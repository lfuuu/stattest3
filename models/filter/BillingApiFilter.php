<?php

namespace app\models\filter;

use app\classes\grid\ActiveDataProvider;
use app\helpers\DateTimeZoneHelper;
use app\models\billing\api\ApiRaw;
use DateTimeImmutable;
use DateTimeZone;
use Yii;
use yii\db\Expression;

class BillingApiFilter extends ApiRaw
{
    public $accountId = null;
    public $isLoad = false;
    public $group_by_method = 0;
    public $api_weight_total = null;
    public $cost_total = null;

    public
        $connect_time_from = '',
        $connect_time_to = '',

        $api_weight_from = '',
        $api_weight_to = '',

        $rate_from = '',
        $rate_to = '',
        
        $cost_from = '',
        $cost_to = ''
    ;

    public function rules()
    {
        $fieldList = ['connect_time_from', 'connect_time_to', 'api_weight_from', 'api_weight_to', 'rate_from', 'rate_to', 'cost_from', 'cost_to'];
        return array_merge(parent::rules(), [
            [$fieldList, 'required'],
            [array_merge($fieldList, ['api_method_id', 'group_by_method']), 'string'],
        ]);
    }

    /**
     * @param int $clientId
     * @return $this
     */
    public function load($clientId)
    {
        $this->accountId = $clientId;

        $requestData = Yii::$app->request->get();

        parent::load($requestData);

        $filterData = $requestData[$this->formName()] ?? [];
        if (empty($filterData['connect_time_from']) && empty($filterData['connect_time_to'])) {
            $currentMonth = new DateTimeImmutable('now', new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
            $this->connect_time_from = $currentMonth->modify('first day of this month')->format(DateTimeZoneHelper::DATE_FORMAT);
            $this->connect_time_to = $currentMonth->modify('last day of this month')->format(DateTimeZoneHelper::DATE_FORMAT);
        }

        return $this;
    }

    public function isGroupByMethod(): bool
    {
        return (bool)$this->group_by_method;
    }

    private function makeQuery(bool $withGrouping = true)
    {
        $query = self::find()->with('method');

        if (!($this->connect_time_from && $this->connect_time_to)) {
            $query->andWhere('false');
        } else {
            $this->isLoad = true;
            $query->andWhere(['between', 'connect_time', $this->connect_time_from . ' 00:00:00', $this->connect_time_to . ' 23:59:59.999999']);
        }

        if ($this->accountId) {
            $query->andWhere(['account_id' => $this->accountId]);
        }

        $this->api_method_id && $query->andWhere(['api_method_id' => $this->api_method_id]);
        $this->api_weight_from && $query->andWhere(['>=', 'api_weight', $this->api_weight_from]);
        $this->api_weight_to && $query->andWhere(['<=', 'api_weight', $this->api_weight_to]);

        if ($withGrouping && $this->isGroupByMethod()) {
            $query
                ->select([
                    'api_method_id',
                    'api_weight_total' => new Expression('sum(api_weight)'),
                    'cost_total' => new Expression('-sum(cost)'),
                ])
                ->groupBy(['api_method_id']);
        }

        return $query;
    }

    /**
     * @return bool|ActiveDataProvider
     */
    public function search()
    {
        $sort = $this->isGroupByMethod()
            ? [
                'defaultOrder' => [
                    'api_method_id' => SORT_ASC,
                ],
                'attributes' => [
                    'api_method_id',
                    'api_weight_total' => [
                        'asc' => ['api_weight_total' => SORT_ASC],
                        'desc' => ['api_weight_total' => SORT_DESC],
                        'default' => SORT_DESC,
                    ],
                    'cost_total' => [
                        'asc' => ['cost_total' => SORT_ASC],
                        'desc' => ['cost_total' => SORT_DESC],
                        'default' => SORT_DESC,
                    ],
                ],
            ]
            : [
                'defaultOrder' => [
                    'id' => SORT_ASC,
                ],
                'attributes' => [
                    'id',
                    'connect_time',
                    'api_method_id',
                    'api_weight',
                    'rate' => [
                        'asc' => ['rate' => SORT_ASC],
                        'desc' => ['rate' => SORT_DESC],
                        'default' => SORT_DESC,
                    ],
                    'cost' => [
                        'asc' => ['cost' => SORT_DESC],
                        'desc' => ['cost' => SORT_ASC],
                        'default' => SORT_DESC,
                    ],
                ],
            ];

        $dataProvider = new ActiveDataProvider([
            'query' => $this->makeQuery(),
            'db' => ApiRaw::getDb(),
            'sort' => $sort,
        ]);

        return $dataProvider;
    }

    public function getTotal()
    {
        $query = $this->makeQuery(false);

        $query->select(['sum' => new Expression('-sum(cost)')]);

        return $query->scalar();
    }
}
