<?php

namespace app\models\filter;

use app\classes\grid\ActiveDataProvider;
use app\dao\CurrencyRateDao;
use app\helpers\DateTimeZoneHelper;
use app\models\ClientAccount;
use app\models\Currency;
use app\models\Region;
use app\models\billing\api\ApiRaw;
use DateTimeImmutable;
use DateTimeZone;
use Yii;
use yii\data\ArrayDataProvider;
use yii\db\Expression;

class BillingApiFilter extends ApiRaw
{
    public const GROUP_BY_CALL = 'call';
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
    public $group_by = self::GROUP_BY_CALL;
    public $group_by_method = 0;
    public $timezone = '';
    public $api_weight_total = null;
    public $cost_total = null;
    public $cost_price_total = null;
    public $period_group = null;
    private $costPriceByMethod = [];
    private $costPriceByAccount = [];
    private $costPriceByPeriod = [];
    private $isCostPricePrepared = false;

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
            [array_merge($dateFieldList, $rangeFieldList, ['api_method_id', 'group_by', 'group_by_method', 'timezone']), 'string'],
            [$dateFieldList, 'date', 'format' => 'php:Y-m-d'],
            ['group_by', 'in', 'range' => array_keys(self::GROUP_BY_LIST)],
            ['timezone', 'in', 'range' => array_keys(Region::getTimezoneList())],
        ]);
    }

    public static function getGroupByList(): array
    {
        return self::GROUP_BY_LIST;
    }

    public function getTimezoneList(): array
    {
        return Region::getTimezoneList();
    }

    /**
     * @param int $clientId
     * @return $this
     */
    public function load($clientId)
    {
        $this->accountId = $clientId;

        $requestData = Yii::$app->request->get();
        $filterData = $requestData[$this->formName()] ?? [];

        if (empty($filterData['group_by'])) {
            $this->group_by = $this->accountId ? self::GROUP_BY_DAY : self::GROUP_BY_ACCOUNT;
            $requestData[$this->formName()]['group_by'] = $this->group_by;
        }

        if (empty($filterData['timezone'])) {
            $requestData[$this->formName()]['timezone'] = $this->getDefaultTimezone();
        }

        parent::load($requestData);

        if ($this->group_by === self::GROUP_BY_CALL && !empty($filterData['group_by_method'])) {
            $this->group_by = self::GROUP_BY_API_METHOD;
        }
        if (empty($filterData['connect_time_from']) && empty($filterData['connect_time_to'])) {
            $currentMonth = new DateTimeImmutable('now', new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
            $this->connect_time_from = $currentMonth->modify('first day of this month')->format(DateTimeZoneHelper::DATE_FORMAT);
            $this->connect_time_to = $currentMonth->modify('last day of this month')->format(DateTimeZoneHelper::DATE_FORMAT);
        }

        return $this;
    }

    public function getDefaultTimezone(): string
    {
        if ($this->accountId) {
            $account = ClientAccount::findOne($this->accountId);
            if ($account && $account->timezone_name) {
                return $account->timezone_name;
            }
        }

        return DateTimeZoneHelper::TIMEZONE_UTC;
    }

    public function getQueryTimezone(): string
    {
        return $this->timezone ?: $this->getDefaultTimezone();
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
        $timezone = $this->getQueryTimezone();

        if ($this->isGroupByMethod()) {
            $query->with('method');
        }

        if (!($this->connect_time_from && $this->connect_time_to)) {
            $query->andWhere('false');
        } else {
            $this->isLoad = true;
            $connectTimeFrom = (new DateTimeImmutable($this->connect_time_from, new DateTimeZone($timezone)))
                ->setTime(0, 0, 0)
                ->setTimezone(new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
            $connectTimeTo = (new DateTimeImmutable($this->connect_time_to, new DateTimeZone($timezone)))
                ->setTime(0, 0, 0)
                ->modify('+1 day')
                ->setTimezone(new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));

            $query->andWhere(['>=', 'connect_time', $connectTimeFrom->format(DateTimeZoneHelper::DATETIME_FORMAT)]);
            $query->andWhere(['<', 'connect_time', $connectTimeTo->format(DateTimeZoneHelper::DATETIME_FORMAT)]);
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
                $groupExpression = new Expression(
                    "date_trunc('{$this->group_by}', ((connect_time AT TIME ZONE 'UTC') AT TIME ZONE '{$timezone}'))"
                );
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
                    'cost_price_total' => [
                        'asc' => ['api_method_id' => SORT_ASC],
                        'desc' => ['api_method_id' => SORT_DESC],
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
                    'cost_price_total' => [
                        'asc' => ['account_id' => SORT_ASC],
                        'desc' => ['account_id' => SORT_DESC],
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
                    'cost_price_total' => [
                        'asc' => ['period_group' => SORT_ASC],
                        'desc' => ['period_group' => SORT_DESC],
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
                    'cost_price_total' => [
                        'asc' => ['id' => SORT_ASC],
                        'desc' => ['id' => SORT_DESC],
                        'default' => SORT_DESC,
                    ],
                ],
            ];
        }

        $query = $this->makeQuery();
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'db' => ApiRaw::getDb(),
            'sort' => $sort,
        ]);

        $sortParam = (string)Yii::$app->request->get('sort', '');
        if (ltrim($sortParam, '-') === 'cost_price_total') {
            $models = $query->all();
            foreach ($models as $model) {
                if ($model instanceof ApiRaw) {
                    $model->cost_price_total = $this->getCostPriceTotal($model);
                }
            }

            usort($models, function (ApiRaw $left, ApiRaw $right) use ($sortParam) {
                if ($left->cost_price_total == $right->cost_price_total) {
                    return 0;
                }

                $isDesc = strpos($sortParam, '-') === 0;
                if ($isDesc) {
                    return ($left->cost_price_total < $right->cost_price_total) ? 1 : -1;
                }

                return ($left->cost_price_total > $right->cost_price_total) ? 1 : -1;
            });

            return new ArrayDataProvider([
                'allModels' => $models,
                'pagination' => $dataProvider->pagination,
                'sort' => $sort,
            ]);
        }

        return $dataProvider;
    }

    public function getTotal()
    {
        $query = $this->makeQuery(false);

        $query->select(['sum' => new Expression('-sum(cost)')]);

        return $query->scalar();
    }

    public function getMethodAccountDetails(int $apiMethodId): array
    {
        $rows = $this->makeQuery(false)
            ->andWhere(['api_method_id' => $apiMethodId])
            ->select([
                'account_id',
                'connect_time',
                'price_currency_id',
                'api_weight',
                'price_rate',
                'cost',
            ])
            ->asArray()
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $accountId = (int)$row['account_id'];
            if (!isset($result[$accountId])) {
                $result[$accountId] = [
                    'account_id' => $accountId,
                    'api_weight_total' => 0.0,
                    'cost_total' => 0.0,
                    'cost_price_total' => 0.0,
                ];
            }

            $result[$accountId]['api_weight_total'] += (float)$row['api_weight'];
            $result[$accountId]['cost_total'] += -(float)$row['cost'];
            $result[$accountId]['cost_price_total'] += $this->convertToRub(
                (float)$row['api_weight'] * (float)$row['price_rate'],
                (string)$row['price_currency_id'],
                (string)$row['connect_time']
            );
        }

        usort($result, function (array $left, array $right) {
            if ($left['cost_total'] === $right['cost_total']) {
                if ($left['api_weight_total'] === $right['api_weight_total']) {
                    return $left['account_id'] <=> $right['account_id'];
                }
                return $left['api_weight_total'] < $right['api_weight_total'] ? 1 : -1;
            }

            return $left['cost_total'] < $right['cost_total'] ? 1 : -1;
        });

        return $result;
    }

    public function getCostPriceTotal(ApiRaw $row): float
    {
        if ($this->isGroupByMethod()) {
            $this->prepareCostPriceAggregates();
            return (float)($this->costPriceByMethod[(int)$row->api_method_id] ?? 0.0);
        }

        if ($this->isGroupByAccount()) {
            $this->prepareCostPriceAggregates();
            return (float)($this->costPriceByAccount[(int)$row->account_id] ?? 0.0);
        }

        if ($this->isGroupedByDate()) {
            $this->prepareCostPriceAggregates();
            return (float)($this->costPriceByPeriod[(string)$row->period_group] ?? 0.0);
        }

        return $this->convertToRub(
            (float)$row->api_weight * (float)$row->price_rate,
            (string)$row->price_currency_id,
            (string)$row->connect_time
        );
    }

    private function prepareCostPriceAggregates(): void
    {
        if ($this->isCostPricePrepared) {
            return;
        }
        $this->isCostPricePrepared = true;

        $timezone = new DateTimeZone($this->getQueryTimezone());
        $rows = $this->makeQuery(false)
            ->select(['api_method_id', 'account_id', 'connect_time', 'price_currency_id', 'api_weight', 'price_rate'])
            ->asArray()
            ->all();

        foreach ($rows as $row) {
            $rubValue = $this->convertToRub(
                (float)$row['api_weight'] * (float)$row['price_rate'],
                (string)$row['price_currency_id'],
                (string)$row['connect_time']
            );

            $methodId = (int)$row['api_method_id'];
            $accountId = (int)$row['account_id'];
            $this->costPriceByMethod[$methodId] = ($this->costPriceByMethod[$methodId] ?? 0) + $rubValue;
            $this->costPriceByAccount[$accountId] = ($this->costPriceByAccount[$accountId] ?? 0) + $rubValue;

            if ($this->isGroupedByDate()) {
                $period = (new DateTimeImmutable($row['connect_time'], new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC)))
                    ->setTimezone($timezone);

                if ($this->group_by === self::GROUP_BY_YEAR) {
                    $period = $period->setDate((int)$period->format('Y'), 1, 1)->setTime(0, 0, 0);
                } elseif ($this->group_by === self::GROUP_BY_MONTH) {
                    $period = $period->setDate((int)$period->format('Y'), (int)$period->format('m'), 1)->setTime(0, 0, 0);
                } else {
                    $period = $period->setTime(0, 0, 0);
                }

                $periodKey = $period->format('Y-m-d H:i:s');
                $this->costPriceByPeriod[$periodKey] = ($this->costPriceByPeriod[$periodKey] ?? 0) + $rubValue;
            }
        }
    }

    private function convertToRub(float $amount, string $currencyId, string $connectTime): float
    {
        $currencyId = trim($currencyId) ?: Currency::RUB;
        if ($currencyId === Currency::RUB) {
            return $amount;
        }

        try {
            $rate = CurrencyRateDao::crossRate(
                $currencyId,
                Currency::RUB,
                (new DateTimeImmutable($connectTime))->format(DateTimeZoneHelper::DATE_FORMAT)
            );
        } catch (\Throwable $e) {
            $rate = null;
        }

        return $amount * ($rate ?: 1.0);
    }

}
