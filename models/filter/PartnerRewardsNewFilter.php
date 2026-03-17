<?php

namespace app\models\filter;

use app\classes\DynamicModel;
use app\models\Bill;
use app\models\BillLine;
use app\models\ClientAccount;
use app\models\ClientContract;
use app\models\ClientContragent;
use app\models\Organization;
use app\models\rewards\RewardBillLine;
use app\models\rewards\RewardClientContractService;
use DateTimeImmutable;
use Yii;
use yii\data\ArrayDataProvider;
use yii\db\Expression;
use yii\db\Query;

class PartnerRewardsNewFilter extends DynamicModel
{
    private const MONEY_SCALE = 2;

    public
        $partner_contract_id,
        $payment_date_before,
        $payment_date_after,
        $show_zero_rewards,
        $isExtendsMode;

    public
        $contractsWithoutRewardSettings = [],
        $contractsWithIncorrectBusinessProcess = [],
        $summary = [],
        $possibleSummary = [];

    /**
     * @return array
     */
    public function rules()
    {
        return [
            [['payment_date_before', 'payment_date_after'], 'string'],
            [['partner_contract_id',], 'integer'],
            [['show_zero_rewards'], 'boolean'],
        ];
    }

    /**
     * @param bool $isExtendsMode
     */
    public function __construct($isExtendsMode = true)
    {
        parent::__construct();
        $this->isExtendsMode = $isExtendsMode;
    }

    /**
     * @return $this
     */
    public function load()
    {
        parent::load(Yii::$app->request->get(), 'filter');
        $this->show_zero_rewards = (bool)$this->show_zero_rewards;
        return $this;
    }

    /**
     * @return bool|ArrayDataProvider
     */
    public function search()
    {
        if ($this->partner_contract_id == '') {
            return false;
        }

        $this->summary = $this->getEmptySummary();
        $this->possibleSummary = $this->getEmptySummary();

        $data = $this->getPartnerClients();
        if (!$data) {
            return new ArrayDataProvider([
                'allModels' => [],
                'sort' => false,
                'pagination' => false,
            ]);
        }

        $query = new Query;
        $actual_from = <<<SQL
CASE
   WHEN line.service = 'uu_account_tariff' THEN
     (
       SELECT convert(MIN(uu_account_tariff_log_inner.actual_from_utc), DATE)
       FROM uu_account_tariff uu_account_tariff_inner
        INNER JOIN uu_account_tariff_log uu_account_tariff_log_inner
          ON uu_account_tariff_log_inner.account_tariff_id = uu_account_tariff_inner.id
       WHERE uu_account_tariff_inner.id = line.id_service
     )
   END
SQL;
        $query->select([
            'rewards.*',
            'client_id' => 'client.id',
            'client_created' => 'client.created',
            'client.account_version',
            'contragent_name' => 'contragent.name',
            'bill_no' => 'bills.bill_no',
            'bill_paid' => 'bills.is_payed',
            'paid_summary' => 'bills.sum',
            'payment_date' => 'bills.payment_date',
            'usage_type' => 'line.service',
            'usage_id' => 'line.id_service',
            'usage_paid' => 'line.sum',
            'description' => 'line.item',
            'actual_from' => $actual_from,
        ]);

        // Определение источника генерации партнерского вознаграждения
        $partnerRewardsTableName = RewardBillLine::tableName();

        $query
            ->from(['rewards' => $partnerRewardsTableName])
            ->innerJoin(['bills' => Bill::tableName()], 'bills.id = rewards.bill_id')
            ->innerJoin(['client' => ClientAccount::tableName()], 'client.id = bills.client_id')
            ->innerJoin(['contract' => ClientContract::tableName()], 'contract.id = client.contract_id')
            ->innerJoin(['contragent' => ClientContragent::tableName()], 'contragent.id = contract.contragent_id')
            ->innerJoin(['line' => BillLine::tableName()], 'line.pk = rewards.bill_line_pk');

        $query
            ->andWhere(['contract.partner_contract_id' => $this->partner_contract_id])
            ->andWhere(['>=', 'line.sum', 0]);

        if (!$this->isExtendsMode) {
            $query->andWhere(['bills.is_payed' => Bill::STATUS_IS_PAID]);
        }

        if ($this->payment_date_before !== '') {
            $query->andWhere(['>=', new Expression('DATE_FORMAT(bills.payment_date, "%Y-%m")'), $this->payment_date_before]);
        }

        if ($this->payment_date_after !== '') {
            $query->andWhere(['<=', new Expression('DATE_FORMAT(bills.payment_date, "%Y-%m")'), $this->payment_date_after]);
        }

        $query->orderBy('contragent_name');

        $data = $this->_prepareData($query, $data);
        $this->applyClientDiagnostics($data);
        $data = $this->filterZeroRewardRows($data);
        $this->recalculateSummaries($data);

        $dataProvider = new ArrayDataProvider([
            'allModels' => array_values($data),
            'sort' => false,
            'pagination' => false,
        ]);

        return $dataProvider;
    }

