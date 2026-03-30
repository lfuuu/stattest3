<?php

namespace app\commands;

use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\helpers\Semaphore;
use app\models\Param;
use app\modules\uu\behaviors\AccountTariffBiller;
use app\modules\uu\classes\QueryCounterTarget;
use app\modules\uu\models\AccountEntry;
use app\modules\uu\models\AccountLogMin;
use app\modules\uu\models\AccountLogPeriod;
use app\modules\uu\models\AccountLogResource;
use app\modules\uu\models\AccountLogSetup;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\Period;
use app\modules\uu\models\ServiceType;
use app\modules\uu\tarificator\AccountEntryTarificator;
use app\modules\uu\tarificator\AccountLogMinTarificator;
use app\modules\uu\tarificator\AccountLogPeriodPackageTarificator;
use app\modules\uu\tarificator\AccountLogPeriodTarificator;
use app\modules\uu\tarificator\AccountLogResourceTarificator;
use app\modules\uu\tarificator\AccountLogSetupTarificator;
use app\modules\uu\tarificator\AnnounceResourceChangesTarifficator;
use app\modules\uu\tarificator\AutoCloseAccountTariffTarificator;
use app\modules\uu\tarificator\BillConverterTarificator;
use app\modules\uu\tarificator\BillTarificator;
use app\modules\uu\tarificator\FinanceBlockTarificator;
use app\modules\uu\tarificator\FreePeriodInFinanceBlockTarificator;
use app\modules\uu\tarificator\RealtimeBalanceTarificator;
use app\modules\uu\tarificator\SetCurrentTariffTarificator;
use app\modules\uu\tarificator\SyncResourceTarificator;
use app\modules\uu\tarificator\Tarificator;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Универсальный тарификатор (тарификатор универсальных услуг)
 */
class UbillerController extends Controller
{
    const LOCK_FILE = '/tmp/yii-ubiller';

    protected $logger;
    protected $queryCounter;

    /**
     * Плучаем основной логгер
     *
     * @return \yii\log\Logger
     */
    protected function getLogger()
    {
        return Yii::getLogger();
    }

    /**
     * Получаем таргет-объект со статистикой запросов к БД
     *
     * @return QueryCounterTarget|null
     */
    public function getQueryCounter()
    {
        if (!$this->queryCounter) {
            $logger = $this->getLogger();

            if (
                $logger &&
                $logger->dispatcher
            ) {
                $dbStatTarget = new QueryCounterTarget();
                $logger->dispatcher->targets[] = $dbStatTarget;

                $this->queryCounter = $dbStatTarget;
            }
        }

        return $this->queryCounter;
    }

    /**
     * Создать транзакции/проводки/счета за вчера и сегодня. hot 4 минуты / cold 3 часа
     *
     * @return int
     */
    public function actionIndex()
    {
        ini_set('memory_limit', '6G');

        // очистка логов проводок (uu_account_tariff_log_*)
        // * - started manualy or by crontab
        // $this->actionCleanUp();

        // Обновить AccountTariff.TariffPeriod на основе AccountTariffLog
        // Проверить баланс при смене тарифа. Если денег не хватает - отложить на день
        // обязательно это вызывать до транзакций (чтобы они правильно посчитали)
        $this->actionSetCurrentTariff();


        $this->actionAnnounceResourceChanges();

        // Автоматически закрыть услугу по истечению тестового периода
        // Обязательно после actionSetCurrentTariff (чтобы правильно учесть тариф) и до транзакций (чтобы они правильно посчитали)
        $this->actionAutoCloseAccountTariff();

        // Еще раз обновить AccountTariff.TariffPeriod на основе AccountTariffLog
        // Второй раз вызываем после actionAutoCloseAccountTariff, чтобы сразу (фактически) закрыть УУ, которые выше решили закрыть (теоретически)
        $this->actionSetCurrentTariff();

        // Отправить измененные ресурсы на платформу и другим поставщикам услуг
        // Обязательно после actionSetCurrentTariff, чтобы измененный тариф сам синхронизировал некоторые ресурсы
        $this->actionSyncResource();

        // транзакции
        $this->actionSetup();
        $this->actionPeriod();
        $this->actionResource();

        $this->actionMin();
//        $this->sem_start();

        // проводки
        $this->actionEntry();

//        $this->sem_restart();

		// Не списывать абонентку и минималку (обнулять транзакции) за ВТОРОЙ и последующие периоды при финансовой блокировке
		// Должно идти после actionEntry (чтобы проводки уже были), но до actionBill (чтобы проводки правильно учлись в счете)
        $this->actionFreePeriodInFinanceBlock();

//        $this->sem_restart();

        // счета
        $this->actionBill();

//        $this->sem_restart();
        // Конвертировать счета в старую бухгалтерию
        $this->actionBillConverter();

//        $this->sem_restart();

        // пересчитать realtimeBalance
        $this->actionRealtimeBalance();

//        $this->sem_stop();

        $this->actionPeriodPackage();

        // Месячную финансовую блокировку заменить на постоянную
        // $this->actionFinanceBlock();

        return ExitCode::OK;
    }

