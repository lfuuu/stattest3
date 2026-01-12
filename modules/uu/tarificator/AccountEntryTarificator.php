<?php

namespace app\modules\uu\tarificator;

use app\classes\Connection;
use app\models\BusinessProcessStatus;
use app\models\ClientAccount;
use app\models\ClientContract;
use app\models\OperationType;
use app\modules\uu\classes\helper\AccountTariffRunner;
use app\modules\uu\models\AccountEntry;
use app\modules\uu\models\AccountLogMin;
use app\modules\uu\models\AccountLogPeriod;
use app\modules\uu\models\AccountLogResource;
use app\modules\uu\models\AccountLogSetup;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\ResourceModel;
use app\modules\uu\models\Tariff;
use app\modules\uu\models\TariffPeriod;
use app\modules\uu\models\TariffResource;
use Yii;
use yii\db\Expression;

/**
 * Расчет для бухгалтерской проводки (AccountEntry)
 *
 * @link http://bugtracker.welltime.ru/jira/browse/BIL-1909
 */
class AccountEntryTarificator extends Tarificator
{
    const STEP_BATCH = 10000;

    public function runCalculateEntries($accountTariffId, $sectionCb)
    {
        if ($accountTariffId) {
            $sectionCb($accountTariffId);
        } else {
            $this->isEcho = false;
            (new AccountTariffRunner())->run(function($fromAccountId, $toAccountId) use ($sectionCb) {
                $_accountTariffId = [$fromAccountId, $toAccountId];
                $sectionCb($_accountTariffId);
            });
            $this->isEcho = true;

            echo PHP_EOL;
        }
    }

    /**
     * На основе новых транзакций создать новые проводки или добавить в существующие
     *
     * @param int|null $accountTariffId Если указан, то только для этой услуги. Если не указан - для всех
     * @throws \yii\db\Exception
     */
    public function tarificate($accountTariffId = null)
    {
        if (!$accountTariffId) {
            $db = Yii::$app->db;
            $db->createCommand("DROP TEMPORARY TABLE IF EXISTS clients_postpaid")->execute();
            $db->createCommand("CREATE TEMPORARY TABLE `clients_postpaid` (
                           `id` int NOT NULL AUTO_INCREMENT,
                           `is_postpaid` int NOT NULL DEFAULT '0',
                           PRIMARY KEY (`id`)
            ) ENGINE=InnoDB")->execute();
            $db->createCommand("INSERT INTO clients_postpaid (id, is_postpaid) SELECT id, is_postpaid FROM clients")->execute();
        }

        // Подключение
        // Транзакции группировать в проводки следующего месяца
        $this->out('Проводки за подключение');
        $this->runCalculateEntries($accountTariffId, function ($accountTariffId) {
            $this->calculateEntries(
                AccountLogSetup::tableName(),
                new Expression((string)AccountEntry::TYPE_ID_SETUP),
                'date',
                'date',
                $accountTariffId,
                $isSplitByMonths = true,
                $isGroupPerDayToMonth = true
            );
        });

        // Абонентская плата
        // Постоплатные: все транзакции группировать в проводки следующего месяца
        // Предоплатные: помесячные транзакции от 1го числа группировать в проводки того же месяца. Остальные транзакции (посуточные или не от 1го числа) группировать в проводки следующего месяца
        // Посуточные проводки не группировать в транзакции, а так и оставлять 1-в-1 независимо от типа оплаты
        $this->out(PHP_EOL . 'Проводки за абоненскую плату');
        $this->runCalculateEntries($accountTariffId, function ($accountTariffId) {
            $this->calculateEntries(
                AccountLogPeriod::tableName(),
                new Expression((string)AccountEntry::TYPE_ID_PERIOD),
                'date_from',
                'date_to',
                $accountTariffId,
                $isSplitByMonths = true,
                $isGroupPerDayToMonth = false
            );
        });

        // Ресурсы
        // (аналогично абонентке за исключением группировки - группировать всегда)
        $this->out(PHP_EOL . 'Проводки за ресурсы');
        $this->runCalculateEntries($accountTariffId, function ($accountTariffId) {
            $this->calculateEntries(
                AccountLogResource::tableName(),
                'tariff_resource_id',
                'date_from',
                'date_to',
                $accountTariffId,
                $isSplitByMonths = true,
                $isGroupPerDayToMonth = true,
                'account_log.cost_price'
            );
        });

        // Минимальная плата
        // Транзакции группировать в проводки следующего месяца
        $this->out(PHP_EOL . 'Проводки за минимальную плату');
        $this->runCalculateEntries($accountTariffId, function ($accountTariffId) {
            $this->calculateEntries(
                AccountLogMin::tableName(),
                new Expression((string)AccountEntry::TYPE_ID_MIN),
                'date_from',
                'date_to',
                $accountTariffId,
                $isSplitByMonths = false,
                $isGroupPerDayToMonth = true
            );
        });

        // Расчёт НДС
        $this->out(PHP_EOL . 'Расчёт НДС');
        // $this->runCalculateEntries($accountTariffId, function ($accountTariffId) {
            $this->calculateVat($accountTariffId);
        // });

        $this->out(PHP_EOL);
    }