    /**
     * @param Query $query
     * @param array $data
     * @return array
     */
    private function _prepareData(Query $query, array $data)
    {
        $buffer = [];
        foreach ($query->each(1000) as $record) {
            if ($record['sum'] == 0) {
                continue;
            }
            if (!array_key_exists($record['client_id'], $data)) {
                $data[$record['client_id']] = $this->createClientRow($record);
            }  

            $fieldPrefix = '';
            if ($this->isExtendsMode) {
                if ((int)$record['bill_paid'] !== Bill::STATUS_IS_PAID) {
                    $fieldPrefix = 'possible_';
                }
            }
            $data[$record['client_id']][$fieldPrefix . 'paid_summary_reward'] += $record['usage_paid'];
            $data[$record['client_id']]['details'][] = $record;

            $data[$record['client_id']][$fieldPrefix . 'sum'] += $record['sum'];
            $data[$record['client_id']]['has_reward_rows'] = true;

            // Расчет итоговых суммы для каждого клиента, который может иметь более одного счета.
            // Суммирование происходит по зараннее рассчитанному столбцу `sum` из таблицы `newbills`
            if (!isset($buffer['local'][$record['client_id']][$record['bill_id']])) {
                $buffer['local'][$record['client_id']][$record['bill_id']] = $record['bill_id'];
                $data[$record['client_id']][$fieldPrefix . 'paid_summary'] += $record['paid_summary'];
            }
        }
        unset($buffer);

        return $data;
    }

    /**
     * Базовая выборка для отчета: берем всех клиентов партнера, даже если по ним
     * еще не было рассчитано ни одной строки вознаграждения.
     *
     * @return array
     */
    private function getPartnerClients()
    {
        $query = new Query();
        $query
            ->select([
                'client_id' => 'client.id',
                'client_created' => 'client.created',
                'contragent_name' => 'contragent.name',
            ])
            ->from(['client' => ClientAccount::tableName()])
            ->innerJoin(['contract' => ClientContract::tableName()], 'contract.id = client.contract_id')
            ->innerJoin(['contragent' => ClientContragent::tableName()], 'contragent.id = contract.contragent_id')
            ->where(['contract.partner_contract_id' => $this->partner_contract_id])
            ->orderBy(['contragent.name' => SORT_ASC]);

        $data = [];
        foreach ($query->all() as $record) {
            $data[$record['client_id']] = $this->createClientRow($record);
        }

        return $data;
    }

    /**
     * Подготавливает пустую строку отчета по клиенту, которую затем можно
     * заполнить либо рассчитанными данными, либо диагностикой отсутствия начислений.
     *
     * @param array $record
     * @return array
     */
    private function createClientRow(array $record)
    {
        return [
            'client_id' => $record['client_id'],
            'contragent_name' => $record['contragent_name'],
            'client_created' => $record['client_created'],
            'paid_summary_reward' => 0.0,
            'paid_summary' => 0.0,
            'sum' => 0.0,
            'possible_paid_summary_reward' => 0.0,
            'possible_paid_summary' => 0.0,
            'possible_sum' => 0.0,
            'details' => [],
            'issues' => [],
            'has_reward_rows' => false,
            'paid_bills_count' => 0,
        ];
    }