    public function sem_start()
    {
        if (Semaphore::me()->acquire(Semaphore::ID_UU_CALCULATOR, false)) {
            return true;
        }

        $startWait = microtime(true);
        echo PHP_EOL . date('r') . ' Info. command/ubiller wait';

        Semaphore::me()->acquire(Semaphore::ID_UU_CALCULATOR);

        echo PHP_EOL . date('r') . 'Info. command/ubiller begined. Wait time: ' . round(microtime(true) - $startWait, 2) . ' sec';
    }

    public function sem_restart()
    {
        $this->sem_stop();
        sleep(1);
        $this->sem_start();
    }

    public function sem_stop()
    {
        return Semaphore::me()->release(Semaphore::ID_UU_CALCULATOR);
    }

    /**
     * консольное отключение симафора блокировок (используется при деплое)
     */
    public function actionSemStop()
    {
        var_dump($this->sem_stop());
    }

    /**
     * Создать транзакции/проводки/счета за этот и прошлый месяц. hot 4 минуты / cold 3 часа
     *
     * @return int
     */
    public function actionFull()
    {
        AccountTariff::setIsFullTarification(true);
        return $this->actionIndex();
    }

    /**
     * Тарифицировать, вызвав нужный класс
     *
     * @param string $className
     * @return int
     */
    protected function executeRater($className)
    {
        try {
            /** @var Tarificator $rater */
            $rater = (new $className($isEcho = true));
            echo PHP_EOL . '******************************************************************************************************************************************************';
            echo PHP_EOL . '******************************************************************************************************************************************************';
            echo PHP_EOL . $rater->getDescription() . '. ' . date("Y-m-d H:i:s P") . PHP_EOL;

            $logger = $this->getLogger();
            $queryCounter = $this->getQueryCounter();
            if ($queryCounter) {
                $logger->flush();
                $queryCounter->reset();
            }

            $time = microtime(1);

            // run rater
            $rater->tarificate();

            $time = microtime(1) - $time;

            $dbStat = ['???', 0];
            if ($queryCounter) {
                $logger->flush();
                $dbStat = $queryCounter->getStat();
            }
            echo
                PHP_EOL . date("Y-m-d H:i:s P")
                . ', Done in: ' . gmdate("H:i:s", $time)
                . ', Memory: ' . sprintf(
                        '%4.2f MB (%4.2f MB in peak)',
                        memory_get_usage(true) / 1048576,
                        memory_get_peak_usage(true) / 1048576
                    )
                . ', DB queries: ' . sprintf('%s (%6.3f sec), duplicates: %s', $dbStat[0], $dbStat[1], $dbStat[2])
                . PHP_EOL;
            return ExitCode::OK;

        } catch (\Exception $e) {
            Yii::error('Ошибка универсального тарификатора. Класс ' . substr(strrchr($className, "\\"), 1));
            Yii::error($e);
            printf('Error. %s %s', $e->getMessage(), $e->getTraceAsString());
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Создать транзакции за подключение. hot 40 секунд / cold 1 минута
     * Предоплата
     * Каждое подключение - отдельная транзакция
     * Смена тарифа для не-телефонии считается тоже подключением
     */
    public function actionSetup()
    {
        $this->executeRater(AccountLogSetupTarificator::class);
    }

    /**
     * Создать транзакции абоненской платы. hot 40 секунд / cold 13 минут
     * Предоплата
     * Абонентская плата берется с выравниванием по периоду списания, то есть до конца текущего периода (месяца, квартала, года)
     * Если получилось меньше периода списания - пропорционально кол-ву дней
     * Разбить по календарным месяцам, каждый месяц записать отдельной транзакцией
     */
    public function actionPeriod()
    {
        $this->executeRater(AccountLogPeriodTarificator::class);
    }

    /**
     * Создать транзакции абоненской платы. В платных пакетах. hot 40 секунд / cold 13 минут
     */
    public function actionPeriodPackage()
    {
        $this->executeRater(AccountLogPeriodPackageTarificator::class);
    }

    /**
     * Создать транзакции за ресурсы. hot 3 минуты / cold 3 часа
     * Постоплата посуточно
     * По каждому ресурсу за каждые сутки - отдельная транзакция
     */
    public function actionResource()
    {
        $this->executeRater(AccountLogResourceTarificator::class);
    }

    /**
     * Создать транзакции минималки за ресурсы. 5 сек.
     * Предоплата по аналогии с абоненткой
     */
    public function actionMin()
    {
        $this->executeRater(AccountLogMinTarificator::class);
    }

    /**
     * Создать проводки. hot 1 секунда / cold 5 сек
     * На основе новых транзакций создать новые проводки или добавить в существующие
     */
    public function actionEntry()
    {
        $this->executeRater(AccountEntryTarificator::class);
    }

    /**
     * Создать счета. hot 1 секунда / cold 5 сек
     * На основе новых проводок создать новые счета-фактуры или добавить в существующие
     */
    public function actionBill()
    {
        $this->executeRater(BillTarificator::class);
    }

    /**
     * Не списывать абонентку и минималку (обнулять транзакции) за ВТОРОЙ и последующие периоды при финансовой блокировке. 1 секунда
	 * Должно идти после actionEntry (чтобы проводки уже были), но до actionBill (чтобы проводки правильно учлись в счете)
     */
    public function actionFreePeriodInFinanceBlock()
    {
        $this->executeRater(FreePeriodInFinanceBlockTarificator::class);
    }

    /**
     * Конвертировать новые счета в старую бухгалтерию. 3 секунды
     */
    public function actionBillConverter()
    {
        $this->executeRater(BillConverterTarificator::class);
    }

    /**
     * Обновить AccountTariff.TariffPeriod на основе AccountTariffLog. 10 секунд
     * Проверить баланс при смене тарифа. Если денег не хватает - отложить на день.
     * Обязательно это вызывать до транзакций (чтобы они правильно посчитали)
     */
    public function actionSetCurrentTariff()
    {
        $this->executeRater(SetCurrentTariffTarificator::class);
    }

    /**
     * Отправить измененные ресурсы на платформу и другим поставщикам услуг
     */
    public function actionSyncResource()
    {
        $this->executeRater(SyncResourceTarificator::class);
    }

    /**
     * Анонсировать применение ресурсов
     */
    public function actionAnnounceResourceChanges()
    {
        $this->executeRater(AnnounceResourceChangesTarifficator::class);
    }

    /**
     * Пересчитать realtimeBalance. 1 секунда
     */
    public function actionRealtimeBalance()
    {
        $this->sem_start();
        $this->executeRater(RealtimeBalanceTarificator::class);
        $this->sem_stop();
    }

    /**
     * Месячную финансовую блокировку заменить на постоянную. 1 секунда
     */
    public function actionFinanceBlock()
    {
        $this->executeRater(FinanceBlockTarificator::class);
    }

    /**
     * Автоматически закрыть услугу по истечению тестового периода. 1 секунда
     */
    public function actionAutoCloseAccountTariff()
    {
        $this->executeRater(AutoCloseAccountTariffTarificator::class);
    }

    /**
     * Удалить транзакции за ресурсы-звонки в предыдущем месяце. 5 сек
     *
     * @throws \yii\db\Exception
     */
    public function actionClearResourceCallsPrevMonth()
    {
        echo AccountLogResource::clearCalls('first day of previous month', 'last day of previous month') . ' ' . PHP_EOL;
    }

    /**
     * Удалить транзакции за ресурсы-звонки в этом месяце. 1-3 сек
     *
     * @throws \yii\db\Exception
     */
    public function actionClearResourceCallsThisMonth()
    {
        echo AccountLogResource::clearCalls('first day of this month', 'last day of this month') . ' ' . PHP_EOL;
    }

    public function actionSetAndSyncTariff()
    {
        ini_set('memory_limit', '5G');

        // Обновить AccountTariff.TariffPeriod на основе AccountTariffLog
        // Проверить баланс при смене тарифа. Если денег не хватает - отложить на день
        // обязательно это вызывать до транзакций (чтобы они правильно посчитали)
        $this->actionSetCurrentTariff();

        // Автоматически закрыть услугу по истечению тестового периода
        // Обязательно после actionSetCurrentTariff (чтобы правильно учесть тариф) и до транзакций (чтобы они правильно посчитали)
        $this->actionAutoCloseAccountTariff();

        // Еще раз обновить AccountTariff.TariffPeriod на основе AccountTariffLog
        // Второй раз вызываем после actionAutoCloseAccountTariff, чтобы сразу (фактически) закрыть УУ, которые выше решили закрыть (теоретически)
        $this->actionSetCurrentTariff();

        // Отправить измененные ресурсы на платформу и другим поставщикам услуг
        // Обязательно после actionSetCurrentTariff, чтобы измененный тариф сам синхронизировал некоторые ресурсы
        $this->actionSyncResource();
    }

    /**
     * Запустить пересчет проводок и конвертировать счета
     */
    public function actionFixBills()
    {
        // проводки
        $this->actionEntry();

        // Не списывать абонентку и минималку (обнулять транзакции) за ВТОРОЙ и последующие периоды при финансовой блокировке
        // Должно идти после actionEntry (чтобы проводки уже были), но до actionBill (чтобы проводки правильно учлись в счете)
        $this->actionFreePeriodInFinanceBlock();

        // счета
        $this->actionBill();

        // Конвертировать счета в старую бухгалтерию
        $this->actionBillConverter();

        // пересчитать realtimeBalance
        $this->actionRealtimeBalance();
    }

    /**
     * Пересчёт конкретной услуги конкретного клиента
     *
     * @param int|null $clientId
     * @param int|null $accountTariffId
     * @throws \Throwable
     * @throws \yii\base\Exception
     * @throws \yii\db\Exception
     */
    public function actionCalcAccountTariff($clientId = null, $accountTariffId = null)
    {
        if (is_null($clientId) || is_null($accountTariffId)) {
            echo 'Please provide data with client_account_id and account_tariff_id.' . PHP_EOL;
        } else {
            $params = [
                'client_account_id' => $clientId,
                'account_tariff_id' => $accountTariffId,
            ];

            echo sprintf('Билинговать услугу #%s у клиента #%s', $accountTariffId, $clientId) . PHP_EOL;
            AccountTariffBiller::recalc($params);
            echo PHP_EOL . 'Done!' . PHP_EOL;
        }
    }

    /**
     * @return array
     */
    public static function getHelpConfluence()
    {
        return ['confluenceId' => 4391334, 'message' => 'Универсальный биллер'];
    }


    /**
     * Очитска минималок, выставленных после закрытия услуги
     *
     * @param bool $isTest
     * @param integer $clientAccountId
     * @throws \Exception
     */
    public function actionClearMinEntry($isTest = true, $clientAccountId = null)
    {
        $isTest = is_bool($isTest) ? $isTest : !($isTest == 'false' || $isTest == '0');

        echo PHP_EOL . 'isTest:' . ($isTest ? 'true' : 'false');

        echo PHP_EOL . 'clientAccountId: ' . ($clientAccountId ? $clientAccountId : 'all');
        echo PHP_EOL;

        $accountWhere = $clientAccountId ? 'a.client_account_id = ' . $clientAccountId . ' AND' : '';
        $sql = <<<SQL
SELECT
  client_account_id,
  l.account_tariff_id,
  cast(actual_from_utc AS DATE) AS close_date,
  m.pack_id,
  e.id
FROM uu_account_tariff_log l, (SELECT
                                client_account_id,
                                 a.id      pack_id,
                                 account_tariff_id,
                                 max(l.id) m_id
                               FROM uu_account_tariff_log l, uu_account_tariff a
                               WHERE l.account_tariff_id = a.prev_account_tariff_id AND a.service_type_id = 3 AND
                                     {$accountWhere} actual_from_utc < now()
                               GROUP BY pack_id) m
  , uu_account_entry e
WHERE m.m_id = l.id AND l.tariff_period_id IS NULL
      AND e.account_tariff_id = pack_id 
      AND e.date >= CAST(DATE_FORMAT(NOW() , '%Y-%m-01') AS DATE)
      ###AND e.type_id = -3 
      AND e.price > 0
ORDER BY client_account_id
SQL;

        $data = \Yii::$app->db->createCommand($sql)->queryAll();

        $transaction = \Yii::$app->db->beginTransaction();

        try {
            foreach ($data as $row) {
                $entry = AccountEntry::findOne(['id' => $row['id']]);

                echo PHP_EOL . 'AccountEntry[id=' . $entry->id . '] price: ' . $entry->price;

                if ($entry->type_id == AccountEntry::TYPE_ID_MIN) {
                    foreach ($entry->accountLogMins as $logMin) {
                        echo PHP_EOL . '(-) AccountLogMins[id=' . $logMin->id . ']';
                        !$isTest && $logMin->delete();

                        $logPeriod = AccountLogPeriod::findOne(['id' => $logMin->id]);

                        if ($logPeriod) {
                            echo PHP_EOL . '(-) AccountLogPeriods[id=' . $logPeriod->id . ']';
                            !$isTest && $logPeriod->delete();
                        }
                    }
                } elseif ($entry->type_id == AccountEntry::TYPE_ID_PERIOD) {

                    foreach ($entry->accountLogPeriods as $logPeriod) {
                        echo PHP_EOL . '(-) AccountLogPeriod[id=' . $logPeriod->id . ']';
                        !$isTest && $logPeriod->delete();
                    }
                } else {
                    echo PHP_EOL . '!!!!! unknown type: ' . $entry->type_id;
                    break;
                }

                /** @var \app\modules\uu\models\Bill $bill */
                $bill = $entry->bill;

                if ($bill && $bill->is_converted) {
                    $bill->is_converted = 0;

                    echo PHP_EOL . '(*) uuBill[id=' . $bill->id . '][bill_no=' . ($bill->newBill ? $bill->newBill->bill_no : '?') . '] account_id: ' . $bill->client_account_id . ', price: ' . $bill->price;

                    if (!$isTest && !$bill->save()) {
                        throw new ModelValidationException($bill);
                    }
                }

                echo PHP_EOL . '(-) AccountEntry[id=' . $entry->id . ']';
                !$isTest && $entry->delete();
            }
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * очистка логов проводок (uu_account_tariff_log_*)
     *
     * @throws \Exception
     */
    public function actionCleanUp()
    {
        $minLogDatetimeStr = AccountTariff::getMinLogDatetime()->format(DateTimeZoneHelper::DATE_FORMAT);
        echo PHP_EOL . 'Start: ' . date('r');
        echo PHP_EOL . 'Clean from : ' . $minLogDatetimeStr;
        echo PHP_EOL . '----------------------------------';

        /** @var AccountLogPeriod $logTable */
        foreach ([
                     AccountLogSetup::class => 'date',
                     AccountLogPeriod::class => 'date_to',
                     AccountLogMin::class => 'date_to',
                     AccountLogResource::class => 'date_from',
                 ] as $logTable => $dateField) {
            $timeStart = microtime(true);
            $logTableName = explode('\\', $logTable)[4];
            echo PHP_EOL . $logTableName . ' ... ';
            $logTable::deleteAll(['<', $dateField, $minLogDatetimeStr], [], 'id ASC');
            echo 'OK (' . round(microtime(true) - $timeStart, 2) . ' sec)';
        }

        $where = ['AND', ['price_without_vat' => 0],  ['<', 'date', $minLogDatetimeStr]];
        $count = AccountEntry::find()->where($where)->count();
        echo PHP_EOL . 'Count empty account entry after min period: ' . $count;
        // нечего удалять если ничего нет
        if (!$count) {
            echo PHP_EOL;
            return;
        }
        $timeStart = microtime(true);
        echo PHP_EOL . 'Deleting... ';
        AccountEntry::deleteAll($where);
        echo 'OK (' . round(microtime(true) - $timeStart, 2) . ' sec)';
        echo PHP_EOL;
    }

    /**
     * Остановка процессов ubiller (lock-файл /tmp/yii-ubiller)
     *
     * @return int
     */
    public function actionStopBiller()
    {
        $lockFile = self::LOCK_FILE;

        $output = '';
        exec("fuser {$lockFile} 2>/dev/null", $outputLines);
        $output = implode(' ', $outputLines);

        $pids = preg_split('/\s+/', trim($output), -1, PREG_SPLIT_NO_EMPTY);

        if (!$pids) {
            echo PHP_EOL . 'Нет процессов на lock-файле' . PHP_EOL;
            return ExitCode::OK;
        }

        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                posix_kill($pid, SIGTERM);
                echo PHP_EOL . "SIGTERM -> PID {$pid}";
            }
        }

        echo PHP_EOL;

        return ExitCode::OK;
    }

    /**
     * Исправление последнего дня в счетах (newbill_lines + uu_bill.is_converted)
     *
     * @return int
     * @throws \yii\db\Exception
     */
    public function actionFixBillLastDay()
    {
        $db = \Yii::$app->db;

        $countSql = "
            SELECT count(*)
            FROM `newbill_lines`
            WHERE bill_no LIKE CONCAT(DATE_FORMAT(NOW(), '%Y%m'), '-%')
              AND item LIKE DATE_FORMAT(LAST_DAY(NOW() - INTERVAL 1 MONTH) - INTERVAL 1 DAY, '%%1-%d %% %Y%%')
        ";

        $count = $db->createCommand($countSql)->queryScalar();
        echo PHP_EOL . "Строк newbill_lines для исправления: {$count}";

        if (!$count) {
            echo PHP_EOL;
            return ExitCode::OK;
        }

        $db->createCommand("
            CREATE TEMPORARY TABLE _aa
            SELECT DISTINCT bill_id
            FROM uu_account_entry
            WHERE id IN (
                SELECT uu_account_entry_id
                FROM `newbill_lines`
                WHERE bill_no LIKE CONCAT(DATE_FORMAT(NOW(), '%Y%m'), '-%')
                  AND item LIKE DATE_FORMAT(LAST_DAY(NOW() - INTERVAL 1 MONTH) - INTERVAL 1 DAY, '%%1-%d %% %Y%%')
            )
        ")->execute();

        $updated = $db->createCommand("
            UPDATE uu_bill SET is_converted = 0 WHERE id IN (SELECT bill_id FROM _aa)
        ")->execute();

        echo PHP_EOL . "Обновлено uu_bill: {$updated}";

        $db->createCommand("DROP TEMPORARY TABLE _aa")->execute();

        echo PHP_EOL;

        return ExitCode::OK;
    }

    public function actionRecalc()
    {
        AccountTariffBiller::recalc([
            'account_tariff_id' => 1009085,
            'client_account_id' => 108936,
        ]);
    }

    /**
     * Проверка годовых тарифов телефонии: вычтена ли стоимость пакета (1 руб.) из price в log_period.
     *
     * Без параметра — dry-run (только отчёт).
     * С любым параметром — исправление.
     *
     * @param string|null $mode
     */
    /**
     * Заполнение аггригационной таблицы
     *
     * @param str $from
     * @param str $to
     */
    public function actionMakeAggr($from = null, $to = null)
    {
        // @todo clean
        // @todo delete in the same period

        $sqlStr = $from && $to ? '\'' . $from . '\',\'' . $to . '\'' : '';
        $time0 = microtime(true);
        echo 'make aggregate table: ' . Yii::$app->dbPg
                ->createCommand("select calls_aggr.fill_uu_aggr({$sqlStr})")
                ->queryScalar() . PHP_EOL;

        echo 'Done in ' . round(microtime(true) - $time0, 2) . ' sec' . PHP_EOL;
    }

    /**
     * заполнение таблицы с маржой
     *
     * @param str $from
     * @param str $to
     */
    public function actionFillMargins($from = null, $to = null)
    {
        // @todo rotating or clean
        // @todo delete in the same period

        $sqlStr = $from && $to ? '\'' . $from . '\',\'' . $to . '\'' : '';
        $time0 = microtime(true);
        echo 'make margin table: ' . Yii::$app->dbPg
                ->createCommand("select calls_margin.make({$sqlStr})")
                ->queryScalar() . PHP_EOL;
        echo 'Done in ' . round(microtime(true) - $time0, 2) . ' sec' . PHP_EOL;
    }

    /**
     * Подготовка перед вычислением ресурсов. (Разбитие на равные блоки)
     * @return void
     */
    public function actionPrepareResourceCalc($parts = 10)
    {
        $count = AccountTariff::find()->where(['not', ['tariff_period_id' => null]])->count();
        $min = AccountTariff::find()->where(['not', ['tariff_period_id' => null]])->min('id');
        $max = AccountTariff::find()->where(['not', ['tariff_period_id' => null]])->max('id');

        echo PHP_EOL.'Count: ' . $count;
        echo PHP_EOL.'Min/max: ' . $min . ' / ' . $max;
        echo PHP_EOL.'Parts: ' . $parts;

        $perPart = round($count / $parts);
        $start = 0;

        $sql = <<<SQL
        with uat as (
            select id from uu_account_tariff where tariff_period_id is not null order by id
        )
SQL;
        for ($part = 0; $part < $parts ; $part++) {
            $sql .= PHP_EOL . ($part ? ' union ' : '') . "select {$part} as id, min(id) as min, max(id) as max from (select * from uat order by id limit {$start}, {$perPart} ) a";
            $start += $perPart;
        }

        $d = [];
        foreach(AccountTariff::getDb()->createCommand($sql)->queryAll() as $a) {
            $d[$a['id']] = ['min' => $a['min'], 'max' => $a['max']];
            echo PHP_EOL . $a['id'] . ': ' . $a['min'] . ' / ' . $a['max'];
        }

        Param::setParam(Param::RESOURCE_PARTS, $d);

        echo PHP_EOL;
    }

}
