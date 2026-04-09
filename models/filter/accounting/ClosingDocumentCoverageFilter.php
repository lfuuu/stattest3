<?php

namespace app\models\filter\accounting;

use app\helpers\DateTimeZoneHelper;
use app\models\Bill;
use app\models\BillLine;
use app\models\ClientAccount;
use app\models\ClientAccountOptions;
use DateTimeImmutable;
use DateTimeZone;
use Yii;
use yii\base\Model;
use yii\data\ArrayDataProvider;
use yii\data\SqlDataProvider;
use yii\db\Expression;
use yii\db\Query;

class ClosingDocumentCoverageFilter extends Model
{
    public const TYPE_OF_BILL_ALL = 'all';
    public const TYPE_OF_BILL_SIMPLE = 'simple';
    public const TYPE_OF_BILL_DETAILED = 'detailed';

    public $month = '';
    public $type_of_bill = self::TYPE_OF_BILL_ALL;

    public function rules()
    {
        return [
            [['month'], 'required'],
            [['month'], 'match', 'pattern' => '/^\d{4}-\d{2}$/'],
            [['type_of_bill'], 'in', 'range' => [
                self::TYPE_OF_BILL_ALL,
                self::TYPE_OF_BILL_SIMPLE,
                self::TYPE_OF_BILL_DETAILED,
            ]],
        ];
    }

    public static function getTypeOfBillList(): array
    {
        return [
            self::TYPE_OF_BILL_ALL => 'Все',
            self::TYPE_OF_BILL_SIMPLE => 'Простой',
            self::TYPE_OF_BILL_DETAILED => 'Полный',
        ];
    }

