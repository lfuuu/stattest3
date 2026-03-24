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
    public const GROUP_BY_CALL = '';
    public const GROUP_BY_DAY = 'day';
    public const GROUP_BY_MONTH = 'month';
    public const GROUP_BY_YEAR = 'year';
    public const GROUP_BY_API_METHOD = 'api_method';
    public const GROUP_BY_ACCOUNT = 'account';

    public const GROUP_BY_LIST = [
        self::GROUP_BY_CALL => 'вызовам',
        self::GROUP_BY_DAY => 'дням',
        self::GROUP_BY_MONTH => 'месяцам',
        self::GROUP_BY_YEAR => 'годам',
        self::GROUP_BY_API_METHOD => 'API-методам',
        self::GROUP_BY_ACCOUNT => 'ЛС',
    ];

    public $accountId = null;
    public $isLoad = false;
    public $group_by = '';
    public $group_by_method = 0;
    public $api_weight_total = null;
    public $cost_total = null;
    public $period_group = null;

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
        $rangeFieldList = ['api_weight_from', 'api_weight_to', 'rate_from', 'rate_to', 'cost_from', 'cost_to'];
        $dateFieldList = ['connect_time_from', 'connect_time_to'];

        return array_merge(parent::rules(), [
            [array_merge($dateFieldList, $rangeFieldList), 'required'],
            [array_merge($dateFieldList, $rangeFieldList, ['api_method_id', 'group_by', 'group_by_method']), 'string'],
            [$dateFieldList, 'date', 'format' => 'php:Y-m-d'],
            ['group_by', 'in', 'range' => array_keys(self::GROUP_BY_LIST)],
        ]);
    }

    public static function getGroupByList(): array
    {
        return self::GROUP_BY_LIST;
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
        if (empty($this->group_by) && !empty($filterData['group_by_method'])) {
            $this->group_by = self::GROUP_BY_API_METHOD;
        }
        if (empty($filterData['connect_time_from']) && empty($filterData['connect_time_to'])) {
            $currentMonth = new DateTimeImmutable('now', new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
            $this->connect_time_from = $currentMonth->modify('first day of this month')->format(DateTimeZoneHelper::DATE_FORMAT);
            $this->connect_time_to = $currentMonth->modify('last day of this month')->format(DateTimeZoneHelper::DATE_FORMAT);
        }

        return $this;
    }

    public function isGroupByMethod(): bool
    {
        return $this->group_by === self::GROUP_BY_API_METHOD;
    }

    public function isGroupByAccount(): bool
    {
        return $this->group_by === self::GROUP_BY_ACCOUNT;
    }

    public function isGroupedByDate(): bool
    {
        return in_array($this->group_by, [self::GROUP_BY_DAY, self::GROUP_BY_MONTH, self::GROUP_BY_YEAR], true);
    }

    public function isGrouped(): bool
    {
        return $this->isGroupByMethod() || $this->isGroupedByDate() || $this->isGroupByAccount();
    }

    private function makeQuery(bool $withGrouping = true)
    {
        $query = self::find();

        if ($this->isGroupByMethod()) {
            $query->with('method');
        }

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

        if ($withGrouping && $this->isGrouped()) {
            if ($this->isGroupByMethod()) {
                $query
                    ->select([
                        'api_method_id',
                        'api_weight_total' => new Expression('sum(api_weight)'),
                        'cost_total' => new Expression('-sum(cost)'),
                    ])
                    ->groupBy(['api_method_id']);
            } elseif ($this->isGroupByAccount()) {
                $query
                    ->select([
                        'account_id',
                        'api_weight_total' => new Expression('sum(api_weight)'),
                        'cost_total' => new Expression('-sum(cost)'),
                    ])
                    ->groupBy(['account_id']);
            } elseif ($this->isGroupedByDate()) {
                $groupExpression = new Expression("date_trunc('{$this->group_by}', connect_time)");
                $query
                    ->select([
                        'period_group' => $groupExpression,
                        'api_weight_total' => new Expression('sum(api_weight)'),
                        'cost_total' => new Expression('-sum(cost)'),
                    ])
                    ->groupBy([$groupExpression]);
            }
        }

        return $query;
    }

    /**
     * @return bool|ActiveDataProvider
     */
    public function search()
    {
        if ($this->isGroupByMethod()) {
            $sort = [
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
            ];
        } elseif ($this->isGroupByAccount()) {
            $sort = [
                'defaultOrder' => [
                    'account_id' => SORT_ASC,
                ],
                'attributes' => [
                    'account_id',
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
            ];
        } elseif ($this->isGroupedByDate()) {
            $sort = [
                'defaultOrder' => [
                    'connect_time' => SORT_ASC,
                ],
                'attributes' => [
                    'connect_time' => [
                        'asc' => ['period_group' => SORT_ASC],
                        'desc' => ['period_group' => SORT_DESC],
                        'default' => SORT_ASC,
                    ],
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
            ];
        } else {
            $sort = [
                'defaultOrder' => [
                    'id' => SORT_ASC,
                ],
                'attributes' => [
                    'id',
                    'account_id',
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
        }

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
