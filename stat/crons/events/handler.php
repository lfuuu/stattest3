<?php

use app\classes\ActaulizerCallChatUsage;
use app\classes\ActaulizerVoipNumbers;
use app\classes\adapters\ClientChangedAmqAdapter;
use app\classes\adapters\Tele2Adapter;
use app\classes\api\ApiChatBot;
use app\classes\api\ApiCore;
use app\classes\api\ApiFeedback;
use app\classes\api\ApiPhone;
use app\classes\api\ApiRobocall;
use app\classes\api\ApiRobocallInternal;
use app\classes\api\ApiSipTrunk;
use app\classes\api\ApiVpbx;
use app\classes\api\ApiVps;
use app\classes\behaviors\InvoiceGeneratePdf;
use app\classes\contragent\importer\lk\CoreLkContragent;
use app\classes\HandlerLogger;
use app\classes\Html;
use app\classes\partners\RewardCalculate;
use app\classes\sender\RocketChat;
use app\classes\voip\StateVoipUpdater;
use app\helpers\DateTimeZoneHelper;
use app\models\Bik;
use app\models\ClientAccount;
use app\models\EventQueue;
use app\models\EventQueueIndicator;
use app\models\important_events\ImportantEventsNames;
use app\models\Invoice;
use app\models\Number;
use app\models\voip\Registry;
use app\modules\async\classes\AsyncAdapter;
use app\modules\atol\behaviors\SendToOnlineCashRegister;
use app\modules\atol\Module as AtolModule;
use app\modules\callTracking\classes\api\ApiCalltracking;
use app\modules\callTracking\models\VoipNumber;
use app\modules\callTracking\Module as CallTrackingModule;
use app\modules\freeNumber\classes\FreeNumberAdapter;
use app\modules\freeNumber\Module as FreeNumberModule;
use app\modules\async\Module as asyncModule;
use app\modules\mtt\classes\MttAdapter;
use app\modules\mtt\Module as MttModule;
use app\modules\nnp\classes\CityLinker;
use app\modules\nnp\classes\OperatorLinker;
use app\modules\nnp\classes\RefreshPrefix;
use app\modules\nnp\classes\RegionLinker;
use app\modules\nnp\models\CountryFile;
use app\modules\nnp\classes\helpers\ImportPreviewHelper;
use app\modules\nnp\models\NumberExample;
use app\modules\nnp\Module as NnpModule;
use app\modules\notifier\Module;
use app\modules\sbisTenzor\helpers\SBISDataProvider;
use app\modules\sim\models\Imsi;
use app\modules\socket\classes\Socket;
use app\modules\uu\behaviors\AccountTariffBiller;
use app\modules\uu\behaviors\AccountTariffCheckHlr;
use app\modules\uu\behaviors\RecalcRealtimeBalance;
use app\modules\uu\behaviors\SyncAccountTariffLight;
use app\modules\uu\classes\SyncVps;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\ResourceModel;
use app\modules\uu\Module as UuModule;
use app\modules\webhook\classes\ApiWebCall;
use yii\console\ExitCode;
use yii\db\ActiveQuery;
use app\classes\dto\ChangeClientStructureRegistratorDto;
use app\classes\dto\AccountTariffStructureToKafka;

define('NO_WEB', 1);
define('PATH_TO_ROOT', '../../');
require PATH_TO_ROOT . 'conf_yii.php';

// echo PHP_EOL . 'Start ' . date(DateTimeZoneHelper::DATETIME_FORMAT);

$sleepTime = 2;
$workTime = 300; // перезагрузка каждые 5-8 минут
$maxCountShift = 3;

// настраиваем запрос выборки событий
$nnpEvents = ['event' => [
    NnpModule::EVENT_FILTER_TO_PREFIX,
    NnpModule::EVENT_LINKER,
    NnpModule::EVENT_EXAMPLES,
    NnpModule::EVENT_IMPORT,
    NnpModule::EVENT_IMPORT_PREVIEW,
    EventQueue::INVOICE_MASS_CREATE,
//    EventQueue::INVOICE_GENERATE_PDF,
    EventQueue::INVOICE_ALL_PDF_CREATED,
    EventQueue::ADD_RESOURCE_ON_ACCOUNT_TARIFFS,
    EventQueue::UPDATE_BALANCE_MASS,
    EventQueue::KSIM_GET_STATISTIC,
]];

$syncEvents = ['event' => [
    EventQueue::ATS3__SYNC,
    EventQueue::MAKE_CALL,
    EventQueue::SYNC_1C_CLIENT,
    UuModule::EVENT_SIPTRUNK_SYNC,
    UuModule::EVENT_ROBOCALL_INTERNAL_CREATE,
    UuModule::EVENT_ROBOCALL_INTERNAL_REMOVE,
    EventQueue::DADATA_BIK,
    EventQueue::SYNC_TELE2_SET_GET_STATUS,
]];

$syncT2Events = ['event' => [
    EventQueue::SYNC_TELE2_GET_IMSI,
    EventQueue::SYNC_TELE2_LINK_IMSI,
    EventQueue::SYNC_TELE2_UNSET_IMSI,
    EventQueue::SYNC_TELE2_UNLINK_IMSI,
    EventQueue::SYNC_TELE2_GET_STATUS,
    EventQueue::SYNC_TELE2_SET_CFNRC,
    EventQueue::SYNC_TELE2_UNSET_CFNRC,
]];

$uuSyncEvents = ['event' => [
    UuModule::EVENT_ADD_LIGHT,
    UuModule::EVENT_CLOSE_LIGHT,
]];

$kafkaEvents = ['event' => [
    UuModule::EVENT_UU_ANONCE,
    UuModule::EVENT_UU_ANONCE_TARIFF,
    EventQueue::EVENT_LK_CONTRAGENT_CHANGED,
]];

$kafkaLowEvents = ['event' => [
    UuModule::EVENT_UU_ANONCE2,
    EventQueue::INVOICE_GENERATE_PDF,
]];


//$syncEvents['event'] = array_merge($syncEvents['event'], $uuSyncEvents['event']/*, $kafkaEvents*/);