    /**
     * Добавляет к каждой строке клиента объяснение, почему начисления отсутствуют:
     * нет настроек, нет оплат за период, дата начала настроек позже оплат и т.д.
     *
     * @param array $data
     */
    private function applyClientDiagnostics(array &$data)
    {
        $paidBillsByClient = $this->getPaidBillsByClient();
        $settingsMeta = $this->getRewardSettingsMeta();
        $hasSettings = $settingsMeta['settings_count'] > 0;
        $hasValidSettings = $settingsMeta['valid_settings_count'] > 0;

        foreach ($data as $clientId => &$row) {
            $row['issues'] = [];
            $billStats = $paidBillsByClient[$clientId] ?? null;
            if ($billStats) {
                $row['paid_bills_count'] = (int)$billStats['bill_count'];
                if (!$row['has_reward_rows']) {
                    $row['paid_summary'] = (float)$billStats['paid_summary'];
                }
            }

            if (!$hasSettings) {
                $row['issues'][] = 'Не заданы параметры вознаграждения v3';
                continue;
            }

            if (!$hasValidSettings) {
                $row['issues'][] = 'Некорректно заполнена дата начала в параметрах вознаграждения';
                continue;
            }

            if (!$billStats) {
                $row['issues'][] = $this->hasDateFilter()
                    ? 'Нет оплаченных счетов за выбранный период'
                    : 'Нет оплаченных счетов';
                continue;
            }

            if ($row['has_reward_rows']) {
                continue;
            }

            if (
                $settingsMeta['min_actual_from']
                && $billStats['last_payment_date']
                && $billStats['last_payment_date'] < $settingsMeta['min_actual_from']
            ) {
                $row['issues'][] = 'Дата начала настроек вознаграждения позже даты оплаты счетов';
                continue;
            }

            $row['issues'][] = 'По оплаченным счетам вознаграждение не рассчитано или нет подходящих строк услуг';
        }
        unset($row);
    }

    /**
     * По умолчанию отчет скрывает строки, в которых отображаемая сумма
     * вознаграждения равна нулю. Отдельная галка позволяет вернуть такие
     * строки обратно в выборку и увидеть полную связку партнер -> клиент.
     *
     * @param array $data
     * @return array
     */
    private function filterZeroRewardRows(array $data)
    {
        if ($this->show_zero_rewards) {
            return $data;
        }

        return array_filter($data, function ($row) {
            return abs((float)$row['sum']) > 0.00001;
        });
    }

    /**
     * Собирает по каждому клиенту агрегаты по реально оплаченным счетам.
     * Эти данные нужны, чтобы показывать связку партнер -> клиент даже без reward-строк.
     *
     * @return array
     */
    private function getPaidBillsByClient()
    {
        $query = new Query();
        $query
            ->select([
                'client_id' => 'client.id',
                'bill_count' => 'COUNT(DISTINCT bills.id)',
                'paid_summary' => 'SUM(bills.sum)',
                'last_payment_date' => 'MAX(bills.payment_date)',
            ])
            ->from(['client' => ClientAccount::tableName()])
            ->innerJoin(['contract' => ClientContract::tableName()], 'contract.id = client.contract_id')
            ->innerJoin(['bills' => Bill::tableName()], 'bills.client_id = client.id')
            ->where([
                'contract.partner_contract_id' => $this->partner_contract_id,
                'bills.is_payed' => Bill::STATUS_IS_PAID,
            ]);

        $this->applyPaymentDateFilter($query, 'bills.payment_date');

        $query->groupBy('client.id');

        $result = [];
        foreach ($query->all() as $record) {
            $result[$record['client_id']] = $record;
        }

        return $result;
    }

    /**
     * Возвращает краткую сводку по настройкам v3 партнера:
     * есть ли они вообще и есть ли среди них записи с корректной датой начала.
     *
     * @return array
     */
    private function getRewardSettingsMeta()
    {
        $query = new Query();
        $validActualFrom = "CASE WHEN actual_from IS NOT NULL AND actual_from <> '0000-00-00' THEN actual_from END";
        $meta = $query
            ->select([
                'settings_count' => 'COUNT(*)',
                'valid_settings_count' => "COUNT($validActualFrom)",
                'min_actual_from' => "MIN($validActualFrom)",
            ])
            ->from(RewardClientContractService::tableName())
            ->where(['client_contract_id' => $this->partner_contract_id])
            ->one();

        return $meta ?: [
            'settings_count' => 0,
            'valid_settings_count' => 0,
            'min_actual_from' => null,
        ];
    }

