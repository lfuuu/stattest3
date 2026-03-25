<?php

namespace app\models\filter;

use app\classes\grid\ActiveDataProvider;
use app\helpers\DateTimeZoneHelper;
use app\models\ClientAccount;
use app\models\Region;
use app\models\billing\AiDialogRaw;
use DateTimeImmutable;
use DateTimeZone;
use Yii;
use yii\db\Expression;

class AiDialogFilter extends AiDialogRaw
{
    public const GROUP_BY_CALL = 'call';
    public const GROUP_BY_DAY = 'day';
    public const GROUP_BY_MONTH = 'month';
    public const GROUP_BY_YEAR = 'year';
    public const GROUP_BY_AGENT = 'agent';
    public const GROUP_BY_ACCOUNT = 'account';

    public const GROUP_BY_LIST = [
        self::GROUP_BY_CALL => 'диалогам',
        self::GROUP_BY_DAY => 'дням',
        self::GROUP_BY_MONTH => 'месяцам',
        self::GROUP_BY_YEAR => 'годам',
        self::GROUP_BY_AGENT => 'агентам',
        self::GROUP_BY_ACCOUNT => 'ЛС',
    ];

    public $accountId = null;
    public $isLoad = false;
    public $group_by = self::GROUP_BY_CALL;
    public $timezone = '';
    public $duration_total_sec = null;
    public $duration_total_min = null;
    public $dialogs_count = null;
    public $period_group = null;

    public
        $action_start_from = '',
        $action_start_to = '',

        $duration_from = '',
        $duration_to = ''
    ;

