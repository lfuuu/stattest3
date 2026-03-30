<?php

namespace app\models\filter;

use app\classes\grid\ActiveDataProvider;
use app\helpers\DateTimeZoneHelper;
use app\models\ClientAccount;
use app\models\Region;
use app\models\billing\MavRaw;
use DateTimeImmutable;
use DateTimeZone;
use Yii;
use yii\db\Expression;

class MavFilter extends MavRaw
{
    public const RECORD_TYPE_ALL = 'all';
    public const RECORD_TYPE_MAV = 'mav';
    public const RECORD_TYPE_LABEL = 'label';

    public const GROUP_BY_CALL = 'call';
    public const GROUP_BY_DAY = 'day';
    public const GROUP_BY_MONTH = 'month';
    public const GROUP_BY_YEAR = 'year';
    public const GROUP_BY_ACCOUNT = 'account';

    public const GROUP_BY_LIST = [
        self::GROUP_BY_CALL => 'вызовам',
        self::GROUP_BY_DAY => 'дням',
        self::GROUP_BY_MONTH => 'месяцам',
        self::GROUP_BY_YEAR => 'годам',
        self::GROUP_BY_ACCOUNT => 'ЛС',
    ];

    public const RECORD_TYPE_LIST = [
        self::RECORD_TYPE_ALL => 'МАВ и Маркировка',
        self::RECORD_TYPE_MAV => 'МАВ',
        self::RECORD_TYPE_LABEL => 'Маркировка',
    ];

    public $accountId = null;
    public $isLoad = false;
    public $group_by = self::GROUP_BY_CALL;
    public $timezone = '';
    public $record_type = self::RECORD_TYPE_ALL;
    public $calls_count = null;
    public $billed_time_total = null;
    public $cost_total = null;
    public $period_group = null;

    public
        $connect_time_from = '',
        $connect_time_to = '',

        $billed_time_from = '',
        $billed_time_to = '',

        $rate_from = '',
        $rate_to = '',

        $cost_from = '',
        $cost_to = ''
    ;

