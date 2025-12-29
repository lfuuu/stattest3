<?php

namespace app\models;

use app\classes\api\ApiRobocall;
use app\classes\dto\ChangeClientStructureRegistratorDto;
use app\classes\HandlerLogger;
use app\classes\model\ActiveRecord;
use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\important_events\ImportantEventsNames;
use app\modules\async\Module as AsyncModule;
use app\modules\atol\Module as AtolModule;
use app\modules\freeNumber\Module as FreeNumberModule;
use app\modules\mtt\Module as MttModule;
use app\modules\nnp\Module as NnpModule;
use app\modules\sim\Module as SimModule;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\Module as UuModule;
use Exception;
use Yii;
use yii\db\ActiveQuery;
use yii\helpers\Url;

/**
 * @property int $id
 * @property string $event
 * @property string $param
 * @property string $status
 * @property int $iteration
 * @property string $next_start
 * @property string $log_error
 * @property string $code
 * @property string $insert_time
 * @property string $trace
 * @property integer $account_tariff_id
 *
 * @property-read AccountTariff $accountTariff
 */
class EventQueue extends ActiveRecord
{
    const STATUS_OK = 'ok';
    const STATUS_ERROR = 'error';
    const STATUS_PLAN = 'plan';
    const STATUS_STOP = 'stop';

    public static $statuses = [
        self::STATUS_PLAN => 'Запланировано',
        self::STATUS_OK => 'Выполнено',
        self::STATUS_ERROR => 'Временная ошибка',
        self::STATUS_STOP => 'Постоянная ошибка',
    ];

    const ITERATION_MAX_VALUE = 20;