    /**
     * На основе новых транзакций создать проводки
     *
     * @param string $accountLogTableName
     * @param int|Expression $typeId
     * @param string $dateFieldNameFrom
     * @param string $dateFieldNameTo
     * @param int|null $accountTariffId Если указан, то только для этой услуги. Если не указан - для всех
     * @param bool $isSplitByMonths делить ли на прошлый/будущий месяц
     * @param bool $isGroupPerDayToMonth группировать ли посуточные транзакции в проводки по месяцам
     * @param int|float|string $costPrice Себестоимость. Значение и "account_log.поле"
     * @throws \yii\db\Exception
     */
    protected function calculateEntries($accountLogTableName, $typeId, $dateFieldNameFrom, $dateFieldNameTo, $accountTariffId, $isSplitByMonths, $isGroupPerDayToMonth, $costPrice = 0)
    {
        /** @var Connection $db */
        $db = Yii::$app->db;
        $accountEntryTableName = AccountEntry::tableName();
        $accountTariffTableName = AccountTariff::tableName();
        $clientAccountTableName = ClientAccount::tableName();
        if (!$accountTariffId) {
            $clientAccountTableName = 'clients_postpaid';
        }
        $sqlParams = [];

        $sqlAndWhere = '';
        if ($accountTariffId) {
            if (is_array($accountTariffId) && count($accountTariffId) == 2) {
                $sqlAndWhere .= ' AND account_log.account_tariff_id BETWEEN :account_tariff_id_from and :account_tariff_id_to';
                $sqlParams[':account_tariff_id_from'] = $accountTariffId[0];
                $sqlParams[':account_tariff_id_to'] = $accountTariffId[1];
            } else {
                $sqlAndWhere .= ' AND account_log.account_tariff_id = :account_tariff_id';
                $sqlParams[':account_tariff_id'] = $accountTariffId;
            }
        }

        if ($isSplitByMonths) {

            $isNextMonthSql = "(DATE_FORMAT(account_log.`{$dateFieldNameFrom}`, '%d') != '01'";
            if ($dateFieldNameTo !== $dateFieldNameFrom) {
                $isNextMonthSql .= " OR account_log.`{$dateFieldNameTo}` = account_log.`{$dateFieldNameFrom}`";
            }

            $isNextMonthSql .= ')';

        } else {
            $isNextMonthSql = 'true';
        }

        if ($isGroupPerDayToMonth) {
            $isGroupPerDayToMonthSql = "'%Y-%m-01'";
        } else {
            // если посуточно, то не группировать транзакции в проводки по месяцам, а оставлять 1-в-1
            $isGroupPerDayToMonthSql = "IF(account_log.`{$dateFieldNameTo}` = account_log.`{$dateFieldNameFrom}`, '%Y-%m-%d', '%Y-%m-01')";
        }

        // operation type
        $operationType = OperationType::getDefaultId();
        $tariffResourceTableName = '';
        $sqlResourceAndWhere = '';
        if ($accountLogTableName === AccountLogResource::tableName()) {
            // ресурсы
            // добавляем условие для operation_type_id
            $tariffResourceTableName = TariffResource::tableName();
            $tariffResourceTableName .= ' tariff_resource,';

            $operationType = '(CASE ';
            foreach (ResourceModel::$operationTypesMap as $resourceId => $operationTypeId) {
                $operationType .= ' WHEN tariff_resource.resource_id = ' . $resourceId . ' THEN ' . $operationTypeId;
            }
            $operationType .= ' ELSE ' . OperationType::getDefaultId() . ' END)';

            $sqlResourceAndWhere .= ' AND tariff_resource.id = account_log.tariff_resource_id';
        }

        // создать пустые проводки
        $this->out('. ');
        $insertSQL = <<<SQL
            INSERT INTO {$accountEntryTableName}
            (operation_type_id, date, account_tariff_id, type_id, price, is_next_month, tariff_period_id, date_from, date_to)
                SELECT DISTINCT
                    {$operationType} AS operation_type_id,
                    DATE_FORMAT(account_log.`{$dateFieldNameFrom}`, {$isGroupPerDayToMonthSql}) AS date,
                    account_log.account_tariff_id,
                    {$typeId} as type_id,
                    0 AS price,
                    IF(client_account.is_postpaid = 1 OR {$isNextMonthSql}, 1, 0) AS is_next_month,
                    account_log.tariff_period_id,
                    account_log.`{$dateFieldNameFrom}` AS date_from,
                    account_log.`{$dateFieldNameTo}` AS date_to
                FROM
                    {$accountLogTableName} account_log,
                    {$tariffResourceTableName}
                    {$accountTariffTableName} account_tariff,
                    {$clientAccountTableName} client_account
                WHERE
                    account_log.account_entry_id IS NULL
                    AND account_log.account_tariff_id = account_tariff.id
                    AND account_tariff.client_account_id = client_account.id
                    {$sqlResourceAndWhere}
                    {$sqlAndWhere}
            ON DUPLICATE KEY UPDATE price = 0
SQL;
        $db->createCommand($insertSQL, $sqlParams)
            ->execute();
        unset($insertSQL);

        // привязать транзакции к проводкам
        $this->out('. ');
        $updateSql = <<<SQL
            UPDATE
                {$accountEntryTableName} account_entry,
                {$accountLogTableName} account_log,
                {$tariffResourceTableName}
                {$accountTariffTableName} account_tariff,
                {$clientAccountTableName} client_account
            SET
                account_log.account_entry_id = account_entry.id
            WHERE
                account_log.account_entry_id IS NULL
                AND account_entry.is_next_month = IF(client_account.is_postpaid = 1 OR {$isNextMonthSql}, 1, 0)
                AND account_entry.date = DATE_FORMAT(account_log.`{$dateFieldNameFrom}`, {$isGroupPerDayToMonthSql})
                AND account_entry.type_id = {$typeId}
                AND account_entry.account_tariff_id = account_log.account_tariff_id
                AND account_entry.tariff_period_id = account_log.tariff_period_id
                AND account_log.account_tariff_id = account_tariff.id
                AND account_entry.operation_type_id = {$operationType}
                AND account_tariff.client_account_id = client_account.id
                {$sqlResourceAndWhere}
                {$sqlAndWhere}
SQL;
        $db->createCommand($updateSql, $sqlParams)
            ->execute();
        unset($updateSql);

        // пересчитать стоимость проводок
        $this->out('. ');
        $updateSql = <<<SQL
            UPDATE
                {$accountEntryTableName} account_entry,
                (
                    SELECT
                        account_log.account_entry_id,
                        SUM(account_log.price) AS price,
                        MIN(`{$dateFieldNameFrom}`) AS date_from,
                        MAX(`{$dateFieldNameTo}`) AS date_to,
                        SUM({$costPrice}) AS cost_price
                    FROM
                        {$accountLogTableName} account_log
                    WHERE
                        TRUE
                        {$sqlAndWhere}
                    GROUP BY
                       account_log.account_entry_id
                ) t
            SET
                account_entry.price = t.price,
                account_entry.cost_price = t.cost_price,
                account_entry.date_from = t.date_from,
                account_entry.date_to = t.date_to
            WHERE
                account_entry.id = t.account_entry_id
SQL;
        $db->createCommand($updateSql, $sqlParams)
            ->execute();
        unset($updateSql);
    }