    public function load($data = null, $formName = null)
    {
        $requestData = $data ?? Yii::$app->request->get();

        if (empty(($requestData[$this->formName()] ?? [])['month'])) {
            $currentMonthStart = new DateTimeImmutable('now', new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
            $requestData[$this->formName()]['month'] = $currentMonthStart->format('Y-m');
        }

        parent::load($requestData, $formName);

        return $this;
    }

    public function getSummary(): array
    {
        $missingQuery = $this->buildMissingQuery();
        $groupedBillsQuery = $this->buildGroupedMissingBillsQuery();

        return [
            'processed_line_count' => (int)$this->buildProcessedQuery()
                ->select(new Expression('COUNT(*)'))
                ->orderBy([])
                ->scalar(),
            'missing_upd_line_count' => (int)(clone $missingQuery)
                ->select(new Expression('COUNT(*)'))
                ->orderBy([])
                ->scalar(),
            'bill_count' => (int)(new Query())
                ->from(['grouped_bills' => $groupedBillsQuery])
                ->count('*'),
            'client_count' => (int)(new Query())
                ->from([
                    'grouped_clients' => $this->buildMissingQuery()
                        ->select(['nb.client_id'])
                        ->groupBy(['nb.client_id']),
                ])
                ->count('*'),
        ];
    }

    public function getDataProvider(): SqlDataProvider
    {
        $query = $this->buildGroupedMissingBillsQuery();

        $totalCount = (int)(new Query())
            ->from(['grouped_bills' => $query])
            ->count('*');

        return new SqlDataProvider([
            'sql' => $query->createCommand()->rawSql,
            'totalCount' => $totalCount,
            'key' => 'bill_no',
            'pagination' => [
                'pageSize' => 100,
            ],
            'sort' => false,
        ]);
    }

    public function getBillDetails(string $billNo): ArrayDataProvider
    {
        $rows = $this->buildMissingQuery()
            ->select([
                'line_pk' => 'nbl.pk',
                'item' => 'nbl.item',
                'sum' => 'nbl.sum',
                'date_from' => 'nbl.date_from',
                'date_to' => 'nbl.date_to',
            ])
            ->andWhere(['nbl.bill_no' => $billNo])
            ->orderBy([
                'nbl.pk' => SORT_ASC,
            ])
            ->all();

        return new ArrayDataProvider([
            'allModels' => $rows,
            'pagination' => false,
            'sort' => false,
        ]);
    }

    private function buildProcessedQuery(): Query
    {
        $query = (new Query())
            ->from(['nbl' => BillLine::tableName()])
            ->innerJoin(['nb' => Bill::tableName()], 'nb.bill_no = nbl.bill_no')
            ->innerJoin(['client' => ClientAccount::tableName()], 'client.id = nb.client_id')
            ->leftJoin(
                ['cao' => ClientAccountOptions::tableName()],
                "cao.client_account_id = nb.client_id AND cao.option = '" . ClientAccountOptions::OPTION_UPLOAD_TO_SALES_BOOK . "'"
            )
            ->where(['not', ['nb.uu_bill_id' => null]])
            ->andWhere(['>=', 'nb.bill_date', $this->getBillDateFrom()])
            ->andWhere(['<', 'nb.bill_date', $this->getBillDateToExclusive()])
            ->andWhere(['nbl.type' => 'service'])
            ->andWhere(['=', new Expression("COALESCE(cao.value, '0')"), '1'])
            ->andWhere(['>', 'nbl.sum', 0])
            ->andWhere(['client.price_level' => 1])
            ->andWhere(['>=', 'nbl.date_from', $this->getServiceDateFrom()])
            ->andWhere(['<', 'nbl.date_from', $this->getServiceDateToExclusive()]);

        $normalizedTypeOfBill = $this->normalizeTypeOfBill();
        if ($normalizedTypeOfBill !== null) {
            $query->andWhere(['client.type_of_bill' => $normalizedTypeOfBill]);
        }

        return $query;
    }

    private function buildMissingQuery(): Query
    {
        return $this->buildProcessedQuery()
            ->leftJoin(['ils' => 'invoice_line_source'], 'ils.line_pk = nbl.pk')
            ->andWhere(['ils.line_pk' => null]);
    }

    private function buildGroupedMissingBillsQuery(): Query
    {
        return $this->buildMissingQuery()
            ->select([
                'bill_no' => 'nbl.bill_no',
                'bill_date' => 'nb.bill_date',
                'client_id' => 'nb.client_id',
                'organization_id' => 'nb.organization_id',
                'type_of_bill' => 'client.type_of_bill',
                'missing_line_count' => new Expression('COUNT(*)'),
                'missing_sum' => new Expression('SUM(nbl.sum)'),
            ])
            ->groupBy([
                'nbl.bill_no',
                'nb.bill_date',
                'nb.client_id',
                'nb.organization_id',
                'client.type_of_bill',
            ])
            ->orderBy([
                'nb.bill_no' => SORT_ASC,
            ]);
    }

    private function normalizeTypeOfBill()
    {
        if ($this->type_of_bill === self::TYPE_OF_BILL_ALL || $this->type_of_bill === null) {
            return null;
        }

        if ($this->type_of_bill === self::TYPE_OF_BILL_SIMPLE) {
            return (int)ClientAccount::TYPE_OF_BILL_SIMPLE;
        }

        if ($this->type_of_bill === self::TYPE_OF_BILL_DETAILED) {
            return (int)ClientAccount::TYPE_OF_BILL_DETAILED;
        }

        return null;
    }

    public function getServiceDateFrom(): string
    {
        return $this->getMonthStart()->format(DateTimeZoneHelper::DATE_FORMAT);
    }

    public function getServiceDateToExclusive(): string
    {
        return $this->getMonthStart()
            ->modify('first day of next month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);
    }

    public function getBillDateFrom(): string
    {
        return $this->getMonthStart()->format(DateTimeZoneHelper::DATE_FORMAT);
    }

    public function getBillDateToExclusive(): string
    {
        return $this->getMonthStart()
            ->modify('first day of +2 month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);
    }

    private function getMonthStart(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $this->month . '-01',
            new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC)
        );
    }
}