    const ACTUALIZE_CLIENT = 'actualize_client';
    const ACTUALIZE_NUMBER = 'actualize_number';
    const ADD_SUPER_CLIENT = 'add_super_client';
    const CHECK_CREATE_CORE_OWNER = 'check_create_core_owner';
    const SYNC_CORE_ADMIN = 'sync_core_admin';
    const ADD_ACCOUNT = 'add_account';
    const CREATE_CONTRACT = 'create_contract';
    const CONTRACT_CHANGE_CONTRAGENT = 'contract_change_contragent';
    const ADD_PAYMENT = 'add_payment';
    const ATS2_NUMBERS_CHECK = 'ats2_numbers_check';
    const ATS3__BLOCKED = 'ats3__blocked';
    const ATS3__DISABLED_NUMBER = 'ats3__disabled_number';
    const ATS3__SYNC = 'ats3__sync';
    const ATS3__UNBLOCKED = 'ats3__unblocked';
    const CALL_CHAT__ADD = 'call_chat__add';
    const CALL_CHAT__DEL = 'call_chat__del';
    const CALL_CHAT__UPDATE = 'call_chat__update';
    const CORE_CREATE_OWNER = 'core_create_owner';
    const CHECK__CALL_CHAT = 'check__call_chat';
    const CHECK__USAGES = 'check__usages';
    const CHECK__VIRTPBX3 = 'check__virtpbx3';
    const SYNC__VIRTPBX3 = 'sync__virtpbx3';
    const CHECK__VOIP_NUMBERS = 'check__voip_numbers';
    const CHECK__VOIP_OLD_NUMBERS = 'check__voip_old_numbers';
    const CLIENT_SET_STATUS = 'client_set_status';
    const CLIENT_STRUCTURE_CHANGED = 'client_structure_changed';
    const CYBERPLAT_PAYMENT = 'cyberplat_payment';
    const DOC_DATE_CHANGED = 'doc_date_changed';
    const SET_GOOD_BILL_DATE = 'set_bill_good_date';
    const LK_SETTINGS_TO_MAILER = 'lk_settings_to_mailer';
    const MIDNIGHT = 'midnight';
    const MIDNIGHT__CLEAN_EVENT_QUEUE = 'midnight__clean_event_queue';
    const MIDNIGHT__CLEAN_EVENT_CMD_ID = 'midnight__clean_event_cmd_id';
    const MIDNIGHT__CLEAN_PRE_PAYED_BILLS = 'midnight__clean_pre_payed_bills';
    const MIDNIGHT__LK_BILLS4ALL = 'midnight__lk_bills4all';
    const MIDNIGHT__MONTHLY_FEE_MSG = 'midnight__monthly_fee_msg';
    const NEWBILLS__DELETE = 'newbills__delete';
    const NEWBILLS__INSERT = 'newbills__insert';
    const NEWBILLS__UPDATE = 'newbills__update';
    const PRODUCT_PHONE_ADD = 'product_phone_add';
    const PRODUCT_PHONE_REMOVE = 'product_phone_remove';
    const UPDATE_PRODUCTS = 'update_products';
    const USAGE_VIRTPBX__DELETE = 'usage_virtpbx__delete';
    const USAGE_VIRTPBX__INSERT = 'usage_virtpbx__insert';
    const USAGE_VIRTPBX__UPDATE = 'usage_virtpbx__update';
    const USAGE_VOIP__DELETE = 'usage_voip__delete';
    const USAGE_VOIP__INSERT = 'usage_voip__insert';
    const USAGE_VOIP__UPDATE = 'usage_voip__update';
    const YANDEX_PAYMENT = 'yandex_payment';
    const UPDATE_BALANCE = 'update_balance';
    const UPDATE_BALANCE_MASS = 'update_balance_mass';
    const ACCOUNT_BLOCKED = 'account_blocked';
    const ACCOUNT_UNBLOCKED = 'account_unblocked';
    const PARTNER_REWARD = 'partner_reward';
    const VPBX_BLOCKED = 'vpbx_blocked';
    const VPBX_UNBLOCKED = 'vpbx_unblocked';
    const COMET_NOTIFIER_EVENT = 'comet_notifier_event';
    const TROUBLE_NOTIFIER_EVENT = 'trouble_notifier_event';
    const NOTIFIER_TO_MATRIX = 'notifier_to_matrix';
    const MAKE_CALL = 'make_call';
    const INVOICE_GENERATE_PDF = 'invoice_generate_pdf';
    const INVOICE_ALL_PDF_CREATED = 'invoice_all_pdf_created';
    const INVOICE_MASS_CREATE = 'invoice_mass_create';
    const SYNC_1C_CLIENT = 'sync_1c_client';
    const DADATA_BIK = 'dadata_bik';
    const SYNC_TELE2_GET_IMSI = 'sync_tele2_get_imsi';
    const SYNC_TELE2_LINK_IMSI = 'sync_tele2_link_imsi';
    const SYNC_TELE2_UNSET_IMSI = 'sync_tele2_unset_imsi';
    const SYNC_TELE2_UNLINK_IMSI = 'sync_tele2_unlink_imsi';
    const SYNC_TELE2_SET_GET_STATUS = 'sync_tele2_set_get_status';
    const SYNC_TELE2_GET_STATUS = 'sync_tele2_get_status';
    const SYNC_TELE2_SET_CFNRC = 'sync_tele2_set_cfnrc';
    const SYNC_TELE2_UNSET_CFNRC = 'sync_tele2_unset_cfnrc';

    const SYNC_CLIENT_CHANGED = 'sync_client_changed';
    const PORTED_NUMBER_ADD = 'ported_number_add';
    const NUMBER_HAS_BEEN_PORTED = 'number_has_been_ported';
    const ADD_RESOURCE_ON_ACCOUNT_TARIFFS = 'add_resource_on_account_tariffs';

    const EVENT_BUS_CMD = 'event_bus_cmd';
    const EVENT_BUS_CMD_RESULT = 'event_bus_cmd_result';

    const EVENT_LK_CONTRAGENT_CHANGED = 'lk_contragent_changed';
    const EVENT_LK_CONTRACT_CHANGED = 'lk_contract_changed';

    const SORM_REDIRECT_EXPORT = 'sorm_redirect_export';
    const SORM_EXPORT_BY_DID_ID = 'sorm_export_by_did_id';
    const SORM_REDIRECT_GROUP = 'sorm_redirect_group';

    const STATE_VOIP_UPDATE = 'state_voip_update';