$map = [
    'with_account_tariff' => [['NOT', ['account_tariff_id' => null]], ['NOT', $uuSyncEvents], ['NOT', $kafkaEvents], ['NOT', $kafkaLowEvents]], // account_tariff_id => not null =>> already ['NOT', $syncEvents] && ['NOT', $nnpEvents]
    'without_account_tariff' => [['account_tariff_id' => null], ['NOT', $nnpEvents], ['NOT', $syncEvents], ['NOT', $syncT2Events], ['NOT', $uuSyncEvents], ['NOT', $kafkaEvents], ['NOT', $kafkaLowEvents]],

    'kafka' => [$kafkaEvents], // kafka events
    'kafka_low' => [$kafkaLowEvents], // low priority kafka events (mass upload, mass generate)
    'uu_sync' => [$uuSyncEvents],
    'ats3_sync' => [$syncEvents], // all sync events
    'sync_t2' => [$syncT2Events], // all sync events
    'nnp' => [$nnpEvents],

    'no_nnp' => [['NOT', $nnpEvents]], //для служебного пользования
];

$memoryLimitMap = [
    'nnp' => '16G',
];

// @TODO ordering? UuModule::EVENT_ADD_LIGHT in first

$consoleParam = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : null;

if (!$consoleParam || !isset($map[$consoleParam])) {
    echo PHP_EOL . 'Не задан параметр очереди: [' . implode(', ', array_keys($map)) . ']';
    echo PHP_EOL;
    return ExitCode::UNSPECIFIED_ERROR;
}

echo PHP_EOL . sprintf("%s, handler started: %s", date(DateTimeZoneHelper::DATETIME_FORMAT), $consoleParam);
if (!empty($memoryLimitMap[$consoleParam])) {
    $memoryLimit = $memoryLimitMap[$consoleParam];

    ini_set('memory_limit', $memoryLimit);
    echo '. Memory: ' . sprintf(
            '%4.2f MB (%4.2f MB in peak)',
            memory_get_usage(true) / 1048576,
            memory_get_peak_usage(true) / 1048576
        );
}

const MAX_PARALLEL_WORKERS = 5;


$parallelWorkers = $_SERVER['argv'][2] ?? false;
$parallelWorkerIdx = $_SERVER['argv'][3] ?? false;

if ($parallelWorkers !== false && $parallelWorkerIdx !== false) {
    $parallelWorkers = (int)$parallelWorkers;
    $parallelWorkerIdx = (int)$parallelWorkerIdx;

    if (!($parallelWorkers && $parallelWorkerIdx && $parallelWorkers <= MAX_PARALLEL_WORKERS && $parallelWorkerIdx <= $parallelWorkers)) {
        throw new IncorrectRequestParametersException();
    }

} else {
    $parallelWorkers = $parallelWorkerIdx = false;
}

// Контроль времени работы. выключаем с 55 до 00 секунд.
$time = (new DateTimeImmutable());
$stopTimeFrom = $time->modify('+' . $workTime . ' second');
$stopTimeFrom = $stopTimeFrom->modify('-' . ((int)$stopTimeFrom->format('s') + 5) . 'second');
$stopTimeTo = $stopTimeFrom->modify('+5 second');

$countShift = 0;
do {
    $activeQuery = EventQueue::getPlannedQuery();
    foreach ($map[$consoleParam] as $where) {
        $activeQuery->andWhere($where);
    }

    $activeQuery->limit(1000);

    if ($consoleParam == 'uu_sync') {
        $activeQuery->limit(10000);
    }

    if ($parallelWorkers) {
        $activeQuery->andWhere('id % ' . $parallelWorkers . ' = ' . ($parallelWorkerIdx - 1));
    }

    doEvents($activeQuery, $uuSyncEvents);
    sleep($sleepTime);

    $time = (new DateTimeImmutable());

    $isExit = $stopTimeFrom < $time && $time < $stopTimeTo;

    if (!$isExit && $time > $stopTimeTo) {
        if ($countShift++ >= $maxCountShift) {
            echo 'exit' . $countShift . ' ';
            $isExit = true;
        } else {
            echo ' SHIFT ';
            $stopTimeFrom = $time->modify('+1 minute');
            $stopTimeFrom = $stopTimeFrom->modify('-' . ((int)$stopTimeFrom->format('s') + 5) . 'second');
            $stopTimeTo = $stopTimeFrom->modify('+5 second');
        }
    }

} while (!$isExit);

echo PHP_EOL . 'Stop ' . date(DateTimeZoneHelper::DATETIME_FORMAT) . ' ' . $consoleParam;

/**
 * Выполнить запланированное
 *
 * @param ActiveQuery $eventQueueQuery
 * @param array $uuSyncEvents
 */