    /**
     * После заполнения клиентских строк пересчитывает итоговые суммы отчета,
     * включая клиентов без начислений, которые теперь тоже отображаются в гриде.
     *
     * @param array $data
     */
    private function recalculateSummaries(array $data)
    {
        $this->summary = $this->getEmptySummary();
        $this->possibleSummary = $this->getEmptySummary();

        foreach ($data as $row) {
            $this->summary['paid_summary'] += (float)$row['paid_summary'];
            $this->summary['paid_summary_reward'] += (float)$row['paid_summary_reward'];
            $this->summary['sum'] += (float)$row['sum'];

            $this->possibleSummary['paid_summary'] += (float)$row['possible_paid_summary'];
            $this->possibleSummary['paid_summary_reward'] += (float)$row['possible_paid_summary_reward'];
            $this->possibleSummary['sum'] += (float)$row['possible_sum'];
        }
    }

    /**
     * Нулевая структура итогов, чтобы безопасно накапливать суммы без проверок на isset.
     *
     * @return array
     */
    private function getEmptySummary()
    {
        return [
            'paid_summary' => 0.0,
            'paid_summary_reward' => 0.0,
            'sum' => 0.0,
        ];
    }

    /**
     * Отдельная проверка нужна для текста диагностики: если пользователь задал период,
     * причина должна явно говорить, что оплаченных счетов нет именно за выбранные месяцы.
     *
     * @return bool
     */
    private function hasDateFilter()
    {
        return $this->payment_date_before !== '' || $this->payment_date_after !== '';
    }

    /**
     * Единая логика применения month-фильтра к разным запросам отчета,
     * чтобы выборка начислений и выборка оплаченных счетов работали одинаково.
     *
     * @param Query $query
     * @param string $column
     */
    private function applyPaymentDateFilter(Query $query, $column)
    {
        if ($this->payment_date_before !== '') {
            $query->andWhere(['>=', new Expression(sprintf('DATE_FORMAT(%s, "%%Y-%%m")', $column)), $this->payment_date_before]);
        }

        if ($this->payment_date_after !== '') {
            $query->andWhere(['<=', new Expression(sprintf('DATE_FORMAT(%s, "%%Y-%%m")', $column)), $this->payment_date_after]);
        }
    }

    /**
     * Публичная проверка готовности документного экспорта:
     * нужен партнер, обе даты периода и отсутствие уже сформированного партнерского счета.
     *
     * @return string|null
     */
    public function getDocumentExportIssue()
    {
        if (!$this->partner_contract_id) {
            return 'Не выбран партнер.';
        }

        if ($this->payment_date_before === '' || $this->payment_date_after === '') {
            return 'Для документного экспорта укажите период оплаты "с" и "по".';
        }

        $period = $this->getDocumentPeriodRange();
        if (!$period) {
            return 'Некорректно задан расчетный период.';
        }

        if ($period['from'] > $period['to']) {
            return 'Дата начала периода не может быть позже даты окончания.';
        }

        if ($closedBill = $this->getClosedDocumentBill()) {
            return sprintf(
                'Период %s уже закрыт счетом %s (%s).',
                $this->getDocumentPeriodText(),
                $closedBill['bill_no'],
                Bill::$paidStatuses[$closedBill['is_payed']] ?? 'неизвестный статус'
            );
        }

        return null;
    }

    /**
     * Нормализованный период документа:
     * первая дата месяца "с" и последняя дата месяца "по".
     *
     * @return array|null
     */
    public function getDocumentPeriodRange()
    {
        if ($this->payment_date_before === '' || $this->payment_date_after === '') {
            return null;
        }

        $from = DateTimeImmutable::createFromFormat('Y-m-d', $this->payment_date_before . '-01');
        $to = DateTimeImmutable::createFromFormat('Y-m-d', $this->payment_date_after . '-01');
        if (!$from || !$to) {
            return null;
        }

        return [
            'from' => $from,
            'to' => $to->modify('last day of this month'),
            'toExclusive' => $to->modify('first day of next month'),
        ];
    }

    /**
     * Строка периода для шапки документа и текстов ошибок.
     *
     * @return string
     */
    public function getDocumentPeriodText()
    {
        $period = $this->getDocumentPeriodRange();
        if (!$period) {
            return '';
        }

        return sprintf(
            'с %s по %s',
            $period['from']->format('d.m.Y'),
            $period['to']->format('d.m.Y')
        );
    }