    const KSIM_GET_STATISTIC = 'ksim_get_statistic';

    const API_IS_SWITCHED_OFF = 'API is switched off';

    public static $names = [
        self::ACTUALIZE_CLIENT => 'Актуализировать клиента',
        self::ACTUALIZE_NUMBER => 'Актуализировать номер',
        self::ADD_SUPER_CLIENT => 'Добавлен (супер)клиент',
        self::CHECK_CREATE_CORE_OWNER => 'Проверяеть необходимость создания администратора в ЛК',
        self::SYNC_CORE_ADMIN => 'Создание администратора в ЛК',
        self::ADD_ACCOUNT => 'Добавлен ЛС',
        self::ADD_PAYMENT => 'Платеж добавлен',
        self::YANDEX_PAYMENT => 'Платеж из Яндекс.Деньги',
        self::CYBERPLAT_PAYMENT => 'Платеж из киберплата',
        self::ATS3__BLOCKED => 'Номер заблокирован',
        self::ATS3__DISABLED_NUMBER => 'Номер временно отключен',
        self::ATS3__SYNC => 'Синхронизировать номер',
        self::ATS3__UNBLOCKED => 'Номер разблокирован',
        self::CALL_CHAT__ADD => 'Услуга call chat добавлена',
        self::CALL_CHAT__DEL => 'Услуга call chat удалена',
        self::CALL_CHAT__UPDATE => 'Услуга call chat изменена',
        self::CORE_CREATE_OWNER => 'Создание админа в ЛК',
        self::CHECK__CALL_CHAT => 'Проверить услугу call chat',
        self::CHECK__USAGES => 'Проверить "старые" услуги',
        self::CHECK__VIRTPBX3 => 'Проверить услуги ВАТС',
        self::SYNC__VIRTPBX3 => 'Синхронизация услуги ВАТС',
        self::CHECK__VOIP_NUMBERS => 'Актуализировать все номера',
        self::CHECK__VOIP_OLD_NUMBERS => 'Синхронизировать все "старые" услуги номеров',
        self::CLIENT_SET_STATUS => 'Изменился бизнес процесс ЛС',
        self::DOC_DATE_CHANGED => 'Изменился дата отгрузки счета',
        self::SET_GOOD_BILL_DATE => 'Изменился дата отгрузки товарного счета',
        self::LK_SETTINGS_TO_MAILER => 'Передать настройки в mailer',
        self::MIDNIGHT => 'Полночь',
        self::MIDNIGHT__CLEAN_EVENT_QUEUE => 'Очистка очереди событий',
        self::MIDNIGHT__CLEAN_EVENT_CMD_ID => 'Очистка id команд из кафки',
        self::MIDNIGHT__CLEAN_PRE_PAYED_BILLS => 'Удаление пустых счетов на предоплату из ЛК',
        self::MIDNIGHT__LK_BILLS4ALL => 'Принудительная публикация счетов',
        self::MIDNIGHT__MONTHLY_FEE_MSG => 'Предупреждаем о списании абонентки авансовым клиентам',
        self::NEWBILLS__INSERT => 'Счет добавлен',
        self::NEWBILLS__UPDATE => 'Счет изменен',
        self::NEWBILLS__DELETE => 'Счет удален',
        self::PRODUCT_PHONE_ADD => 'Добавление продукта "телефония"',
        self::PRODUCT_PHONE_REMOVE => 'Удаление продукта "телефония"',
        self::UPDATE_PRODUCTS => 'Обновить продукты',
        self::USAGE_VIRTPBX__INSERT => 'Услуга ВАТС добавлена',
        self::USAGE_VIRTPBX__UPDATE => 'Услуга ВАТС изменена',
        self::USAGE_VIRTPBX__DELETE => 'Услуга ВАТС удалена',
        self::STATE_VOIP_UPDATE => 'Обновить состояние услуги телефонии',
        self::USAGE_VOIP__INSERT => 'Услуга телефонии добавлена',
        self::USAGE_VOIP__UPDATE => 'Услуга телефонии изменена',
        self::USAGE_VOIP__DELETE => 'Услуга телефонии удалена',
        self::UPDATE_BALANCE => 'Обновление баланса',
        self::UPDATE_BALANCE_MASS => 'Массовое обновление баланса',
        self::ACCOUNT_BLOCKED => 'ЛС заблокирован',
        self::ACCOUNT_UNBLOCKED => 'ЛС разблокирован',
        self::PARTNER_REWARD => 'Подсчет вознаграждения партнера',
        self::VPBX_BLOCKED => 'Блокировка ВАТС',
        self::VPBX_UNBLOCKED => 'Разблокировка ВАТС',
        self::COMET_NOTIFIER_EVENT => 'Comet-уведомление',
        self::MAKE_CALL => 'Сделать звонок',
        self::INVOICE_GENERATE_PDF => 'С/ф. Генерация PDF',
        self::INVOICE_ALL_PDF_CREATED => 'С/ф. Все PDF сгенерированы',
        self::DADATA_BIK => 'Dadata. Получение информации. БИК.',
        self::SYNC_1C_CLIENT => 'Синхронизировать клиента в 1С',
        self::SYNC_TELE2_GET_IMSI => 'Теле2. Получить IMSI',
        self::SYNC_TELE2_LINK_IMSI => 'Теле2. Прикрепить номер к IMSI',
        self::SYNC_TELE2_UNSET_IMSI => 'Теле2. очистить IMSI',
        self::SYNC_TELE2_UNLINK_IMSI => 'Теле2. Открепить номер от IMSI',
        self::SYNC_TELE2_SET_GET_STATUS => 'Теле2. Задача на получение статуса IMSI',
        self::SYNC_TELE2_GET_STATUS => 'Теле2. Получение статуса IMSI',
        self::PORTED_NUMBER_ADD => 'Добавить портированный номер',
        self::NUMBER_HAS_BEEN_PORTED => 'Номер портирован к МСН Телеком',
        self::TROUBLE_NOTIFIER_EVENT => 'Оповещение о заявке',
        self::NOTIFIER_TO_MATRIX => 'Оповещение о заявке (element)',
        self::EVENT_BUS_CMD => 'Шина. Команда.',
        self::EVENT_BUS_CMD_RESULT => 'Шина. Команда. Результат.',
        self::EVENT_LK_CONTRAGENT_CHANGED => 'Шина. Изменен контрагент в ЛК',
        self::SYNC_CLIENT_CHANGED => 'Передача измененных клиентов',

        AtolModule::EVENT_SEND => 'АТОЛ. Отправить',
        AtolModule::EVENT_REFRESH => 'АТОЛ. Обновить',

        UuModule::EVENT_ADD_DEFAULT_PACKAGES => 'УУ. Добавить дефолтные пакеты',
        UuModule::EVENT_VOIP_BUNDLE => 'УУ. Добавить бандл-пакеты',
        UuModule::EVENT_VOIP_CALLS => 'УУ. Телефония',
        UuModule::EVENT_VPBX => 'УУ. ВАТС',
        UuModule::EVENT_CALL_CHAT_CREATE => 'УУ. Call chat создать',
        UuModule::EVENT_CALL_CHAT_REMOVE => 'УУ. Call chat удалить',
        UuModule::EVENT_RESOURCE_VOIP => 'УУ. Ресурс телефонии',
        UuModule::EVENT_RESOURCE_VPBX => 'УУ. Ресурс ВАТС',
        UuModule::EVENT_RESOURCE_VPS => 'УУ. Ресурс VPS',
        UuModule::EVENT_RESOURCE_VPS_LICENCE => 'УУ. Ресурс лицензии',
        UuModule::EVENT_RECALC_ACCOUNT => 'УУ. Билинговать услугу',
        UuModule::EVENT_RECALC_BALANCE => 'УУ. Обновление баланса',
        UuModule::EVENT_VPS_SYNC => 'УУ. VPS',
        UuModule::EVENT_VPS_LICENSE => 'УУ. VPS. Доп. услуги',
        UuModule::EVENT_ADD_LIGHT => 'УУ. Добавить лайт-тариф',
        UuModule::EVENT_DELETE_LIGHT => 'УУ. Удалить лайт-тариф',
        UuModule::EVENT_CLOSE_LIGHT => 'УУ. Закрыть лайт-тариф',
        UuModule::EVENT_UU_SWITCHED_ON => 'УУ включена',
        UuModule::EVENT_UU_SWITCHED_OFF => 'УУ выключена',
        UuModule::EVENT_UU_ANONCE => 'Анонсировать (в Кафку) изменение УУ-услуги',
        UuModule::EVENT_UU_ANONCE2 => 'Анонсировать (в Кафку) изменение УУ-услуги (2)',
        UuModule::EVENT_SIPTRUNK_SYNC => 'SIP-транк. Синхронизация',

        NnpModule::EVENT_IMPORT => 'ННП. Импорт страны',
        NnpModule::EVENT_LINKER => 'ННП. Линковка исходных к ID',
        NnpModule::EVENT_FILTER_TO_PREFIX => 'ННП. Фильтр -> префикс',

        MttModule::EVENT_CALLBACK_GET_ACCOUNT_BALANCE => 'МТТ-callback. Получить баланс',
        MttModule::EVENT_CALLBACK_GET_ACCOUNT_DATA => 'МТТ-callback. Получить инфо',
        MttModule::EVENT_CALLBACK_BALANCE_ADJUSTMENT => 'МТТ-callback. Установить баланс',
        MttModule::EVENT_ADD_INTERNET => 'МТТ. Добавить интернет',
        MttModule::EVENT_CLEAR_INTERNET => 'МТТ. Сжечь интернет',
        MttModule::EVENT_CLEAR_BALANCE => 'МТТ. Сбросить баланс',

        FreeNumberModule::EVENT_EXPORT_FREE => 'Free number. Свободен',
        FreeNumberModule::EVENT_EXPORT_BUSY => 'Free number. Занят',

        AsyncModule::EVENT_ASYNC_ADD_ACCOUNT_TARIFF => 'Async. Создать УУ-услугу',
        AsyncModule::EVENT_ASYNC_PUBLISH_RESULT => 'Async. Публикация результата',

        ImportantEventsNames::ZERO_BALANCE => 'Фин. блокировка',
        ApiRobocall::EVENT_ADD_TR_CONTACT => 'Robocall. Добавление контакта',

        self::SORM_REDIRECT_EXPORT => 'SRM. Экспорт переадресаций',
        self::SORM_EXPORT_BY_DID_ID => 'SRM. Экспорт по DidId',
        self::SORM_REDIRECT_GROUP => 'SRM. Группировка переадресаций',

        SimModule::EVENT_ESIM_CHECK => 'SIM. Проверка',
        SimModule::EVENT_ESIM_ATTACH => 'SIM. Привязка',

        self::KSIM_GET_STATISTIC => 'КСИМ. Запуск сбора статистики',
        ChangeClientStructureRegistratorDto::EVENT => 'Анонсировать (в Кафку) изменение структуры ЛС',
    ];

