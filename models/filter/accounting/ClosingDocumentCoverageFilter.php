<?php

namespace app\models\filter\accounting;

use app\helpers\DateTimeZoneHelper;
use app\models\Bill;
use app\models\BillLine;
use app\models\ClientAccount;
use app\models\ClientAccountOptions;
use app\models\InvoiceLine;
use DateTimeImmutable;
use DateTimeZone;
use Yii;
use yii\base\Model;
use yii\data\SqlDataProvider;
use yii\db\Expression;
use yii\db\Query;

class ClosingDocumentCoverageFilter extends Model
{
    public const TYPE_OF_BILL_ALL = '';

    public $month = '';
    public $type_of_bill = self::TYPE_OF_BILL_ALL;

    public function rules()
    {
        return [
            [['month'], 'required'],
            [['month'], 'match', 'pattern' => '/^\d{4}-\d{2}$/'],
            [['type_of_bill'], 'in', 'range' => [
                self::TYPE_OF_BILL_ALL,
                (string)ClientAccount::TYPE_OF_BILL_SIMPLE,
                (string)ClientAccount::TYPE_OF_BILL_DETAILED,
            ]],
        ];
    }

    public static function getTypeOfBillList(): array
    {
        return [
            self::TYPE_OF_BILL_ALL => 'Все',
            (string)ClientAccount::TYPE_OF_BILL_SIMPLE => 'Простой',
            (string)ClientAccount::TYPE_OF_BILL_DETAILED => 'Полный',
        ];
    }

    public function load($data = null, $formName = null)
    {
        $requestData = $data ?? Yii::$app->request->get();

        if (empty(($requestData[$this->formName()] ?? [])['month'])) {
            $currentMonth = new DateTimeImmutable('now', new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
            $requestData[$this->formName()]['month'] = $currentMonth->format('Y-m');
        }

        parent::load($requestData, $formName);

        return $this;
    }

    public function getSummary(): array
    {
        $missingQuery = $this->buildMissingQuery();

        return [
            'processed_line_count' => (int)$this->buildProcessedQuery()
                ->select(new Expression('COUNT(*)'))
                ->orderBy([])
                ->scalar(),
            'missing_upd_line_count' => (int)(clone $missingQuery)
                ->select(new Expression('COUNT(*)'))
                ->orderBy([])
                ->scalar(),
            'bill_count' => (int)(clone $missingQuery)
                ->select(new Expression('COUNT(DISTINCT nb.bill_no)'))
                ->orderBy([])
                ->scalar(),
            'client_count' => (int)(clone $missingQuery)
                ->select(new Expression('COUNT(DISTINCT nb.client_id)'))
                ->orderBy([])
                ->scalar(),
        ];
    }

    public function getDataProvider(): SqlDataProvider
    {
        $query = $this->buildMissingQuery()
            ->select([
                'line_pk' => 'nbl.pk',
                'bill_no' => 'nbl.bill_no',
                'bill_date' => 'nb.bill_date',
                'client_id' => 'nb.client_id',
                'organization_id' => 'nb.organization_id',
                'item' => 'nbl.item',
                'sum' => 'nbl.sum',
                'date_from' => 'nbl.date_from',
                'date_to' => 'nbl.date_to',
                'type_of_bill' => 'client.type_of_bill',
            ])
            ->orderBy([
                'nb.bill_no' => SORT_ASC,
                'nbl.pk' => SORT_ASC,
            ]);

        $totalCount = (int)(clone $query)
            ->select(new Expression('COUNT(*)'))
            ->orderBy([])
            ->scalar();

        return new SqlDataProvider([
            'sql' => $query->createCommand()->rawSql,
            'totalCount' => $totalCount,
            'pagination' => [
                'pageSize' => 100,
            ],
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
                "cao.client_account_id = nb.client_id AND cao.option = 'upload_to_sales_book'"
            )
            ->where(['>=', 'nb.bill_date', $this->getDateFrom()])
            ->andWhere(['<', 'nb.bill_date', $this->buildDateToExclusive()])
            ->andWhere(['nbl.type' => 'service'])
            ->andWhere(['=', new Expression("COALESCE(cao.value, '0')"), '1'])
            ->andWhere(['>', 'nbl.sum', 0])
            ->andWhere(['client.price_level' => 1])
            ->andFilterWhere(['client.type_of_bill' => $this->normalizeTypeOfBill()]);
    }

    private function buildMissingQuery(): Query
    {
        return $this->buildProcessedQuery()
            ->leftJoin(['il' => InvoiceLine::tableName()], 'il.line_id = nbl.pk')
            ->andWhere(['il.pk' => null]);
    }

    private function buildDateToExclusive(): string
    {
        return $this->getMonthStart()
            ->modify('first day of next month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);
    }

    private function normalizeTypeOfBill()
    {
        if ($this->type_of_bill === self::TYPE_OF_BILL_ALL || $this->type_of_bill === null) {
            return null;
        }

        return (int)$this->type_of_bill;
    }

    private function getMonthStart(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $this->month . '-01',
            new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC)
        );
    }

    public function getDateFrom(): string
    {
        return $this->getMonthStart()->format(DateTimeZoneHelper::DATE_FORMAT);
    }

    public function getDateTo(): string
    {
        return $this->getMonthStart()->modify('last day of this month')->format(DateTimeZoneHelper::DATE_FORMAT);
    }
}