function doEvents($eventQueueQuery, $uuSyncEvents)
{
    static $flags = [];

    if (!$flags) {
        $flags['isCoreServer'] = (isset(Yii::$app->params['CORE_SERVER']) && Yii::$app->params['CORE_SERVER']);
        $flags['isVpbxServer'] = ApiVpbx::me()->isAvailable();
        $flags['isBaseServer'] = \app\classes\api\ApiBase::me()->isAvailable();
        $flags['isVmServer'] = ApiVps::me()->isAvailable();
        $flags['isFeedbackServer'] = ApiFeedback::isAvailable();
        $flags['isChatBotServer'] = ApiChatBot::isAvailable();
        $flags['isAccountTariffLightServer'] = SyncAccountTariffLight::isAvailable();
        $flags['isNnpServer'] = NnpModule::isAvailable();
        $flags['isCallTrackingServer'] = CallTrackingModule::isAvailable();
        $flags['isAtolServer'] = \app\modules\atol\classes\Api::me()->isAvailable();
        $flags['isMttServer'] = MttAdapter::me()->isAvailable();
        $flags['isTele2Server'] = Tele2Adapter::me()->isAvailable();
        $flags['isFreeNumberServer'] = FreeNumberAdapter::me()->isAvailable();
        $flags['isAsyncServer'] = AsyncAdapter::me()->isAvailable();
        $flags['isSipTrunkServer'] = ApiSipTrunk::me()->isAvailable();
//        $flags['isClientChangedServer'] = ClientChangedAmqAdapter::me()->isAvailable();
        $flags['is1CServer'] = defined('SYNC1C_UT_SOAP_URL') && SYNC1C_UT_SOAP_URL;
        $flags['isRobocallServer'] = ApiRobocall::me()->isAvailable();
        $flags['isRobocallInternalServer'] = ApiRobocallInternal::me()->isAvailable();
        $flags['isEbcKafka'] = \app\classes\adapters\EbcKafka::me()->isAvailable();
    }

    $isCoreServer = $flags['isCoreServer'];
    $isVpbxServer = $flags['isVpbxServer'];
    $isBaseServer = $flags['isBaseServer'];
    $isVmServer = $flags['isVmServer'];
    $isFeedbackServer = $flags['isFeedbackServer'];
    $isChatBotServer = $flags['isChatBotServer'];
    $isAccountTariffLightServer = $flags['isAccountTariffLightServer'];
    $isNnpServer = $flags['isNnpServer'];
    $isAtolServer = $flags['isAtolServer'];
    $isMttServer = $flags['isMttServer'];
    $isTele2Server = $flags['isTele2Server'];
    $isFreeNumberServer = $flags['isFreeNumberServer'];
    $isAsyncServer = $flags['isAsyncServer'];
    $isSipTrunkServer = $flags['isSipTrunkServer'];
//    $isClientChangedServer = $flags['isClientChangedServer'];
    $is1CServer = $flags['is1CServer'];
    $isRobocallServer = $flags['isRobocallServer'];
    $isRobocallInternalServer = $flags['isRobocallInternalServer'];
    $isEbcKafka = $flags['isEbcKafka'];
    echo '. ';


    /** @var EventQueue $event */
    foreach ($eventQueueQuery->each() as $event) {
        HandlerLogger::me()->clear();

        $info = '';

        try {
            echo PHP_EOL . $event->event . ', ' . $event->param;

            // для того, чтобы при фатале на конкретном событии они при следующем запуске не мешало другим событиям
            $event->setError();
            $event->iteration--;

            $param = $event->param;

            if (strpos($param, 'a:') === 0) {
                $param = unserialize($param);
            } else {
                if (strpos($param, '|') !== false) {
                    $param = explode('|', $param);
                } else {
                    if (strpos($param, '{"') === 0) {
                        $param = json_decode($param, true);
                    }
                }
            }

            Yii::info(
                'Handle event: ' . $event->event . ' ' .
                json_encode($param, (JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT))
            );

            if ($event->account_tariff_id && !in_array($event->event, $uuSyncEvents['event'])) {
                // все запросы по одной услуге надо выполнять строго последовательно
                if ($event->hasPrevEvent()) {
                    throw new LogicException('Еще не выполнен предыдущий запрос по этой услуге');
                }
            }

            switch ($event->event) {
                case EventQueue::EVENT_BUS_CMD:
                    $info = \app\classes\adapters\EventBus::me()->applyCmd($param);
                    break;
                case EventQueue::EVENT_BUS_CMD_RESULT:
                    $info = \app\classes\adapters\EventBus::me()->sendCmdResult($param);
                    break;

                case EventQueue::EVENT_LK_CONTRAGENT_CHANGED:
                    CoreLkContragent::syncAndUpdate($param['contragent_id'] ?? 0);
                    break;

                case EventQueue::EVENT_LK_CONTRACT_CHANGED:
                    \app\classes\contragent\importer\lk\CoreLkContract::me()->applyChanges($param);
                    break;

                case EventQueue::USAGE_VOIP__INSERT:
                case EventQueue::USAGE_VOIP__UPDATE:
                case EventQueue::USAGE_VOIP__DELETE:
                    // ats2Numbers::check();
                    break;

                case EventQueue::STATE_VOIP_UPDATE:
                    HandlerLogger::me()->echoToLog(function () use ($param) {
                        return StateVoipUpdater::me()->update($param['account_tariff_id']);
                    });

                    break;

                case EventQueue::ADD_PAYMENT:
                    EventHandler::updateBalance($param[1]);
                    \app\models\Payment::onAddEvent($param[0]);
                    // (new AddPaymentNotificationProcessor($param[1], $param[0]))->makeSingleClientNotification();
                    break;

                case EventQueue::UPDATE_BALANCE:
                    EventHandler::updateBalance($param);
                    break;

                case EventQueue::UPDATE_BALANCE_MASS:
                    ClientAccount::dao()->billBalanceMass();
                    break;

                case EventQueue::MIDNIGHT:
                    // проверка необходимости включать или выключать услуги
                    EventQueue::go(EventQueue::CHECK__USAGES);

                    /*
                        // каждый 2-ой рабочий день, помечаем, что все счета показываем в LK
                        if (WorkDays::isWorkDayFromMonthStart(time(), 2)) {
                            Event::go(Event::MIDNIGHT__LK_BILLS4ALL);
                        }
                    */

                    // за 4 дня предупреждаем о списании абонентки аваносовым клиентам
                    if (WorkDays::isWorkDayFromMonthEnd(time(), 4)) {
                        EventQueue::go(EventQueue::MIDNIGHT__MONTHLY_FEE_MSG);
                    }

                    // очистка предоплаченных счетов
                    EventQueue::go(EventQueue::MIDNIGHT__CLEAN_PRE_PAYED_BILLS);

                    // очистка очереди событий
                    EventQueue::go(EventQueue::MIDNIGHT__CLEAN_EVENT_QUEUE);

                    // очистка id комманд из кафки
                    EventQueue::go(EventQueue::MIDNIGHT__CLEAN_EVENT_CMD_ID);

                    break;

                case EventQueue::CHECK__USAGES:
                    // проверка необходимости включить или выключить услугу UsageVoip
                    EventQueue::go(EventQueue::CHECK__VOIP_OLD_NUMBERS);

                    // проверка необходимости включить или выключить услугу в новой схеме
                    EventQueue::go(EventQueue::CHECK__VOIP_NUMBERS);

                    // проверка необходимости включить или выключить услугу UsageVirtPbx
                    EventQueue::go(EventQueue::CHECK__VIRTPBX3);

                    // проверка необходимости включить или выключить улугу UsageCallChat
                    EventQueue::go(EventQueue::CHECK__CALL_CHAT);

                    break;

                // проверка необходимости включить или выключить услугу UsageVoip
                case EventQueue::CHECK__VOIP_OLD_NUMBERS:
//                    voipNumbers::check();
                    break;

                // проверка необходимости включить или выключить услугу UsageVirtPbx
                case EventQueue::CHECK__VIRTPBX3:
                    $usageId = isset($param[0]) ? $param[0] : (isset($param['usage_id']) ? $param['usage_id'] : 0);
                    VirtPbx3::check($usageId);
                    break;

                // проверка необходимости включить или выключить услугу UsageCallChat
                // @todo перенести в новый демон
                case EventQueue::CHECK__CALL_CHAT:
                    ActaulizerCallChatUsage::me()->actualizeUsages();
                    break;

                // каждый 2-ой рабочий день, помечаем, что все счета показываем в LK
                case EventQueue::MIDNIGHT__LK_BILLS4ALL:
                    NewBill::setLkShowForAll();
                    break;

                // за 4 дня предупреждаем о списании абонентки аваносовым клиентам
                case EventQueue::MIDNIGHT__MONTHLY_FEE_MSG:
                    // $execStr = "cd ".PATH_TO_ROOT."crons/stat/; php -c /etc/ before_billing.php >> /var/log/nispd/cron_before_billing.php";
                    // echo " exec: ".$execStr;
                    // exec($execStr);
                    break;

                // очистка предоплаченных счетов
                case EventQueue::MIDNIGHT__CLEAN_PRE_PAYED_BILLS:
                    Bill::cleanOldPrePayedBills();
                    break;

                // очистка очереди событий
                case EventQueue::MIDNIGHT__CLEAN_EVENT_QUEUE:
                    EventQueue::clean();
                    break;

                // очистка очереди событий
                case EventQueue::MIDNIGHT__CLEAN_EVENT_CMD_ID:
                    \app\models\EventCmdId::clean();
                    break;

                case EventQueue::LK_SETTINGS_TO_MAILER:
                    /** @var Module $notifier */
                    $notifier = Yii::$app->getModule('notifier');
                    $notifier->actions->applySchemePersonalSubscribe($param);
                    break;

                case EventQueue::CHECK_CREATE_CORE_OWNER:
                    if ($isCoreServer) {
                        ApiCore::checkCreateCoreAdmin($param);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case EventQueue::SYNC_CLIENT_CHANGED:
                    if ($isBaseServer) {
                        \app\classes\api\ApiBase::me()->syncStatClientStructure($param);
                    } else {
//                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case EventQueue::CORE_CREATE_OWNER:
                    $info = $isCoreServer ?
                        ApiCore::syncCoreOwner($param) :
                        EventQueue::API_IS_SWITCHED_OFF;
                    break;

                case EventQueue::USAGE_VIRTPBX__INSERT:
                case EventQueue::USAGE_VIRTPBX__UPDATE:
                case EventQueue::USAGE_VIRTPBX__DELETE:
                    $usageId = isset($param[0]) ? $param[0] : (isset($param['usage_id']) ? $param['usage_id'] : 0);
                    if ($isCoreServer) {
                        VirtPbx3::check($usageId);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case EventQueue::SYNC__VIRTPBX3:
                    $usageId = isset($param[0]) ? $param[0] : (isset($param['usage_id']) ? $param['usage_id'] : 0);
                    if ($isCoreServer) {
                        VirtPbx3::sync($usageId);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case EventQueue::ACTUALIZE_NUMBER:
                    Number::dao()->actualizeStatusByE164($param['number']);
                    if ($isCoreServer) {
                        ActaulizerVoipNumbers::me()->actualizeByNumber($param['number']);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case EventQueue::ACTUALIZE_CLIENT:
                    if ($isCoreServer) {
                        ActaulizerVoipNumbers::me()->actualizeByClientId($param['client_id']);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case EventQueue::CHECK__VOIP_NUMBERS:
                    if ($isCoreServer) {
                        ActaulizerVoipNumbers::me()->actualizeAll();
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case EventQueue::ATS3__SYNC:
                    if ($isCoreServer) {
                        ActaulizerVoipNumbers::me()->sync($param['number']);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case EventQueue::CALL_CHAT__ADD:
                case EventQueue::CALL_CHAT__UPDATE:
                case EventQueue::CALL_CHAT__DEL:
                    // события услуги звонок_чат
                    if ($isFeedbackServer) {
                        ActaulizerCallChatUsage::me()->actualizeUsage($param['usage_id']);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case EventQueue::ACCOUNT_BLOCKED:
                    if ($isVpbxServer) {
                        EventQueue::goWithIndicator(
                            EventQueue::VPBX_BLOCKED,
                            $param['account_id'],
                            ClientAccount::tableName(),
                            $param['account_id'],
                            EventQueueIndicator::SECTION_ACCOUNT_BLOCK
                        );
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }

                    // Синхронизировать в VPS manager
                    if ($isVmServer) {
                        (new SyncVps)->disableAccount($param['account_id']);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case EventQueue::ACCOUNT_UNBLOCKED:
                    if ($isVpbxServer) {
                        EventQueue::goWithIndicator(
                            EventQueue::VPBX_UNBLOCKED,
                            $param['account_id'],
                            ClientAccount::tableName(),
                            $param['account_id'],
                            EventQueueIndicator::SECTION_ACCOUNT_BLOCK
                        );
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }

                    // Синхронизировать в VPS manager
                    if ($isVmServer) {
                        (new SyncVps)->enableAccount($param['account_id']);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case EventQueue::VPBX_BLOCKED:
                    // Синхронизировать в Vpbx. Блокировка
                    if ($isVpbxServer) {
                        ApiVpbx::me()->lockAccount($param);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case EventQueue::VPBX_UNBLOCKED:
                    // Синхронизировать в Vpbx. Разблокировка
                    if ($isVpbxServer) {
                        ApiVpbx::me()->unlockAccount($param);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case EventQueue::PARTNER_REWARD:
                    RewardCalculate::run($param['client_id'], $param['bill_id'], $param['created_at']);
                    break;

                case EventQueue::TROUBLE_NOTIFIER_EVENT:
                    RocketChat::me()->sendTroubleNotifier($param);
                    break;

                case EventQueue::NOTIFIER_TO_MATRIX:
                    if (\app\classes\api\ApiMatrixElementChat::isAvailable()) {
                        \app\classes\sender\MatrixElementChat::me()->sendTroubleNotifier($param['trouble_id'], $param['user'], $param['text']);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case EventQueue::MAKE_CALL:
                    ApiWebCall::me()->makeCall($param['abon'], $param['calling_number']);
                    break;

                case EventQueue::INVOICE_GENERATE_PDF:
                    InvoiceGeneratePdf::generate($param['id'], $param['document']);
                    Invoice::checkAllPdfFiles($param['id']);
                    break;

                case EventQueue::INVOICE_ALL_PDF_CREATED:
//                    throw new \Exception('stopped');
                    SBISDataProvider::checkInvoiceForExchange($param['id']);
                    break;

                case EventQueue::INVOICE_MASS_CREATE:
                    Invoice::dao()->massGenerate($event);
                    break;

                case EventQueue::ADD_RESOURCE_ON_ACCOUNT_TARIFFS:
                    ResourceModel::addResourceOnAccountTariffs($param['service_type_id'], $param['resource_id']);
                    break;

                case EventQueue::SYNC_1C_CLIENT:
                    if ($is1CServer) {
                        if (($Client = Sync1C::getClient()) !== false) {
                            $Client->saveClientCards($param['client_id']);
                        } else {
                            throw new Exception('Ошибка синхронизации с 1С.');
                        }
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case EventQueue::SET_GOOD_BILL_DATE:
                    $bill = \app\models\Bill::findOne(['bill_no' => $param['bill_no']]);

                    if ($bill) {
                        $bill->generateInvoices();
                    }

                    break;

                case EventQueue::SYNC_TELE2_GET_IMSI:
                    $info = $isTele2Server ? AccountTariffCheckHlr::reservImsi($param) : EventQueue::API_IS_SWITCHED_OFF;
                    break;

                case EventQueue::SYNC_TELE2_LINK_IMSI:
                    $info = $isTele2Server ? AccountTariffCheckHlr::linkImsi($event->id, $param) : EventQueue::API_IS_SWITCHED_OFF;
                    break;

                case EventQueue::SYNC_TELE2_UNSET_IMSI:
                    $info = $isTele2Server ? AccountTariffCheckHlr::unsetImsi($param) : EventQueue::API_IS_SWITCHED_OFF;
                    break;

                case EventQueue::SYNC_TELE2_UNLINK_IMSI:
                    $info = $isTele2Server ? AccountTariffCheckHlr::unlinkImsi($event->id, $param) : EventQueue::API_IS_SWITCHED_OFF;
                    break;

                case EventQueue::SYNC_TELE2_GET_STATUS:
                    $info = $isTele2Server ? AccountTariffCheckHlr::getSubscriberStatus($event->id, $param) : EventQueue::API_IS_SWITCHED_OFF;
                    break;

                case EventQueue::SYNC_TELE2_SET_GET_STATUS:
                    $info = $isTele2Server ? Imsi::dao()->getSubscriberStatus($param['imsi']) : EventQueue::API_IS_SWITCHED_OFF;
                    break;

                case EventQueue::SYNC_TELE2_SET_CFNRC:
                    $info = $isTele2Server ? AccountTariffCheckHlr::setRedirect($event->id, $param, Tele2Adapter::REDIRECT_CFNRC) : EventQueue::API_IS_SWITCHED_OFF;
                    break;

                case EventQueue::SYNC_TELE2_UNSET_CFNRC:
                    $info = $isTele2Server ? AccountTariffCheckHlr::removeRedirect($event->id, $param, Tele2Adapter::REDIRECT_CFNRC) : EventQueue::API_IS_SWITCHED_OFF;
                    break;

                case \app\modules\sim\Module::EVENT_ESIM_CHECK:
                    $info = \app\modules\sim\classes\EsimChecker::me()->check($param['account_tariff_id']);
                    break;

                case \app\modules\sim\Module::EVENT_ESIM_ATTACH:
                    $info = \app\modules\sim\classes\EsimChecker::me()->attach($param['account_tariff_id'], $param['esim_tag_names']);
                    break;

                case EventQueue::CREATE_CONTRACT:
                    \app\classes\behaviors\important_events\ClientContract::eventAddContract($param);
                    $registr = ChangeClientStructureRegistratorDto::me();
                    $registr->registrChange(ChangeClientStructureRegistratorDto::CONTRACT, $param['contract_id']);
                    $registr->registrChange(ChangeClientStructureRegistratorDto::CONTRAGENT, $param['contragent_id']);
                    $registr->registrChange(ChangeClientStructureRegistratorDto::SUPER, $param['super_client_id']);
                    break;

                case EventQueue::ADD_ACCOUNT:
                    ChangeClientStructureRegistratorDto::me()->registrChange(ChangeClientStructureRegistratorDto::ACCOUNT, $param);
                    break;

                case EventQueue::CONTRACT_CHANGE_CONTRAGENT:
                    $registr = ChangeClientStructureRegistratorDto::me();
                    $registr->registrChange(ChangeClientStructureRegistratorDto::CONTRACT, $param['contract_id']);
                    $registr->registrChange(ChangeClientStructureRegistratorDto::CONTRAGENT, $param['new_contragent_id']);
                    $registr->registrChange(ChangeClientStructureRegistratorDto::CONTRAGENT, $param['old_contragent_id']);
                    break;

                case EventQueue::ADD_SUPER_CLIENT:
                    ChangeClientStructureRegistratorDto::me()->registrChange(ChangeClientStructureRegistratorDto::SUPER, $param);
                    break;

                case ChangeClientStructureRegistratorDto::EVENT:
                    $info = $isEbcKafka ? ChangeClientStructureRegistratorDto::me()->anonce($param) : EventQueue::API_IS_SWITCHED_OFF;
                    break;



                // --------------------------------------------
                // Псевдо-логирование
                // @todo выпилить
                // --------------------------------------------
                case EventQueue::NEWBILLS__INSERT:
                case EventQueue::NEWBILLS__UPDATE:
                case EventQueue::NEWBILLS__DELETE:
                case EventQueue::DOC_DATE_CHANGED:
                case EventQueue::YANDEX_PAYMENT:
                case EventQueue::ATS3__BLOCKED:
                case EventQueue::SYNC_CORE_ADMIN:
                case EventQueue::ATS2_NUMBERS_CHECK:
                case EventQueue::ATS3__DISABLED_NUMBER:
                case EventQueue::ATS3__UNBLOCKED:
                case EventQueue::CLIENT_SET_STATUS:
                case EventQueue::CYBERPLAT_PAYMENT:
                case EventQueue::PRODUCT_PHONE_ADD:
                case EventQueue::PRODUCT_PHONE_REMOVE:
                case EventQueue::UPDATE_PRODUCTS:
                    break;


                // --------------------------------------------
                // Универсальные услуги
                // --------------------------------------------
                case UuModule::EVENT_ADD_LIGHT:
                    // УУ. Добавить данные в AccountTariffLight
                    if ($isAccountTariffLightServer) {
                        SyncAccountTariffLight::addToAccountTariffLight($param);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case UuModule::EVENT_CLOSE_LIGHT:
                    // УУ. Закрыть пакет в AccountTariffLight
                    if ($isAccountTariffLightServer) {
                        SyncAccountTariffLight::closeAccountTariffLight($param);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case UuModule::EVENT_DELETE_LIGHT:
                    // УУ. Удалить данные из AccountTariffLight. Теоретически этого быть не должно, но...
                    if ($isAccountTariffLightServer) {
                        SyncAccountTariffLight::deleteFromAccountTariffLight($param);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;
                case UuModule::EVENT_RECALC_ACCOUNT:
                    // УУ. Билинговать клиента
                    $info = AccountTariffBiller::recalc($param);
                    break;

                case UuModule::EVENT_RECALC_BALANCE:
                    // УУ. Пересчитать realtime баланс
                    RecalcRealtimeBalance::recalc($param['client_account_id']);
                    break;

                case UuModule::EVENT_VPS_SYNC:
                    // УУ. Услуга VPS
                    if ($isVmServer) {
                        (new SyncVps)->syncVm($param['account_tariff_id']);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case UuModule::EVENT_RESOURCE_VPS:
                    // УУ. Отправить измененные ресурсы VPS
                    if ($isVmServer) {
                        (new SyncVps)->syncResource($param['client_account_id'], $param['account_tariff_id'], $param['account_tariff_resource_ids']);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case UuModule::EVENT_VPS_LICENSE:
                    // УУ. Доп. услуга VPS
                    (new SyncVps)->syncLicense($param['account_tariff_id']);
                    break;

                case UuModule::EVENT_VPBX:
                    // УУ. Услуга ВАТС
                    if ($isCoreServer) {
                        VirtPbx3::check($param['account_tariff_id']);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case UuModule::EVENT_VOIP_CALLS:
                    // УУ. Услуга телефонии
                    Number::dao()->actualizeStatusByE164($param['number']);

                    if ($isCoreServer) {
                        if (AccountTariff::hasTrunk($param['client_account_id'])) {
                            $info = 'Мегатранк';
                        } else {
                            ActaulizerVoipNumbers::me()->actualizeByNumber($param['number']); // @todo выпилить этот костыль и использовать напрямую ApiPhone::me()->addDid/editDid
                        }
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }

                    // УУ. Добавление/выключение дефолтных пакетов телефонии
//                    AccountTariff::actualizeDefaultPackages($param);
                    break;

                case UuModule::EVENT_VOIP_BUNDLE:
                    // УУ. Добавление / изменение бандл-пакетов
                    AccountTariff::actualizeBundlePackages($param);
                    break;

                case UuModule::EVENT_ADD_DEFAULT_PACKAGES:
                    // УУ. Добавление/выключение дефолтных пакетов телефонии
                    AccountTariff::actualizeDefaultPackages($param);
                    break;

                case UuModule::EVENT_CLOSE_ALL_PACKAGE:
                    // УУ. Отключить все пакеты
                    AccountTariff::closeAllPackages($param);
                    break;

                case UuModule::EVENT_CALL_CHAT_CREATE:
                    // УУ. Услугу call chat создать
                    if ($isFeedbackServer) {
                        ApiFeedback::createChat($param['client_account_id'], $param['account_tariff_id']);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case UuModule::EVENT_CALL_CHAT_REMOVE:
                    // УУ. Услугу call chat удалить
                    if ($isFeedbackServer) {
                        ApiFeedback::removeChat($param['client_account_id'], $param['account_tariff_id']);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;


//                case UuModule::EVENT_CHAT_BOT_CREATE:
//                    if ($isChatBotServer) {
//                        ApiChatBot::createChatBot(
//                            $param['client_account_id'],
//                            $param['account_tariff_id'],
//                            $param['tariff_id']
//                        );
//                    } else {
//                        $info = EventQueue::API_IS_SWITCHED_OFF;
//                    }
//                    break;
//
//                case UuModule::EVENT_CHAT_BOT_REMOVE:
//                    if ($isChatBotServer) {
//                        ApiChatBot::removeChatBot($param['account_tariff_id']);
//                    } else {
//                        $info = EventQueue::API_IS_SWITCHED_OFF;
//                    }
//                    break;

                case UuModule::EVENT_RESOURCE_VOIP:
                    // УУ. Отправить измененные ресурсы телефонии на платформу
                    if (AccountTariff::hasTrunk($param['client_account_id'])) {
                        HandlerLogger::me()->add('Мегатранк');
                    } elseif ($isCoreServer) {
                        ApiPhone::me()->editDid(
                            $param['client_account_id'],
                            $param['number'],
                            $param['lines'],
                            $param['is_fmc_active'],
                            $param['is_fmc_editable'],
                            $param['is_mobile_outbound_active'],
                            $param['is_mobile_outbound_editable'],
                            null,
                            null,
                            $param['is_robocall_enabled'],
                            $param['is_smart'],
                            $param['is_geo_substitute'] ?? null,
                            $param['is_for_siptrunk_or_vpbx_only'] ?? null,
                            $param['is_call_record'] ?? null,
                        );
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case UuModule::EVENT_RESOURCE_VPBX:
                    // УУ. Отправить измененные ресурсы ВАТС на платформу
                    if ($isCoreServer) {
                        ApiVpbx::me()->update(
                            $param['client_account_id'],
                            $param['account_tariff_id'],
                            $regionId = null,
                            ClientAccount::VERSION_BILLER_UNIVERSAL
                        );
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case UuModule::EVENT_UU_SWITCHED_ON:
                case UuModule::EVENT_UU_SWITCHED_OFF:
                case UuModule::EVENT_UU_UPDATE:
                    // УУ-услуга включена
                    ClientAccount::dao()->updateIsActive($param['client_account_id']);
                    break;

                case UuModule::EVENT_UU_ANONCE:
                case UuModule::EVENT_UU_ANONCE2:
                    if ($isEbcKafka) {
                        $info = AccountTariffStructureToKafka::me()->anonce($param['account_tariff_id']);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case UuModule::EVENT_UU_ANONCE_TARIFF:
                    if ($isEbcKafka) {
                        $info = AccountTariffStructureToKafka::me()->anonceTariff($param);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case UuModule::EVENT_SIPTRUNK_SYNC:
                    if ($isSipTrunkServer) {
                        ApiSipTrunk::me()->sync($param);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                // dadata
                case EventQueue::DADATA_BIK:
                    Bik::updateDadata($param);
                    break;
                // --------------------------------------------
                // АТОЛ
                // --------------------------------------------
                case AtolModule::EVENT_SEND:
                    // АТОЛ. В соответствии с ФЗ−54 отправить данные в онлайн-кассу. А она сама отправит чек покупателю и в налоговую
                    $info = $isAtolServer ?
                        SendToOnlineCashRegister::send(
                            $param['paymentId'],
                            $param['isForcePush'] ?? false
                        ) :
                        EventQueue::API_IS_SWITCHED_OFF;
                    break;

                case AtolModule::EVENT_REFRESH:
                    // АТОЛ. Обновить статус из онлайн-кассы
                    $info = $isAtolServer ?
                        SendToOnlineCashRegister::refreshStatus($param['paymentId'], true) :
                        EventQueue::API_IS_SWITCHED_OFF;
                    break;

                // --------------------------------------------
                // ННП
                // --------------------------------------------
                case NnpModule::EVENT_IMPORT:
                    // ННП. Импорт страны
                    if ($isNnpServer) {
                        list($info, $countryCode) = CountryFile::importById($param['fileId'], $param['old'], $param['new']);

                        if ($param['old']) {
                            // поставить в очередь для пересчета операторов, регионов и городов
                            EventQueue::go(NnpModule::EVENT_LINKER, ['country_code' => $countryCode]);
                        }
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case NnpModule::EVENT_IMPORT_PREVIEW:
                    if ($isNnpServer) {
                        $countryFile = CountryFile::findOne($param['fileId'] ?? null);
                        if (!$countryFile) {
                            throw new Exception('Файл не найден для предпросмотра');
                        }

                        ImportPreviewHelper::runQueuedCheck($countryFile, $event);
                        $info = '';
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case NnpModule::EVENT_LINKER:
                    // ННП. Линковка исходных к ID
                    $info .= $isNnpServer ?
                        'Операторы: ' . OperatorLinker::me()->run($param['country_code'] ?? null) . PHP_EOL .
                        'Регионы: ' . RegionLinker::me()->run($param['country_code'] ?? null) . PHP_EOL .
                        'Города: ' . CityLinker::me()->run($param['country_code'] ?? null) . PHP_EOL .
                        ($param['country_code'] ? 'Подтверждение: ' . \app\modules\nnp\classes\NumberRangeSetValid::me()->set($param['country_code']) : ''):
                        EventQueue::API_IS_SWITCHED_OFF;

                    EventQueue::go(NnpModule::EVENT_EXAMPLES);
                    break;

                case NnpModule::EVENT_EXAMPLES:
                    // ННП. Перерасчёт примеров номеров
                    NumberExample::renewAll();
                    break;

                case NnpModule::EVENT_FILTER_TO_PREFIX:
                    // ННП. Конвертировать фильтры в префиксы
                    $info .= $isNnpServer ?
                        implode(PHP_EOL, RefreshPrefix::me()->filterToPrefix()) . PHP_EOL :
                        EventQueue::API_IS_SWITCHED_OFF;
                    break;

                // --------------------------------------------
                // МТТ
                // --------------------------------------------
                case MttModule::EVENT_ADD_INTERNET:
                    // МТТ. Добавить интернет
                    $info = $isMttServer ?
                        MttModule::addInternetPackage($param['package_account_tariff_id'], $param['internet_traffic']) :
                        EventQueue::API_IS_SWITCHED_OFF;
                    break;

                case MttModule::EVENT_CLEAR_BALANCE:
                    // МТТ. Сбросить баланс
                    $info = MttModule::clearBalance($param['account_tariff_id']);
                    break;

                case MttModule::EVENT_CLEAR_INTERNET:
                    // МТТ. Сжечь интернет
                    $info = $isMttServer ?
                        MttModule::clearInternet($param['account_tariff_id']) :
                        EventQueue::API_IS_SWITCHED_OFF;
                    break;

                case MttModule::EVENT_CALLBACK_GET_ACCOUNT_BALANCE:
                    // МТТ. Callback обработчик API-запроса getAccountBalance
                    if ($isMttServer) {
                        MttModule::getAccountBalanceCallback($param);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case MttModule::EVENT_CALLBACK_GET_ACCOUNT_DATA:
                    // МТТ. Callback обработчик API-запроса getAccountData
                    if ($isMttServer) {
                        MttModule::getAccountDataCallback($param);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case MttModule::EVENT_CALLBACK_BALANCE_ADJUSTMENT:
                    // МТТ. Callback обработчик API-запроса balanceAdjustment
                    if ($isMttServer) {
                        MttModule::balanceAdjustmentCallback($param);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                // --------------------------------------------
                // Free number
                // --------------------------------------------
                case FreeNumberModule::EVENT_EXPORT_FREE:
                    // Номер стал свободным
                    /*
                    $info = $isFreeNumberServer ?
                        FreeNumberModule::addFree($param) :
                        EventQueue::API_IS_SWITCHED_OFF;
                    */
                    break;

                case FreeNumberModule::EVENT_EXPORT_BUSY:
                    // Номер стал несвободным
                    /*
                    $info = $isFreeNumberServer ?
                        FreeNumberModule::addBusy($param) :
                        EventQueue::API_IS_SWITCHED_OFF;
                    */
                    break;

                // --------------------------------------------
                // async
                // --------------------------------------------
                case asyncModule::EVENT_ASYNC_ADD_ACCOUNT_TARIFF:
                    $info = $isAsyncServer ?
                        asyncModule::addAccountTariff($param, $event->id) :
                        EventQueue::API_IS_SWITCHED_OFF;
                    break;

                case asyncModule::EVENT_ASYNC_PUBLISH_RESULT:
                    $info = $isAsyncServer ?
                        asyncModule::publishResult($param) :
                        EventQueue::API_IS_SWITCHED_OFF;
                    break;


                case EventQueue::COMET_NOTIFIER_EVENT:
                    $completedEventName = EventQueue::$names[$param['completed_event']];
                    $completedEventMessage = Html::a('ссылке', EventQueue::getUrlById($param['completed_id']), [
                        'target' => '_blank'
                    ]);

                    Socket::me()->emit([
                        Socket::PARAM_TITLE => "Задача '{$completedEventName}' успешно выполнена",
                        Socket::PARAM_USER_ID_TO => $param['notified_user_id'],
                        Socket::PARAM_MESSAGE_HTML => 'Просмотреть выполненную задачу можно по ' . $completedEventMessage,
                    ]);
                    break;

                case ClientChangedAmqAdapter::EVENT:
                    /*
                    $info = $isClientChangedServer
                        ? ClientChangedAmqAdapter::me()->process($param)
                        : EventQueue::API_IS_SWITCHED_OFF;
                    */
                    break;

                case ImportantEventsNames::ZERO_BALANCE:
                    $info = $isRobocallServer
                        ? ApiRobocall::me()->addTaskByBlockAccount($param['account_id'], $param['value'])
                        : EventQueue::API_IS_SWITCHED_OFF;
                    break;

                case ApiRobocall::EVENT_ADD_TR_CONTACT:
                    if ($isRobocallServer) {
                        $info = ApiRobocall::me()->addTransactionContact($param['task_id'], $param['account_id'], $param['robocall_id'], $param['phone'], $param['user_variables']);

                        if (is_array($info)) {
                            $info = print_r($info, true);
                        }
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case UuModule::EVENT_ROBOCALL_INTERNAL_CREATE:
                    if ($isRobocallInternalServer) {
                        ApiRobocallInternal::me()->create($param['client_account_id'], $param['account_tariff_id']);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case UuModule::EVENT_ROBOCALL_INTERNAL_UPDATE:
                    if ($isRobocallInternalServer) {
                        ApiRobocallInternal::me()->update($param['client_account_id'], $param['account_tariff_id']);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case UuModule::EVENT_ROBOCALL_INTERNAL_REMOVE:
                    if ($isRobocallInternalServer) {
                        // The disconnect event is taken from kafka
//                        ApiRobocallInternal::me()->remove($param['account_tariff_id']);
                    } else {
                        $info = EventQueue::API_IS_SWITCHED_OFF;
                    }
                    break;

                case EventQueue::PORTED_NUMBER_ADD:
                    Registry::dao()->addPortedNumber($param['account_id'], $param['number']);
                    break;

                case EventQueue::NUMBER_HAS_BEEN_PORTED:
                    $number = $param['number'];
                    $info = Registry::dao()->createAccountTariffForPortedNumber($number);
                    EventQueue::go(EventQueue::ACTUALIZE_NUMBER, ['number' => $number]);
                    break;


                case EventQueue::SORM_REDIRECT_EXPORT:
                    \app\modules\sorm\classes\redirects\RedirectCollectorDao::me()->export($param['number']);
                    EventQueue::go(EventQueue::SORM_REDIRECT_GROUP, ['number' => $param['number']]);
                    break;

                case EventQueue::SORM_REDIRECT_GROUP:
                    \app\modules\sorm\classes\redirects\RedirectCollectorDao::me()->group($param['number']);
                    break;

                case EventQueue::SORM_EXPORT_BY_DID_ID:
                    @ob_start();
                    \app\modules\sorm\classes\redirects\RedirectCollectorDao::me()->makeExportEventByDidId($param['did_id']);
                    if ($log = ob_get_clean()) {
                        echo ' ' . $log;
                        HandlerLogger::me()->add($log);
                    }
                    break;

                case EventQueue::KSIM_GET_STATISTIC:
                    \app\dao\statistics\KsimStatisticDao::me()->makeStatisticByNumber($event, $param['number']);
                    break;

                // --------------------------------------------
                //
                // --------------------------------------------
                default:
                    // неизвестное событие
                    $info = EventQueue::API_IS_SWITCHED_OFF;
                    break;
            }

            $event->setOk($info);

        } catch (Exception $e) {

            $message = $e->getMessage();
            echo PHP_EOL . '--------------' . PHP_EOL;
            echo '[' . $event->event . '] Code: ' . $e->getCode() . ': ' . $message . ' in ' . $e->getFile() . ' +' . $e->getLine();

            // stop events
            if ($event->event == EventQueue::PORTED_NUMBER_ADD) {
                $event->setError($e, true);
            } else if ( // завершение задачи, в зависимости от ошибки
                ($event->event == AtolModule::EVENT_SEND && strpos($message, 'Не указаны контакты клиента') !== false)
                || ($event->event == EventQueue::CORE_CREATE_OWNER && $e->getCode() == 500 && strpos($message, 'user_already_exists') !== false /* Пользователь с таким email существует */)
                || ($event->event == EventQueue::INVOICE_GENERATE_PDF && strpos($message, 'Content is not PDF') !== false)
            ) {
                $event->setOk('[-] ' . $e->getCode() . ': ' . $message);
            } else {
                $event->setError($e);
            }

            /*
                $isContinue = $e instanceof yii\base\InvalidCallException // ошибка вызова внешней системы
                    || $e instanceof InvalidParamException // Syntax error. В ответ пришел не JSON
                    || strpos($message, 'Operation timed out') !== false // Curl error: #28 - Operation timed out after 30000 milliseconds with 0 bytes received
                    || $e->getCode() == 40001; // Deadlock found when trying to get lock
            */
        }
    }
}