    /**
     * Имя партнера для шапки документа и имени файла.
     *
     * @return string
     */
    public function getPartnerName()
    {
        $contract = $this->getPartnerContract();
        if (!$contract) {
            return '#' . $this->partner_contract_id;
        }

        return $contract->contragent->name;
    }

    /**
     * Организация-оператор берется из договора партнера на текущую дату.
     *
     * @return Organization|null
     */
    public function getOperatorOrganization()
    {
        $contract = $this->getPartnerContract();
        if (!$contract) {
            return null;
        }

        return $contract->organization;
    }

    /**
     * Имя файла документа без расширения.
     *
     * @return string
     */
    public function getDocumentFileName()
    {
        $period = $this->getDocumentPeriodRange();
        $suffix = $period
            ? $period['from']->format('Ym') . '_' . $period['to']->format('Ym')
            : date('Ymd');

        return sprintf(
            'partner_rewards_%s_%s',
            preg_replace('/[^A-Za-z0-9_]+/', '_', (string)$this->partner_contract_id),
            $suffix
        );
    }

    /**
     * Признак того, что документный экспорт можно запускать без дополнительных блокировок.
     *
     * @return bool
     */
    public function canExportDocument()
    {
        return $this->getDocumentExportIssue() === null;
    }

    /**
     * Ищет уже сформированный партнерский счет за выбранный диапазон.
     * Используем строки самого партнерского счета с period date_from/date_to как источник истины.
     *
     * @return array|null
     */
    public function getClosedDocumentBill()
    {
        $period = $this->getDocumentPeriodRange();
        if (!$period || !$this->partner_contract_id) {
            return null;
        }

        $query = new Query();
        return $query
            ->select([
                'bills.bill_no',
                'bills.is_payed',
                'bills.payment_date',
            ])
            ->from(['bills' => Bill::tableName()])
            ->innerJoin(['client' => ClientAccount::tableName()], 'client.id = bills.client_id')
            ->innerJoin(['line' => BillLine::tableName()], 'line.bill_no = bills.bill_no')
            ->where([
                'client.contract_id' => $this->partner_contract_id,
                'line.type' => BillLine::LINE_TYPE_SERVICE,
                'line.date_from' => $period['from']->format('Y-m-d'),
                'line.date_to' => $period['to']->format('Y-m-d'),
            ])
            ->andWhere(['<', 'line.sum', 0])
            ->andWhere([
                'or',
                ['like', 'line.item', 'Агентское вознаграждение', false],
                ['like', 'line.item', 'partner_reward', false],
            ])
            ->orderBy(['bills.id' => SORT_DESC])
            ->one();
    }

    /**
     * Данные, которые использует документный экспорт:
     * шапка, строки, итоги и подписи оператора/агента.
     *
     * @return array
     */
    public function getDocumentData()
    {
        $dataProvider = $this->search();
        $organization = $this->getOperatorOrganization();

        return [
            'partnerName' => $this->getPartnerName(),
            'periodText' => $this->getDocumentPeriodText(),
            'rows' => $dataProvider ? $dataProvider->allModels : [],
            'summary' => $this->summary,
            'operatorOrganizationName' => $organization ? $organization->name : 'Оператор',
            'operatorDirectorPost' => ($organization && $organization->director)
                ? $organization->director->post_nominative
                : 'Оператор',
            'operatorDirectorName' => ($organization && $organization->director)
                ? $organization->director->name_nominative
                : '',
        ];
    }

    /**
     * Договор партнера нужен в нескольких сценариях: имя в шапке, организация оператора, guard экспорта.
     *
     * @return ClientContract|null
     */
    private function getPartnerContract()
    {
        if (!$this->partner_contract_id) {
            return null;
        }

        return ClientContract::findOne(['id' => $this->partner_contract_id]);
    }

    /**
     * Функция форматирования цены, требуемая в том числе и при экспорте отчета
     *
     * @param $price
     * @param int $scale
     * @return string
     */
    public static function getNumberFormat($price, $scale = self::MONEY_SCALE)
    {
        return number_format((float)$price, $scale, ',', ' ');
    }
}