    /**
     * @return string
     */
    public static function tableName()
    {
        return 'event_queue';
    }

    /**
     * Вернуть имена полей
     *
     * @return array [полеВТаблице = Перевод]
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'insert_time' => 'Создано, UTC',
            'next_start' => 'Запуск, UTC',
            'event' => 'Событие',
            'param' => 'Параметры',
            'status' => 'Статус',
            'iteration' => 'Кол-во попыток',
            'log_error' => 'Лог ошибок',
            'code' => 'Код',
            'account_tariff_id' => 'Услуга',
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getAccountTariff()
    {
        return $this->hasOne(AccountTariff::class, ['id' => 'account_tariff_id']);
    }

    /**
     * @return string
     * @throws \yii\base\InvalidParamException
     */
    public function getUrl()
    {
        return self::getUrlById($this->id);
    }

    /**
     * @param int $id
     * @return string
     * @throws \yii\base\InvalidParamException
     */
    public static function getUrlById($id)
    {
        return Url::to(['/monitoring/event-queue/', 'EventQueueFilter[id]' => $id]);
    }

    /**
     * @return ActiveQuery
     */
    public static function getPlannedQuery()
    {
        return self::find()
            ->where(['<=', 'next_start', DateTimeZoneHelper::getUtcDateTime()->format(DateTimeZoneHelper::DATETIME_FORMAT)])
            ->andWhere(['status' => [self::STATUS_PLAN, self::STATUS_ERROR]])
            ->orderBy([
                'iteration' => SORT_ASC, // сначала запланированные, а потом ошибочные
                'id' => SORT_ASC,
            ])
            ->limit(20);
    }