    public function rules()
    {
        $dateFieldList = ['action_start_from', 'action_start_to'];
        $rangeFieldList = ['duration_from', 'duration_to'];
        $otherFieldList = ['agent_id', 'agent_name', 'group_by', 'timezone'];

        return array_merge(parent::rules(), [
            [array_merge($dateFieldList, $rangeFieldList), 'required'],
            [array_merge($dateFieldList, $rangeFieldList, $otherFieldList), 'string'],
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

        if (empty($filterData['action_start_from']) && empty($filterData['action_start_to'])) {
            $currentMonth = new DateTimeImmutable('now', new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
            $requestData[$this->formName()]['action_start_from'] =
                $currentMonth->modify('first day of this month')->format(DateTimeZoneHelper::DATE_FORMAT);
            $requestData[$this->formName()]['action_start_to'] =
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

    public function isGroupByAgent(): bool
    {
        return $this->group_by === self::GROUP_BY_AGENT;
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
        return $this->isGroupByAgent() || $this->isGroupByAccount() || $this->isGroupedByDate();
    }

    private function makeQuery(bool $withGrouping = true)
    {
        $query = self::find();
        $timezone = $this->getQueryTimezone();

        if (!($this->action_start_from && $this->action_start_to)) {
            $query->andWhere('false');
        } else {
            $this->isLoad = true;
            $actionStartFrom = (new DateTimeImmutable($this->action_start_from, new DateTimeZone($timezone)))
                ->setTime(0, 0, 0)
                ->setTimezone(new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
            $actionStartTo = (new DateTimeImmutable($this->action_start_to, new DateTimeZone($timezone)))
                ->setTime(0, 0, 0)
                ->modify('+1 day')
                ->setTimezone(new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));

            $query->andWhere(['>=', 'action_start', $actionStartFrom->format(DateTimeZoneHelper::DATETIME_FORMAT)]);
            $query->andWhere(['<', 'action_start', $actionStartTo->format(DateTimeZoneHelper::DATETIME_FORMAT)]);
        }

        if ($this->duration_from !== '' && $this->duration_to !== '') {
            $query->andWhere(['between', 'duration', $this->duration_from, $this->duration_to]);
        }

        if ($this->accountId) {
            $query->andWhere(['account_id' => $this->accountId]);
        }

        $this->agent_id && $query->andWhere(['agent_id' => $this->agent_id]);
        $this->agent_name && $query->andWhere(['agent_name' => $this->agent_name]);

        if ($withGrouping && $this->isGrouped()) {
            if ($this->isGroupByAgent()) {
                $query
                    ->select([
                        'agent_id',
                        'agent_name',
                        'duration_total_sec' => new Expression('sum(duration)'),
                        'duration_total_min' => new Expression('sum(ceil(duration / 60.0))'),
                        'dialogs_count' => new Expression('count(*)'),
                    ])
                    ->groupBy(['agent_id', 'agent_name']);
            } elseif ($this->isGroupByAccount()) {
                $query
                    ->select([
                        'account_id',
                        'duration_total_sec' => new Expression('sum(duration)'),
                        'duration_total_min' => new Expression('sum(ceil(duration / 60.0))'),
                        'dialogs_count' => new Expression('count(*)'),
                    ])
                    ->groupBy(['account_id']);
            } elseif ($this->isGroupedByDate()) {
                $groupExpression = new Expression(
                    "date_trunc('{$this->group_by}', ((action_start AT TIME ZONE 'UTC') AT TIME ZONE '{$timezone}'))"
                );
                $query
                    ->select([
                        'period_group' => $groupExpression,
                        'duration_total_sec' => new Expression('sum(duration)'),
                        'duration_total_min' => new Expression('sum(ceil(duration / 60.0))'),
                        'dialogs_count' => new Expression('count(*)'),
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
        if ($this->isGroupByAgent()) {
            $sort = [
                'defaultOrder' => [
                    'agent_name' => SORT_ASC,
                ],
                'attributes' => [
                    'agent_id',
                    'agent_name',
                    'duration_total_sec' => [
                        'asc' => ['duration_total_sec' => SORT_ASC],
                        'desc' => ['duration_total_sec' => SORT_DESC],
                        'default' => SORT_DESC,
                    ],
                    'duration_total_min' => [
                        'asc' => ['duration_total_min' => SORT_ASC],
                        'desc' => ['duration_total_min' => SORT_DESC],
                        'default' => SORT_DESC,
                    ],
                    'dialogs_count' => [
                        'asc' => ['dialogs_count' => SORT_ASC],
                        'desc' => ['dialogs_count' => SORT_DESC],
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
                    'duration_total_sec' => [
                        'asc' => ['duration_total_sec' => SORT_ASC],
                        'desc' => ['duration_total_sec' => SORT_DESC],
                        'default' => SORT_DESC,
                    ],
                    'duration_total_min' => [
                        'asc' => ['duration_total_min' => SORT_ASC],
                        'desc' => ['duration_total_min' => SORT_DESC],
                        'default' => SORT_DESC,
                    ],
                    'dialogs_count' => [
                        'asc' => ['dialogs_count' => SORT_ASC],
                        'desc' => ['dialogs_count' => SORT_DESC],
                        'default' => SORT_DESC,
                    ],
                ],
            ];
        } elseif ($this->isGroupedByDate()) {
            $sort = [
                'defaultOrder' => [
                    'action_start' => SORT_ASC,
                ],
                'attributes' => [
                    'action_start' => [
                        'asc' => ['period_group' => SORT_ASC],
                        'desc' => ['period_group' => SORT_DESC],
                        'default' => SORT_ASC,
                    ],
                    'duration_total_sec' => [
                        'asc' => ['duration_total_sec' => SORT_ASC],
                        'desc' => ['duration_total_sec' => SORT_DESC],
                        'default' => SORT_DESC,
                    ],
                    'duration_total_min' => [
                        'asc' => ['duration_total_min' => SORT_ASC],
                        'desc' => ['duration_total_min' => SORT_DESC],
                        'default' => SORT_DESC,
                    ],
                    'dialogs_count' => [
                        'asc' => ['dialogs_count' => SORT_ASC],
                        'desc' => ['dialogs_count' => SORT_DESC],
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
                    'action_start',
                    'agent_id',
                    'agent_name',
                    'duration' => [
                        'asc' => ['duration' => SORT_ASC],
                        'desc' => ['duration' => SORT_DESC],
                        'default' => SORT_DESC,
                    ],
                ],
            ];
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $this->makeQuery(),
            'db' => AiDialogRaw::getDb(),
            'sort' => $sort,
        ]);

        return $dataProvider;
    }

    public function getTotal()
    {
        $query = $this->makeQuery(false);

        $query->select([
            'sum_sec' => new Expression('sum(duration)'),
            'sum_min' => new Expression('sum(ceil(duration / 60.0))'),
            'dialogs_count' => new Expression('count(*)'),
        ]);

        return $query->asArray()->one();
    }
}
