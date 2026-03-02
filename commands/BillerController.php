<?php

namespace app\commands;

use app\classes\ActOfReconciliation;
use app\classes\adapters\EbcKafka;
use app\classes\api\SberbankApi;
use app\classes\HandlerLogger;
use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\BalanceByMonth;
use app\models\Bill;
use app\models\billing\AiDialogRaw;
use app\models\ClientAccountOptions;
use app\models\EntryPoint;
use app\models\important_events\ImportantEvents;
use app\models\important_events\ImportantEventsNames;
use app\models\important_events\ImportantEventsSources;
use app\models\SberbankOrder;
use app\models\Transaction;
use app\modules\uu\models\AccountEntry;
use app\modules\uu\models\Period;
use Yii;
use DateTime;
use app\classes\bill\ClientAccountBiller;
use app\models\ClientAccount;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Expression;

class BillerController extends Controller
{

    /**
     * Запуск создания транзакций для счетов
     *
     * @return int
     * @throws \Exception
     */
    public function actionTariffication()
    {
        define('MONTHLY_BILLING', 1);

        Yii::info("Запущен тарификатор");

        $partSize = 500;
        $date = new DateTime();
        $date->modify('first day of this month');

        try {
            $count = $partSize;
            $offset = 0;
            while ($count >= $partSize) {
                $clientAccounts = ClientAccount::find()
                    ->andWhere(['NOT IN', 'status', [
                        ClientAccount::STATUS_CLOSED,
                        ClientAccount::STATUS_DENY,
                        ClientAccount::STATUS_TECH_DENY,
                        ClientAccount::STATUS_TRASH,
                        ClientAccount::STATUS_ONCE]])
                    ->limit($partSize)
                    ->offset($offset)
                    ->orderBy('id')
                    ->all();

                foreach ($clientAccounts as $clientAccount) {
                    $offset++;
                    $this->tarifficateClientAccount($clientAccount, $date, $offset);
                }

                $count = count($clientAccounts);
            }
        } catch (\Exception $e) {
            Yii::error('ОШИБКА ТАРИФИКАТОРА');
            Yii::error($e);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        Yii::info("Тарификатор закончил работу");

        return ExitCode::OK;
    }

    /**
     * Транзакции для ЛС
     *
     * @param ClientAccount $clientAccount
     * @param DateTime $date
     * @param int $position
     * @return int
     */
    protected function tarifficateClientAccount(ClientAccount $clientAccount, DateTime $date, $position)
    {
        Yii::info("Тарификатор. $position. Лицевой счет: " . $clientAccount->id);

        try {

            ClientAccountBiller::create($clientAccount, $date, $onlyConnecting = false, $connecting = false,
                $periodical = true, $resource = false)
                ->process();

            $resourceDate = clone $date;
            $resourceDate->modify('-1 day');

            ClientAccountBiller::create($clientAccount, $resourceDate, $onlyConnecting = false, $connecting = false,
                $periodical = false, $resource = true)
                ->process();

        } catch (\Exception $e) {
            Yii::error('ОШИБКА ТАРИФИКАТОРА. Лицевой счет: ' . $clientAccount->id);
            Yii::error($e);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Генерация событий предполагаемого отключения ЛС после выставления счета
     *
     * @return int
     * @throws \Exception
     */
    public function actionForecastAccountBlock()
    {
        define('MONTHLY_BILLING', 1);

        Yii::info("Запущен прогноз отключения клиента при выставлении счета");

        $partSize = 500;
        $date = new DateTime();
        $date->modify('first day of next month');

        $now = new DateTime('now', new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_DEFAULT));

        $firstDay = clone $now;
        $firstDay->modify('first day of this month');
        $firstDay->setTime(0, 0, 0);

        $lastDay = clone $firstDay;
        $lastDay->modify('last day of this month');
        $lastDay->setTime(23, 59, 59);

        $diffToNow = $firstDay->diff($now);
        $diffToBlock = $lastDay->diff($now);

        $dayToBlock = $diffToBlock->days;
        $dayToNow = $diffToNow->days;

        // потребленный трафик с начала месяца + (сколько дней осталось * среднесуточное потребление + 10%)
        $forecastCoefficient = 1 / ((1 / ($dayToNow + ($dayToBlock * 1.1))) * $dayToNow);

        $importantEventName = null;

        switch ($dayToBlock) {
            case 7:
                $importantEventName = ImportantEventsNames::FORECASTING_7DAY;
                break;

            case 3:
                $importantEventName = ImportantEventsNames::FORECASTING_3DAY;
                break;

            case 0:
                $importantEventName = ImportantEventsNames::FORECASTING_1DAY;
                break;

            default:
                throw new \LogicException('Прогнозирование запускается не в тот день.');
        }


        $count = $partSize;
        $offset = 0;
        while ($count >= $partSize) {
            $clientAccounts = ClientAccount::find()
                ->with('superClient')
                ->andWhere(['NOT IN', 'status', [
                    ClientAccount::STATUS_CLOSED,
                    ClientAccount::STATUS_DENY,
                    ClientAccount::STATUS_TECH_DENY,
                    ClientAccount::STATUS_TRASH,
                    ClientAccount::STATUS_ONCE]])
                ->limit($partSize)
                ->offset($offset)
                ->orderBy('id')
                ->all();

            foreach ($clientAccounts as $clientAccount) {

                $offset++;

                if ($clientAccount->superClient->entry_point_id == EntryPoint::ID_MNP_RU_DANYCOM) {
                    echo '- ' . $clientAccount->id;
                    continue;
                }

                echo '. ' . $clientAccount->id;
                continue;

                Yii::info("Прогнозирование. $offset. Лицевой счет: " . $clientAccount->id);

                try {

                    switch ($clientAccount->account_version) {
                        case ClientAccount::VERSION_BILLER_UNIVERSAL:
                            $forecastBillSum = $this->forecastingUniversalAccountBill($clientAccount, $firstDay, $lastDay, $forecastCoefficient);
                            break;

                        case ClientAccount::VERSION_BILLER_USAGE:
                            $forecastBillSum = $this->forecastingAccountBill($clientAccount, $date, $forecastCoefficient);
                            break;

                        default:
                            Yii::error('неизвестная версия ЛС: ' . $clientAccount->id);
                            continue 2;
                    }

                    if ($forecastBillSum && $clientAccount->credit < -$clientAccount->balance + $forecastBillSum) {
                        echo PHP_EOL . $clientAccount->id . ": " . $forecastBillSum;
                        echo " Balance: {$clientAccount->balance} ({$clientAccount->credit} < " . (-$clientAccount->balance + $forecastBillSum) . ")";

                        ImportantEvents::create($importantEventName,
                            ImportantEventsSources::SOURCE_STAT,
                            [
                                'client_id' => $clientAccount->id,
                                'credit' => $clientAccount->credit,
                                'forecast_bill_sum' => $forecastBillSum
                            ]
                        );
                    }

                } catch (\Exception $e) {
                    echo PHP_EOL . 'Error: ' . $clientAccount->id . ': ' . $e->getMessage();

                    Yii::error('Ошибка прогнозирования');
                    Yii::error($e);
                }

            }

            $count = count($clientAccounts);
        }

        Yii::info("Прогнозирование законилось");

        return ExitCode::OK;
    }


    /**
     * Прогнозирование суммы счета ЛС
     *
     * @param ClientAccount $clientAccount
     * @param DateTime $date
     * @param float $forecastCoefficient
     * @return int
     * @internal param float $monthPart
     * @internal param int $position
     */
    protected function forecastingAccountBill(ClientAccount $clientAccount, DateTime $date, $forecastCoefficient)
    {
        $billerSubscription = ClientAccountBiller::create(
            $clientAccount,
            $date,
            $onlyConnecting = false,
            $connecting = false,
            $periodical = true,
            $resource = false)
            ->createTransactions();

        $resourceDate = clone $date;
        $resourceDate->modify('-1 day');

        $billerResource = ClientAccountBiller::create(
            $clientAccount,
            $resourceDate,
            $onlyConnecting = false,
            $connecting = false,
            $periodical = false,
            $resource = true,
            $forecastCoefficient)
            ->createTransactions();

        return round(
            array_reduce(
                array_merge(
                    $billerSubscription->getTransactions(),
                    $billerResource->getTransactions()
                ),
                function ($sum, $item) {
                    /** @var Transaction $item */
                    return $sum + $item->sum;
                }
            ),
            2);
    }

    /**
     * Прогнозирование счета в УЛС
     *
     * @param ClientAccount $account
     * @param DateTime $firstDate
     * @param DateTime $lastDate
     * @param float $forecastCoefficient
     * @return int
     */
    protected function forecastingUniversalAccountBill(ClientAccount $account, DateTime $firstDate, DateTime $lastDate, $forecastCoefficient)
    {
        $data = AccountEntry::find()
            ->alias('entry')
            // если абонентка не за весь месяц
            ->select(new Expression('SUM(
                IF(
                    entry.type_id < 0, # не ресурсы
                    if (DATE_FORMAT(entry.date_to, \'%d\') != :lastDay, # дата окончания не совпадает с последним днем месяца - значит услуга отключена раньше
                        0,
                        if (DATE_FORMAT(entry.date_from, \'%d\') != 1, # если не с начала месяца - то вычисляем полную абонентку 
                            ROUND(entry.price_with_vat/((DATEDIFF(entry.date_to, entry.date_from)+1)/:lastDay), 2),
                            entry.price_with_vat
                        )
                    ),
                    entry.price_with_vat
                    )
                )', [':lastDay' => (int)$lastDate->format('d')]))
            ->joinWith('accountTariff uat')
            ->joinWith('tariffPeriod utp')
            ->where([
                'uat.client_account_id' => $account->id,
                'entry.date' => $firstDate->format(DateTimeZoneHelper::DATE_FORMAT),
                'utp.charge_period_id' => Period::ID_MONTH
            ])
            ->andWhere(['<>', 'entry.price_with_vat', 0])
            ->andWhere(['<>', 'entry.type_id', AccountEntry::TYPE_ID_SETUP])// без платы за подключение
            ->groupBy('entry.type_id')
            ->indexBy('type_id')
            ->column();

        $sum = 0;

        foreach ($data as $typeId => $typeSum) {
            // абонентку и минималку берем столько же, ресурсы - пропорционально
            $sum += $typeId < 0 ? $typeSum : $typeSum * $forecastCoefficient;
        }

        return $sum;
    }

    /**
     * Завершение сбербанковских платежей (запускать каждую минуту)
     * @throws \Exception
     */
    public function actionSberbankOrdersFinishing()
    {
        if (!isset(\Yii::$app->params['SberbankApi'])) {
            throw new \Exception('SberbankApi not configured');
        }

        $now = new \DateTimeImmutable('now');

        if ($now->format('i') == 0) { // в 00 минут каждого часа.
            $date = $now->modify('-3 day');
        } else if (($now->format('i') % 10) == 0) { // каждые 10 минут
            $date = $now->modify('-2 hour');
        } else { // каждую минуту
            $date = $now->modify('-10 minute');
        }

        foreach (\Yii::$app->params['SberbankApi'] as $organizationId => $noNeedThisData) {
            $sberbankApi = new SberbankApi($organizationId);

            $orderQuery = SberbankOrder::find()
                ->where([
                    'status' => SberbankOrder::STATUS_REGISTERED
                ])
                ->andWhere(['>', 'created_at', $date->format(DateTimeZoneHelper::DATETIME_FORMAT)]);

            /** @var SberbankOrder $order */
            foreach ($orderQuery->each() as $order) {

//                if ($order->bill->clientAccount->contract->organization_id != $organizationId) {
//                    continue;
//                }

                try {

                    $info = $sberbankApi->getOrderStatusExtended($order->order_id);

                    if ($info['orderStatus'] == SberbankOrder::STATUS_PAYED) {
                        $order->makePayment($info);

                        ClientAccount::dao()->updateBalance($order->bill->client_id);

                        echo PHP_EOL . date("r") . ': ' . $order->bill_no . ' - payed';
                    }
                } catch (\Exception $e) {
                    echo PHP_EOL;
                    var_dump($order->getAttributes());
                    echo PHP_EOL . 'Error: ' . $e->getMessage();
                }
            }
        }
    }

    /**
     * Выставление авансовых счетов операторам
     * @throws \yii\base\Exception
     * @throws \Exception
     */
    public function actionAdvanceAccounts()
    {
        $isProcessed = false;
        $logger = HandlerLogger::me();

        $today = (new \DateTimeImmutable('now'))
            ->setTime(0, 0, 0);

        $logger->add('today: ' . $today->format('r'));

        $periodEnd = $today;

        $clientAccountQueryBase = ClientAccount::find()
            ->joinWith('options o')->where([
                'o.option' => ClientAccountOptions::OPTION_SETTINGS_ADVANCE_INVOICE,
            ]);

        // выставление авансовых счетов по понедельникам
        if ($today->format('w') == 1) {
            $clientAccountQuery = clone $clientAccountQueryBase;
            $isProcessed = true;
            $logger->add(date('r') . ': Advance invoicing on Mondays');
            $clientAccountQuery->where(['o.value' => ClientAccountOptions::SETTINGS_ADVANCE_EVERY_WEEK_ON_MONDAY]);
            $periodStart = $today->modify('-7 days');

            $logger->add('Period start: ' . $periodStart->format('r'));
            $logger->add('Period end:   ' . $periodEnd->format('r'));
            Bill::dao()->advanceAccounts($clientAccountQuery, $periodStart, $periodEnd);
        }

        // выставление каждого 1 и 15 числа
        $todayDayNumber = $today->format('d');
        if (in_array($todayDayNumber, [1, 16])) {
            $clientAccountQuery = clone $clientAccountQueryBase;
            $isProcessed = true;
            $logger->add(date('r') . ': Advance invoicing on ' . $todayDayNumber);

            // 1 число
            if ($todayDayNumber == 1) {
                $periodStart = $today->modify('-1 month')->modify('+15 days');
            } else { // 15 число
                $periodStart = $today->modify('first day of this month');
            }

            $clientAccountQuery->where(['o.value' => ClientAccountOptions::SETTINGS_ADVANCE_1_AND_15]);

            $logger->add('Period start: ' . $periodStart->format('r'));
            $logger->add('Period end:   ' . $periodEnd->format('r'));

            Bill::dao()->advanceAccounts($clientAccountQuery, $periodStart, $periodEnd);
        }

        if ($isProcessed && ($logs = $logger->get())) {
            echo implode(PHP_EOL, $logs) . PHP_EOL;
        }
    }

    /**
     * Занести инвойсы в книгу продаж за прошлый месяц.
     * @throws \Throwable
     */
    public function actionMakeInvoices()
    {
        $query = Bill::find()
            ->alias('b');

        $now = (new \DateTimeImmutable())
            ->setTime(0, 0, 0);

        $from = $now->modify('first day of previous month');
        $to = $now->modify('last day of this month');

        $query->andWhere([
            'between',
            'b.bill_date',
            $from->format(DateTimeZoneHelper::DATE_FORMAT),
            $to->format(DateTimeZoneHelper::DATE_FORMAT)
        ])
            ->andWhere(['>', 'sum', 0]);


        /** @var Bill $bill */
        foreach ($query->each() as $bill) {
            echo ' .';
            try {
                $bill->generateInvoices();
//                $bill->checkInvoices();
            } catch (\Exception $e) {
                echo PHP_EOL . $e->getMessage();
                echo PHP_EOL;
            }
        }

        echo PHP_EOL . 'start save balances';
        ActOfReconciliation::me()->saveBalances();
    }

    /**
     * Сгенерировать отчет по счетам
     * @throws \yii\db\Exception
     * @throws \Exception
     */
    public function actionGetBillReport()
    {
        $from = (new \DateTimeImmutable('now'))->modify('-3 month')->modify('first day of this month');
        $to = (new \DateTimeImmutable('now'))->modify('last day of this month');

        $q = \Yii::$app->db->createCommand('SELECT
  sum,
  currency,
  month,
  mn,
  firm_name,
  bs_name
FROM (
       SELECT
         sum(sum) AS                  sum,
         b.currency,
         b.organization_id,
         date_format(bill_date, \'%M\') month,
         date_format(bill_date, \'%m\') mn,
         bs.name                      bs_name
       FROM `newbills` b, clients c, client_contract cc, client_contract_business bs
       WHERE bill_date BETWEEN :date_from AND :date_to
             AND sum > 0 AND c.id = b.client_id AND c.contract_id = cc.id AND bs.id = cc.business_id
       GROUP BY organization_id, currency, date_format(bill_date, \'%Y%m\'), bs_name
     ) a,
  (
    SELECT
      o.organization_id                                                                AS id,
      (SELECT value
       FROM organization_i18n
       WHERE organization_record_id = o.id AND lang_code = \'ru-RU\' AND field = \'name\') AS firm_name
    FROM (
           SELECT
             organization_id     o_id,
             max(actual_from) AS max_from
           FROM `organization`
           GROUP BY organization_id
         ) a, `organization` o
    WHERE o.organization_id = a.o_id AND actual_from = max_from
  ) o
WHERE a.organization_id = o.id
ORDER BY mn, firm_name, bs_name, currency
', [
            ':date_from' => $from->format(DateTimeZoneHelper::DATE_FORMAT),
            ':date_to' => $to->format(DateTimeZoneHelper::DATE_FORMAT)
        ])->queryAll();


        $data = [];
        $months = [];
        $total = [];
        foreach ($q as $l) {
            $months[$l['mn']] = $l['month'];
            $data[$l['firm_name']][$l['bs_name']][$l['currency']][$l['mn']] = $l['sum'];

            if (!isset($total[$l['currency']][$l['mn']])) {
                $total[$l['currency']][$l['mn']] = 0;
            }
            $total[$l['currency']][$l['mn']] += $l['sum'];
        }

        ksort($months);
        ksort($total);

        echo PHP_EOL . "Компания\tПодразделение\tвалюта";
        foreach ($months as $k => $month) {
            echo "\t" . $month;
        }

        foreach ($data as $firm => $firmData) {
            foreach ($firmData as $bp => $bpData) {
                foreach ($bpData as $currency => $curData) {

                    echo PHP_EOL . $firm . "\t" . $bp . "\t" . $currency;

                    foreach ($months as $k => $month) {
                        $sum = isset($curData[$k]) ? $curData[$k] : '';
                        echo "\t" . str_replace(".", ",", $sum);
                    }
                }
            }
        }

        foreach ($total as $currency => $curData) {
            echo PHP_EOL . "\t\t" . $currency;
            foreach ($months as $k => $month) {

                $sum = isset($curData[$k]) ? $curData[$k] : '';
                echo "\t" . str_replace(".", ",", $sum);
            }
        }
    }

    /**
     * Сохраняем балансы в ЛС по месяцам
     */
    public function actionSaveMonthBalance($accountId = null)
    {
        ActOfReconciliation::me()->saveBalances($accountId);
    }

    public function actionUpdateLocks()
    {
        $data = \Yii::$app->dbPg->createCommand(
            'SELECT client_id, 
sum(CASE WHEN voip_auto_disabled THEN 1 ELSE 0 END) > 0 b_voip_auto_disabled, 
sum(CASE WHEN voip_auto_disabled_local THEN 1 ELSE 0 END) > 0 b_voip_auto_disabled_local,
sum(CASE WHEN is_overran THEN 1 ELSE 0 END ) > 0 b_is_overran,
sum(CASE WHEN is_mn_overran THEN 1 ELSE 0 END ) > 0 b_is_mn_overran,
sum(CASE WHEN is_finance_block THEN 1 ELSE 0 END ) > 0 b_is_finance_block,
(SELECT dt FROM billing.clients_locks_logs lg
  WHERE (lg.client_id = l.client_id)
  ORDER BY id DESC
  LIMIT 1
) dt_last_dt
FROM billing.clients_locks l -- where client_id in (44200, 50935)
GROUP BY client_id')->queryAll();

        $cache = \Yii::$app->cache;

        $newClients = [];
        $clients = $cache->get('lockcls');
        if (!$clients || !is_array($clients)) {
            $clients = [];
        }

        foreach ($data as $row) {
            $newClients[$row['client_id']] = 1;
            $cache->set('lock' . $row['client_id'], $row, 3 * 60); // установим на 3 минуты. Обновление каждую минуту. Если обнолвения не будет - брать из базы.
            unset($clients[$row['client_id']]);
        }

        $cache->set('lockcls', $newClients);

        foreach ($clients as $clientId => $null) {
            $cache->delete('lock' . $clientId);
        }
    }

    /**
     * Подготовка агрегации звонков за текущий и предыдущий месяц
     *
     * @return int
     * @throws \yii\db\Exception
     */
    public function actionPrepareCallsAggr()
    {
        $db = \Yii::$app->dbPg;

        $currentSuffix = date('Ym');
        $prevSuffix = date('Ym', strtotime('first day of previous month'));

        echo PHP_EOL . 'Создание партиции calls_aggr...';
        $db->createCommand("SELECT calls_aggr.create_calls_aggr_partition(now()::date)")->queryScalar();
        echo ' OK';

        foreach ([$currentSuffix, $prevSuffix] as $suffix) {
            echo PHP_EOL . "Агрегация calls_aggr_{$suffix}...";

            $db->createCommand("TRUNCATE calls_aggr.calls_aggr_{$suffix}")->execute();

            $db->createCommand("
                INSERT INTO calls_aggr.calls_aggr_{$suffix}(
                    server_id, aggr_time, orig, trunk_id, account_id,
                    trunk_service_id, number_service_id, destination_id, mob,
                    last_call_id, billed_time, cost, tax_cost, interconnect_cost,
                    total_calls, notzero_calls
                ) SELECT
                    server_id, date_trunc('hour', connect_time) AS aggr_time, orig, trunk_id, account_id,
                    trunk_service_id, number_service_id, destination_id, mob,
                    max(id) AS last_call_id, sum(billed_time) AS billed_time,
                    sum(cost) AS cost, sum(tax_cost) AS tax_cost,
                    sum(interconnect_cost) AS interconnect_cost, sum(1) AS total_calls,
                    sum(CASE abs(cost) >= 0.0001 WHEN true THEN 1 ELSE 0 END) AS notzero_calls
                FROM calls_raw.calls_raw_{$suffix}
                GROUP BY
                    server_id, aggr_time, orig, trunk_id, account_id,
                    trunk_service_id, number_service_id, destination_id, mob
            ")->execute();

            echo ' OK';
        }

        echo PHP_EOL;

        return ExitCode::OK;
    }

    /**
     * Массовые счета по старым проводкам
     *
     * @return int
     */
    public function actionBillMassLegacy()
    {
        set_time_limit(0);

        $partSize = 500;
        $date = new DateTime();
        $totalCount = 0;
        $totalAmount = 0;
        $totalErrors = 0;

        echo PHP_EOL . 'Массовое выставление счетов';

        $count = $partSize;
        $offset = 0;

        while ($count >= $partSize) {
            $clientAccounts = ClientAccount::find()
                ->andWhere(['NOT IN', 'status', [
                    ClientAccount::STATUS_CLOSED,
                    ClientAccount::STATUS_DENY,
                    ClientAccount::STATUS_TECH_DENY,
                    ClientAccount::STATUS_TRASH,
                    ClientAccount::STATUS_ONCE,
                ]])
                ->limit($partSize)
                ->offset($offset)
                ->orderBy('id')
                ->all();

            foreach ($clientAccounts as $clientAccount) {
                $offset++;

                try {
                    $bill = \app\classes\bill\BillFactory::create($clientAccount, $date)->process();

                    if ($bill) {
                        $totalCount++;
                        $totalAmount += $bill->sum;
                        echo PHP_EOL . "{$offset}. ЛС {$clientAccount->id}: счет {$bill->bill_no} на {$bill->sum}";
                    }
                } catch (\Exception $e) {
                    $totalErrors++;
                    echo PHP_EOL . "{$offset}. ЛС {$clientAccount->id}: ОШИБКА " . $e->getMessage();
                    Yii::error($e);
                }
            }

            $count = count($clientAccounts);
        }

        echo PHP_EOL . "Счетов: {$totalCount}, сумма: {$totalAmount}, ошибок: {$totalErrors}" . PHP_EOL;

        return ExitCode::OK;
    }

    /**
     * Месячная биллинговая рутина (крон, 1-е число месяца)
     *
     * @return int
     */
    public function actionMonthlyRoutine()
    {
        ini_set('memory_limit', '6G');
        set_time_limit(0);

        $lockFile = UbillerController::LOCK_FILE;

        // Остановить текущий ubiller и захватить lock на весь процесс
        $this->runStep('ubiller/stop-biller', [], 'Остановка ubiller');

        $fp = fopen($lockFile, 'c');
        if (!$fp || !flock($fp, LOCK_EX)) {
            echo PHP_EOL . 'ОШИБКА: не удалось захватить lock-файл' . PHP_EOL;
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $steps = [
            ['ubiller/clear-min-entry', [0], 'Очистка минималок'],
            ['convert/one-rub-and-year-packages/check-yearly-packages', [], 'Проверка годовых пакетов'],
            ['biller/prepare-calls-aggr', [], 'Агрегация звонков'],
            ['biller/tariffication', [], 'Тарификация'],
            ['biller/bill-mass-legacy', [], 'Массовые счета по старым проводкам'],
            ['ubiller/fix-bill-last-day', [], 'Исправление последнего дня'],
            ['ubiller/index', [], 'Универсальный биллер'],
        ];

        foreach ($steps as [$action, $params, $label]) {
            $this->runStep($action, $params, $label);
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        echo PHP_EOL . date('Y-m-d H:i:s') . ' Месячная рутина завершена' . PHP_EOL;

        return ExitCode::OK;
    }

    /**
     * @param string $action
     * @param array $params
     * @param string $label
     */
    protected function runStep($action, array $params, $label)
    {
        $time = microtime(true);
        echo PHP_EOL . date('Y-m-d H:i:s') . " [{$label}] ...";

        try {
            \Yii::$app->runAction($action, $params);
            echo ' ' . round(microtime(true) - $time) . ' сек';
        } catch (\Exception $e) {
            echo " ОШИБКА: " . $e->getMessage();
            Yii::error($e);
        }
    }

    public function actionListenAiDialogRaw()
    {
        /**
         * {
         *     "id": "1730fcfd-1e1e-44be-a843-ce435b62cdd7",
         *    "data": {
         *            "data": {
         *                "agent": {
         *                    "id": "31",
         *                    "name": "Должники"
         *                },
         *                "accountId": "142329",
         *                "durationSec": 113,
         *                "endTimestamp": "2025-09-12T14:23:10.343120611Z",
         *                "serviceTypeId": 40,
         *                "statProductId": 2948443,
         *                "startTimestamp": "2025-09-12T14:21:17.375489573Z"
         *        },
         *        "eventTs": "2025-09-12T14:23:10.343208086Z",
         *        "eventName": "rts_agent_billing",
         *        "eventVersion": 1
         *   },
         *    "type": "billing_event"
         * }
         */

        $topic = 'products_billing_events';
        EbcKafka::me()->getMessage($topic, function (\RdKafka\Message $message) {

            print_r($message);

            $data = $message->payload;
            $msg = json_decode($data, true);

            if (!$msg || !is_array($msg) || !isset($msg['type']) || $msg['type'] != 'billing_event') {
                print_r('SKIP (type=' . ($msg['type'] ?? '?') . ')');
                return;
            }


            $data0 = $msg['data'];
            $data = $data0['data'];

            $raw = new AiDialogRaw();
            $raw->event_id = $msg['id'];

            $raw->event_type = $msg['type'];
            $raw->agent_id = $data['agent']['id'];
            $raw->agent_name = $data['agent']['name'];
            $raw->account_id = $data['accountId'];
            $raw->service_type_id = $data['serviceTypeId'];
            $raw->account_tariff_id = $data['statProductId'];
            $raw->duration = $data['durationSec'];
            $raw->action_start = $data['startTimestamp'];
            $raw->action_end = $data['endTimestamp'];

            $raw->event_ts = $data0['eventTs'];
            $raw->event_name = $data0['eventName'];
            $raw->event_version = $data0['eventVersion'];

            try {
                $raw->save();
            } catch (\Exception $e) {
                $msg = $e->getMessage();

                echo PHP_EOL . 'Error: ';
                var_dump($msg);

                if (strpos($msg, 'SQLSTATE[23505]') !== false) {
                    echo '!!!!!!!!!';
                    return ; // SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "raw_pkey"
                }

                throw $e;
            }

        });
    }
}