    /**
     * @return bool
     */
    public function hasPrevEvent()
    {
        return self::find()
            ->where([
                'account_tariff_id' => $this->account_tariff_id,
            ])
            ->andWhere(['<', 'id', $this->id])// строго меньше
            ->andWhere([
                'OR',
                [
                    // запланированные, которые надо выполнить сейчас
                    'AND',
                    ['status' => self::STATUS_PLAN],
                    ['<=', 'next_start', DateTimeZoneHelper::getUtcDateTime()->format(DateTimeZoneHelper::DATETIME_FORMAT)]
                ],
                // ошибочные с любой датой
                ['status' => [self::STATUS_ERROR, self::STATUS_STOP]]
            ])
            ->exists();
    }

    /**
     * Устанавливаем успешное завершение задачи
     *
     * @param string $info
     */
    public function setOk($info = '')
    {
        if ($info) {
            if (!is_string($info)) {
                $info = json_encode($info);
            }
            $this->log_error = $info . PHP_EOL . $this->log_error;
        }

        $logs = HandlerLogger::me()->get();
        if ($logs) {
            $this->trace .= implode(PHP_EOL . PHP_EOL, $logs). PHP_EOL;
            HandlerLogger::me()->clear();
        }

        $this->status = self::STATUS_OK;
        $this->save();

        // Создаем событие, уведомляющее автора о завершении задачи
//        if ($this->param && $this->event != self::COMET_NOTIFIER_EVENT) {
//            $param = json_decode($this->param, true);
//            if (isset($param['notified_user_id'])) {
//                $param['completed_id'] = $this->id;
//                $param['completed_event'] = $this->event;
//                self::go(self::COMET_NOTIFIER_EVENT, $param);
//            }
//        }
    }

