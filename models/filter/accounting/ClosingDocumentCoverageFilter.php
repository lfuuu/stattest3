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
    public $bill_date_from = '';
    public $bill_date_to = '';
    public $service_date_from = '';
    public $service_date_to = '';

    public function rules()
    {
        return [
            [['bill_date_from', 'bill_date_to', 'service_date_from', 'service_date_to'], 'required'],
            [['bill_date_from', 'bill_date_to', 'service_date_from', 'service_date_to'], 'date', 'format' => 'php:Y-m-d'],
            ['bill_date_from', 'compare', 'compareAttribute' => 'bill_date_to', 'operator' => '<', 'type' => 'string'],
            ['service_date_from', 'compare', 'compareAttribute' => 'service_date_to', 'operator' => '<', 'type' => 'string'],
        ];
    }

    public function load($data = null, $formName = null)
    {
        $requestData = $data ?? Yii::$app->request->get();

        if (empty($requestData[$this->formName()])) {
            $currentMonthStart = new DateTimeImmutable('first day of this month', new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
            $nextMonthStart = $currentMonthStart->modify('first day of next month');

            $requestData[$this->formName()] = [
                'bill_date_from' => $currentMonthStart->format(DateTimeZoneHelper::DATE_FORMAT),
                'bill_date_to' => $nextMonthStart->format(DateTimeZoneHelper::DATE_FORMAT),
                'service_date_from' => $currentMonthStart->format(DateTimeZoneHelper::DATE_FORMAT),
                'service_date_to' => $nextMonthStart->format(DateTimeZoneHelper::DATE_FORMAT),
            ];
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
        return (new Query())
            ->from(['nbl' => BillLine::tableName()])
            ->innerJoin(['nb' => Bill::tableName()], 'nb.bill_no = nbl.bill_no')
            ->innerJoin(['client' => ClientAccount::tableName()], 'client.id = nb.client_id')
            ->leftJoin(
                ['cao' => ClientAccountOptions::tableName()],
                "cao.client_account_id = nb.client_id AND cao.option = '" . ClientAccountOptions::OPTION_UPLOAD_TO_SALES_BOOK . "'"
            )
            ->where(['not', ['nb.uu_bill_id' => null]])
            ->andWhere(['>=', 'nb.bill_date', $this->bill_date_from])
            ->andWhere(['<', 'nb.bill_date', $this->bill_date_to])
            ->andWhere(['nbl.type' => 'service'])
            ->andWhere(['=', new Expression("COALESCE(cao.value, '0')"), '1'])
            ->andWhere(['>', 'nbl.sum', 0])
            ->andWhere(['client.price_level' => 1])
            ->andWhere(['>=', 'nbl.date_from', $this->service_date_from])
            ->andWhere(['<', 'nbl.date_from', $this->service_date_to]);
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
                'missing_line_count' => new Expression('COUNT(*)'),
                'missing_sum' => new Expression('SUM(nbl.sum)'),
            ])
            ->groupBy([
                'nbl.bill_no',
                'nb.bill_date',
                'nb.client_id',
                'nb.organization_id',
            ])
            ->orderBy([
                'nb.bill_no' => SORT_ASC,
            ]);
    }
}