    /**
     * Посчитать НДС
     *
     * Алгоритм расчета ставки НДС:
     *  Только для проводок, где НДС еще не посчитан.
     *  Взять дату НАЧАЛА проводки с точностью до суток.
     *  Найти контракт клиента, действующий на эту дату.
     *  Найти НДС с помощью ClientContract::dao()->getEffectiveVATRate($contract)
     *
     * Выводы:
     *  Месячная абонентка будет по НДС на 1е число, ресурсы и суточная абонентка по НДС на конкретную дату.
     *  Дата запуска скрипта не имеет значения.
     *  Пред/постоплата, физик/юрик, с/без НДС не имеет значения.
     *  Смена организации или НДС организации возможна в любое время, но рекомендуется это делать с 1 числа месяца.
     *  Смена организации или НДС задним числом не имеет смысла и не будет учтена.
     *
     * @param int|null $accountTariffId Если указан, то только для этой услуги. Если не указан - для всех
     * @throws \yii\db\Exception
     */
    public function calculateVat($accountTariffId)
    {
        /** @var Connection $db */
        $db = Yii::$app->db;
        $accountEntryTableName = AccountEntry::tableName();

        if ($accountTariffId) {
            if (is_array($accountTariffId) && count($accountTariffId) == 2) {
                $sqlAndWhere = ' AND account_entry.account_tariff_id BETWEEN ' . $accountTariffId[0] . ' AND ' . $accountTariffId[1];
            } else {
                $sqlAndWhere = ' AND account_entry.account_tariff_id = ' . $accountTariffId;
            }
        } else {
            $sqlAndWhere = '';
        }

        // посчитать ставку НДС для юр.лиц
        $this->out('. ');
        $accountTariffTableName = AccountTariff::tableName();
        $selectSql = <<<SQL
        SELECT
            account_entry.id as account_entry_id, 
            account_entry.date_from as date, 
            account_tariff.client_account_id,
            account_entry.tariff_period_id
        FROM
            {$accountEntryTableName} account_entry,
            {$accountTariffTableName} account_tariff
        WHERE
            account_entry.account_tariff_id = account_tariff.id
            AND account_entry.vat_rate IS NULL
            {$sqlAndWhere}
SQL;

        $tariffRates = $this->getTariffTaxRates();
        $tariffAgentRates = $this->getTariffAgentTaxRates();

        $query = $db->createCommand($selectSql)->query();

        $countAll = $query->count();

        $clientCache = []; // [client_account_id => ClientAccount]
        $clientDateVatCache = []; // [{client_account_id}_{date} => VAT]
        $organisationAgentCache = [];
        $count = 0;
        foreach ($query as $row) {
            if (($count % 100) == 0) {
                echo "\r[ " . str_pad($count . ' / ' . $countAll . ' => ' . round($count / ($countAll / 100)) . '% ', 30, '.') . ']';
            }
            $count++;
            $vatRate = null;

            $clientKey = $row['client_account_id'];
            $clientAccount = $clientCache[$clientKey] ?? ClientAccount::findOne(['id' => $row['client_account_id']]);
            $contract = $clientAccount->clientContractModel;

            // специальная папка "Госники - 20% НДС"
            if ($contract->business_process_status_id == BusinessProcessStatus::TELEKOM_MAINTENANCE_GOVERNMENT_AGENCIES) {
//                $vatRate = 20;
                $contract = $clientAccount->getContract($row['date']);

                $clientDateVatKey = $row['client_account_id'] . '_' . $row['date'];
                $clientDateVatCache[$clientDateVatKey] = ClientContract::dao()->getEffectiveVATRate($contract, $row['date']);
                $vatRate = $clientDateVatCache[$clientDateVatKey];
            }

            // В тарифе установлен агентский НДС
            if ($vatRate === null && isset($tariffAgentRates[$row['tariff_period_id']])) {

                $contract = $clientAccount->getContract($row['date']);

                // состояние организации на дату
                $key = $contract->organization_id . '-' . $row['date'];
                if (!isset($organisationAgentCache[$key])) {
                    $organisationAgentCache[$key] = $contract->getOrganization($row['date'])->is_agent_tax_rate;
                }

                // включена ли Агентская система НДС
                if ($organisationAgentCache[$key]) {
                    $vatRate = $tariffAgentRates[$row['tariff_period_id']];
                }
            }

            if ($vatRate === null) {
                if (isset($tariffRates[$row['tariff_period_id']])) {
                    $vatRate = $tariffRates[$row['tariff_period_id']];
                } else {
                    $clientKey = $row['client_account_id'];
                    $clientDateVatKey = $row['client_account_id'] . '_' . $row['date'];
                    if (!array_key_exists($clientDateVatKey, $clientDateVatCache)) { // isset() быстрее, но нам надо учитывать значение null
                        // Посчитать НДС и записать в кэш

                        $clientAccount = $clientCache[$clientKey] ?? ClientAccount::findOne(['id' => $row['client_account_id']]);
                        $contract = $clientAccount->getContract($row['date']);

                        $clientDateVatCache[$clientDateVatKey] = ClientContract::dao()->getEffectiveVATRate($contract, $row['date']);
                    }
                    $vatRate = $clientDateVatCache[$clientDateVatKey];
                }
            }

            $updateSql = <<<SQL
        UPDATE {$accountEntryTableName}
        SET vat_rate = :vat_rate
        WHERE id = :id
SQL;
            $db->createCommand($updateSql, [
                ':id' => $row['account_entry_id'],
                ':vat_rate' => $vatRate,
            ])
                ->execute();
            unset($updateSql);
        }
        unset($selectSql, $updateSql, $clientCache, $clientDateVatCache);

        // посчитать цену без НДС
        $tariffPeriodTableName = TariffPeriod::tableName();
        $tariffTableName = Tariff::tableName();
        $tariffResourceTableName = TariffResource::tableName();
        $resourceIdCalls = implode(', ', ResourceModel::$calls); // стоимость звонков от низкоуровневого биллера уже приходит с НДС

        // нужно знать is_include_vat из тарифа, а это можно получить только через транзакции
        // @todo Может быть несколько транзакций на одну проводку.
        // @todo Будет лишнее обновление, но на значения это не влияет
        $accountLogs = [
            AccountLogSetup::tableName() => 'account_entry.type_id = ' . AccountEntry::TYPE_ID_SETUP,
            AccountLogPeriod::tableName() => 'account_entry.type_id = ' . AccountEntry::TYPE_ID_PERIOD,
            AccountLogMin::tableName() => 'account_entry.type_id = ' . AccountEntry::TYPE_ID_MIN,
            AccountLogResource::tableName() => 'account_entry.type_id > 0',
        ];
        foreach ($accountLogs as $accountLogTableName => $sqlAndWhereTmp) {
            $this->out('. ');
            $updateSql = <<<SQL
        UPDATE
            (
            {$accountEntryTableName} account_entry,
            {$accountLogTableName} account_log,
            {$tariffPeriodTableName} tariff_period,
            {$tariffTableName} tariff
            )
        LEFT JOIN
            {$tariffResourceTableName} tariff_resource
            ON account_entry.type_id = tariff_resource.id
        SET
            account_entry.price_without_vat = IF(
                tariff.is_include_vat OR (tariff_resource.resource_id IS NOT NULL AND tariff_resource.resource_id IN ({$resourceIdCalls}) AND account_entry.date < '2018-01-01'),
                account_entry.price * 100 / (100 + account_entry.vat_rate),
                account_entry.price
               )
        WHERE
            account_entry.vat_rate IS NOT NULL
            AND account_entry.id = account_log.account_entry_id
            AND account_log.tariff_period_id = tariff_period.id
            AND tariff_period.tariff_id = tariff.id
            {$sqlAndWhere}
SQL;
//            $db->createCommand($updateSql)
//                ->execute();
            unset($updateSql);
        }


        $updateSql = <<<SQL
UPDATE
    (
        {$accountEntryTableName} account_entry,
            {$tariffPeriodTableName} tariff_period,
            {$tariffTableName} tariff
        )
        LEFT JOIN
        {$tariffResourceTableName} tariff_resource
        ON account_entry.type_id = tariff_resource.id
SET account_entry.price_without_vat = IF(
        tariff.is_include_vat,
        round(account_entry.price * 100 / (100 + account_entry.vat_rate), 4),
        account_entry.price
    )
WHERE account_entry.vat_rate IS NOT NULL
  AND account_entry.tariff_period_id = tariff_period.id
  AND tariff_period.tariff_id = tariff.id
  and account_entry.date >= '2025-01-01'
  and (
        account_entry.price_without_vat IS NULL
            OR account_entry.price_without_vat != IF(
                tariff.is_include_vat,
                round(account_entry.price * 100 / (100 + account_entry.vat_rate), 4),
                account_entry.price
            )
    )
    {$sqlAndWhere}
SQL;

            $db->createCommand($updateSql)
                ->execute();
            unset($updateSql);


        // посчитать НДС и цену с НДС для юр.лиц
        $this->out('. ');
        $updateSql = <<<SQL
        UPDATE
            {$accountEntryTableName} account_entry
        SET
            vat = price_without_vat * vat_rate / 100,
            price_with_vat = price_without_vat * (100 + vat_rate) / 100
        WHERE
            price_without_vat IS NOT NULL
            and account_entry.date >= '2025-01-01'
            {$sqlAndWhere}
SQL;
        $db->createCommand($updateSql)
            ->execute();
        unset($updateSql);
    }

    /**
     * Получаем карту tariffPeriod и ставки НДС по нему
     * @return array
     */
    private function getTariffTaxRates()
    {
        static $res = [];

        if ($res) {
            return $res;
        }

        $res = Tariff::find()
            ->alias('t')
            ->innerJoinWith('tariffPeriods tp')
            ->where(['not', ['t.tax_rate' => null]])
            ->select(['t.tax_rate', 'tp.id'])
            ->indexBy('id')
            ->column();

        return $res;
    }

    /**
     * Получаем карту tariffPeriod и ставки Агенского НДС
     *
     * @return array
     */
    private function getTariffAgentTaxRates()
    {
        static $res = [];

        if ($res) {
            return $res;
        }

        $res = Tariff::find()
            ->alias('t')
            ->innerJoinWith('tariffPeriods tp')
            ->where(['not', ['t.agent_tax_rate' => null]])
            ->select(['t.agent_tax_rate', 'tp.id'])
            ->indexBy('id')
            ->column();

        return $res;
    }
}