    /**
     * Устанавливаем завершение задачи с ошибкой
     *
     * @param Exception|null $e
     * @param bool $isStop
     */
    public function setError($e = null, $isStop = false)
    {
        if ($isStop) {
            $this->status = self::STATUS_STOP;
        } else {
            $this->_setNextStart();
        }

        $this->iteration++;

        if ($e) {
            $this->log_error = $e->getCode() . ': ' . $e->getMessage();

            $logs = HandlerLogger::me()->get();
            if ($logs) {
                $this->trace .= implode(PHP_EOL . PHP_EOL, $logs) . PHP_EOL;
                HandlerLogger::me()->clear();
            }

            $this->trace .= $e->getFile() . ':' . $e->getLine() . ';\n ' . $e->getTraceAsString() . PHP_EOL;

            Yii::error($e);
        }

        $this->save();
    }

    /**
     * Устанавливаем время следующего запуска задачи
     */
    private function _setNextStart()
    {
        switch ($this->iteration) {
            case 0:
            case 1:
            case 2:
            case 3:
                $time = '+1 minute';
                break;
            case 4:
                $time = '+5 minute';
                break;
            case 5:
                $time = '+10 minute';
                break;
            case 6:
                $time = '+30 minute';
                break;
            case 7:
                $time = '+1 hour';
                break;
            case 8:
                $time = '+2 hour';
                break;
            case 9:
                $time = '+3 hour';
                break;
            case 10:
                $time = '+6 hour';
                break;
            case 11:
                $time = '+12 hour';
                break;
            case 12:
            case 13:
            case 14:
            case 15:
            case 16:
            case 17:
            case 18:
                $time = '+1 day';
                break;
            default:
                $this->status = self::STATUS_STOP;
                return;
        }

        $this->status = self::STATUS_ERROR;

        $this->next_start = DateTimeZoneHelper::getUtcDateTime()
            ->modify($time)
            ->format(DateTimeZoneHelper::DATETIME_FORMAT);
    }