    public function rules()
    {
        $dateFieldList = ['connect_time_from', 'connect_time_to'];
        $rangeFieldList = ['billed_time_from', 'billed_time_to', 'rate_from', 'rate_to', 'cost_from', 'cost_to'];
        $otherFieldList = ['group_by', 'timezone', 'src_number', 'dst_number', 'record_type'];

        return array_merge(parent::rules(), [
            [array_merge($dateFieldList, $rangeFieldList), 'required'],
            [array_merge($dateFieldList, $rangeFieldList, $otherFieldList), 'string'],
            [$dateFieldList, 'date', 'format' => 'php:Y-m-d'],
            ['group_by', 'in', 'range' => array_keys(self::GROUP_BY_LIST)],
            ['record_type', 'in', 'range' => array_keys(self::RECORD_TYPE_LIST)],
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

    public static function getRecordTypeList(): array
    {
        return self::RECORD_TYPE_LIST;
    }

    public function getRecordTypeLabel(): string
    {
        if ($this->is_mav && $this->is_label) {
            return self::RECORD_TYPE_LIST[self::RECORD_TYPE_ALL];
        }

        if ($this->is_mav) {
            return self::RECORD_TYPE_LIST[self::RECORD_TYPE_MAV];
        }

        if ($this->is_label) {
            return self::RECORD_TYPE_LIST[self::RECORD_TYPE_LABEL];
        }

        return '';
    }

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

        if (empty($filterData['connect_time_from']) && empty($filterData['connect_time_to'])) {
            $currentMonth = new DateTimeImmutable('now', new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
            $requestData[$this->formName()]['connect_time_from'] =
                $currentMonth->modify('first day of this month')->format(DateTimeZoneHelper::DATE_FORMAT);
            $requestData[$this->formName()]['connect_time_to'] =
                $currentMonth->modify('last day of this month')->format(DateTimeZoneHelper::DATE_FORMAT);
        }

        parent::load($requestData);

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
        return $this->isGroupByAccount() || $this->isGroupedByDate();
    }

    private function makeQuery(bool $withGrouping = true)
    {
        $query = self::find();
        $timezone = $this->getQueryTimezone();

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

        if ($this->record_type === self::RECORD_TYPE_MAV) {
            $query->andWhere([
                'is_mav' => true,
                'is_label' => false,
            ]);
        } elseif ($this->record_type === self::RECORD_TYPE_LABEL) {
            $query->andWhere([
                'is_mav' => false,
                'is_label' => true,
            ]);
        } else {
            $query->andWhere([
                'or',
                ['is_mav' => true],
                ['is_label' => true],
            ]);
        }

        if ($this->billed_time_from !== '') {
            $query->andWhere(['>=', 'billed_time', $this->billed_time_from]);
        }
        if ($this->billed_time_to !== '') {
            $query->andWhere(['<=', 'billed_time', $this->billed_time_to]);
        }
        if ($this->rate_from !== '') {
            $query->andWhere(['>=', 'rate', $this->rate_from]);
        }
        if ($this->rate_to !== '') {
            $query->andWhere(['<=', 'rate', $this->rate_to]);
        }
        if ($this->cost_from !== '') {
            $query->andWhere(['>=', 'cost', $this->cost_from]);
        }
        if ($this->cost_to !== '') {
            $query->andWhere(['<=', 'cost', $this->cost_to]);
        }
        if ($this->src_number !== '' && $this->src_number !== null) {
            $query->andWhere(['src_number' => $this->src_number]);
        }
        if ($this->dst_number !== '' && $this->dst_number !== null) {
            $query->andWhere(['dst_number' => $this->dst_number]);
        }

        if ($withGrouping && $this->isGrouped()) {
            if ($this->isGroupByAccount()) {
                $query
                    ->select([
                        'account_id',
                        'is_mav' => new Expression('bool_or(is_mav)'),
                        'is_label' => new Expression('bool_or(is_label)'),
                        'calls_count' => new Expression('count(*)'),
                        'billed_time_total' => new Expression('sum(billed_time)'),
                        'cost_total' => new Expression('sum(cost)'),
                    ])
                    ->groupBy(['account_id']);
            } elseif ($this->isGroupedByDate()) {
                $groupExpression = new Expression(
                    "date_trunc('{$this->group_by}', ((connect_time AT TIME ZONE 'UTC') AT TIME ZONE '{$timezone}'))"
                );
                $query
                    ->select([
                        'period_group' => $groupExpression,
                        'is_mav' => new Expression('bool_or(is_mav)'),
                        'is_label' => new Expression('bool_or(is_label)'),
                        'calls_count' => new Expression('count(*)'),
                        'billed_time_total' => new Expression('sum(billed_time)'),
                        'cost_total' => new Expression('sum(cost)'),
                    ])
                    ->groupBy([$groupExpression]);
            }
        }

        return $query;
    }

    public function search()
    {
        if ($this->isGroupByAccount()) {
            $sort = [
                'defaultOrder' => [
                    'account_id' => SORT_ASC,
                ],
                'attributes' => [
                    'account_id',
                    'calls_count' => [
                        'asc' => ['calls_count' => SORT_ASC],
                        'desc' => ['calls_count' => SORT_DESC],
                        'default' => SORT_DESC,
                    ],
                    'billed_time_total' => [
                        'asc' => ['billed_time_total' => SORT_ASC],
                        'desc' => ['billed_time_total' => SORT_DESC],
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
                    'calls_count' => [
                        'asc' => ['calls_count' => SORT_ASC],
                        'desc' => ['calls_count' => SORT_DESC],
                        'default' => SORT_DESC,
                    ],
                    'billed_time_total' => [
                        'asc' => ['billed_time_total' => SORT_ASC],
                        'desc' => ['billed_time_total' => SORT_DESC],
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
                    'src_number',
                    'dst_number',
                    'billed_time' => [
                        'asc' => ['billed_time' => SORT_ASC],
                        'desc' => ['billed_time' => SORT_DESC],
                        'default' => SORT_DESC,
                    ],
                    'rate' => [
                        'asc' => ['rate' => SORT_ASC],
                        'desc' => ['rate' => SORT_DESC],
                        'default' => SORT_DESC,
                    ],
                    'cost' => [
                        'asc' => ['cost' => SORT_ASC],
                        'desc' => ['cost' => SORT_DESC],
                        'default' => SORT_DESC,
                    ],
                ],
            ];
        }

        return new ActiveDataProvider([
            'query' => $this->makeQuery(),
            'db' => MavRaw::getDb(),
            'sort' => $sort,
        ]);
    }

    public function getTotal()
    {
        $query = $this->makeQuery(false);
        $query->select([
            'calls_count' => new Expression('count(*)'),
            'billed_time_total' => new Expression('sum(billed_time)'),
            'cost_total' => new Expression('sum(cost)'),
        ]);

        return $query->asArray()->one();
    }
}