    /**
     * Удалить старые
     */
    public static function clean()
    {
        self::deleteAll([
            '<=',
            'next_start',
            (new \DateTime())
                ->modify('-7 day')
                ->format(DateTimeZoneHelper::DATETIME_FORMAT)
        ]);
    }

    /**
     * Функция добавления события в очередь обработки
     *
     * @param string $event Название события
     * @param string|array $param Данные для обработки события
     * @param bool $isForceAdd Принудительное добавления события. (Если событие уже есть в очереди, то оно не добавляется)
     * @param string $nextStart
     * @return self
     * @throws ModelValidationException
     * @throws \yii\base\Exception
     */
    public static function go($event, $param = "", $isForceAdd = false, $nextStart = null)
    {
        if (is_array($param) && isset($param['account_tariff_id'])) {
            $accountTariffId = $param['account_tariff_id'];
        } else {
            $accountTariffId = null;
        }

        if ($event == UuModule::EVENT_UU_ANONCE) {
            $accountTariffId = null;
        }

        if (is_array($param) || is_object($param)) {
            $param = json_encode($param);
        }

        $code = md5($event . "|||" . $param);

        if ($isForceAdd) {
            $eventQueue = null;
        } else {
            $eventQueue = self::find()
                ->andWhere([
                    'code' => $code,
                    'status' => [self::STATUS_PLAN, self::STATUS_ERROR],
                ])
                ->one();
        }

        if (!$eventQueue) {
            $eventQueue = new self();
            $eventQueue->event = $event;
            $eventQueue->param = $param;
            $eventQueue->account_tariff_id = $accountTariffId;
            $eventQueue->code = $code;
            $eventQueue->log_error = '';
            $eventQueue->insert_time = DateTimeZoneHelper::getUtcDateTime()->format(DateTimeZoneHelper::DATETIME_FORMAT);
        }

        $eventQueue->iteration = 0;
        $eventQueue->status = self::STATUS_PLAN;
        $eventQueue->next_start = $nextStart ?: $eventQueue->insert_time;

        $logs = HandlerLogger::me()->get();
        if ($logs) {
            $eventQueue->trace .= implode(PHP_EOL . PHP_EOL, $logs) . PHP_EOL;
            HandlerLogger::me()->clear();
        }

        if (!$eventQueue->save()) {
            throw new ModelValidationException($eventQueue);
        }

        return $eventQueue;
    }

    /**
     * Создание задачи с привязкой индикатора
     *
     * @param string $event
     * @param mixed $eventParam
     * @param string $object
     * @param integer $objectId
     * @param string|null $section
     * @param string|null $timeLag
     * @return self
     * @throws ModelValidationException
     */
    public static function goWithIndicator($event, $eventParam, $object, $objectId = 0, $section = null, $timeLag = null)
    {
        $indicator = null;

        if ($object && $objectId) {
            /** @var EventQueueIndicator $indicator */
            $indicator = EventQueueIndicator::findOne([
                'object' => $object,
                'object_id' => $objectId,
                'section' => $section
            ]);

            // удаляем задачу из очереди, если она не выполнена
            if ($indicator &&
                $indicator->event &&
                in_array($indicator->event->status, [self::STATUS_PLAN, self::STATUS_ERROR])
            ) {
                $indicator->event->delete();
            }
        }

        $nextStart = null;
        if ($timeLag) {
            $nextStart = (new \DateTimeImmutable())->modify($timeLag)->format(DateTimeZoneHelper::DATETIME_FORMAT);
        }

        $eventQueue = self::go($event, $eventParam, false, $nextStart);

        if (!$indicator) {
            $indicator = new EventQueueIndicator;
            $indicator->object = $object;
            $indicator->object_id = $objectId;
            $indicator->section = $section;
        }

        $indicator->event_queue_id = $eventQueue->id;

        if (!$indicator->save()) {
            throw new ModelValidationException($eventQueue);
        }

        return $eventQueue;
    }
}