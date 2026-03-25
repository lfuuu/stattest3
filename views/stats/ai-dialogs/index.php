<?php
/**
 * Статистика / ИИ-агент: Диалоги
 *
 * @var \app\classes\BaseView $this
 * @var \app\models\filter\AiDialogFilter $filterModel
 */

use app\classes\grid\column\universal\DateRangeDoubleColumn;
use app\classes\grid\column\universal\IntegerRangeColumn;
use app\classes\grid\column\universal\StringColumn;
use app\classes\grid\GridView;
use app\classes\Html;
use app\helpers\DateTimeZoneHelper;
use app\models\billing\AiDialogRaw;
use app\models\filter\AiDialogFilter;
use DateTimeImmutable;
use DateTimeZone;
use Yii;
use yii\helpers\Url;
use yii\widgets\ActiveForm;
use yii\widgets\Breadcrumbs;

echo Html::formLabel($this->title = 'ИИ-агент: Диалоги');

echo Breadcrumbs::widget([
    'links' => [
        'Статистика',
        ['label' => $this->title, 'url' => $baseUrl = Url::toRoute('stats/ai-dialogs')]
    ],
]);

$utcNow = new DateTimeImmutable('now', new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
$requestParams = Yii::$app->request->queryParams;
unset($requestParams['page'], $requestParams['sort']);

$filterParams = $requestParams[$filterModel->formName()] ?? [];
$buildPeriodUrl = function (string $dateFrom, string $dateTo) use ($baseUrl, $filterModel, $requestParams, $filterParams) {
    $requestParams[$filterModel->formName()] = array_merge($filterParams, [
        'action_start_from' => $dateFrom,
        'action_start_to' => $dateTo,
    ]);

    return Url::to(array_merge([$baseUrl], $requestParams));
};

$periodAnchor = new DateTimeImmutable(
    $filterModel->action_start_from ?: $utcNow->format(DateTimeZoneHelper::DATE_FORMAT),
    new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC)
);
$previousMonth = $periodAnchor->modify('first day of previous month');
$currentPeriodMonth = $periodAnchor->modify('first day of this month');
$today = $utcNow->format(DateTimeZoneHelper::DATE_FORMAT);

$integerFormat = function ($value) {
    return number_format((float)$value, 0, '.', ' ');
};

$numericColumnOptions = [
    'contentOptions' => ['style' => 'text-align: right; white-space: nowrap;'],
    'headerOptions' => ['style' => 'text-align: center;'],
];

$previousMonthUrl = $buildPeriodUrl(
    $previousMonth->format('Y-m-01'),
    $previousMonth->format('Y-m-t')
);
$currentMonthUrl = $buildPeriodUrl(
    $currentPeriodMonth->format('Y-m-01'),
    $currentPeriodMonth->format('Y-m-t')
);
$currentDayUrl = $buildPeriodUrl($today, $today);

if (!$filterModel->isLoad) {
    ?>

    <div class="row">
        <div class="col-sm-6 text-left">
            <?php $total = $filterModel->getTotal(); ?>
            <div class="well">
                Итоговое потребление:
                <?=$integerFormat($total['sum_sec'])?> секунд /
                <?=$integerFormat($total['sum_min'])?> минут /
                <?=$integerFormat($total['dialogs_count'])?> диалогов
            </div>
        </div>
    </div>

    <?php
}
$form = ActiveForm::begin(['method' => 'get', 'action' => $baseUrl]);

?>

<div style="margin-bottom: 15px;">
    Создайте отчёт сами:
    (или - посмотрите отчёты за
    <?= Html::a('прошлый месяц', $previousMonthUrl) ?>,
    за <?= Html::a('текущий месяц', $currentMonthUrl) ?>,
    за <?= Html::a('текущий день', $currentDayUrl) ?>)
</div>

<div class="row" style="margin-bottom: 15px;">
    <div class="col-sm-3">
        Выводить по
        <?= Html::activeDropDownList($filterModel, 'group_by', AiDialogFilter::getGroupByList(), [
            'class' => 'form-control input-sm',
        ]) ?>
    </div>
    <div class="col-sm-3">
        Часовой пояс
        <?= Html::activeDropDownList($filterModel, 'timezone', $filterModel->getTimezoneList(), [
            'class' => 'form-control input-sm',
        ]) ?>
    </div>
    <div class="col-sm-3">
        <?= Html::submitButton('Сформировать отчёт', ['class' => 'btn btn-primary btn-sm', 'style' => 'margin-top: 20px']) ?>
    </div>
</div>

<?php

$timeLabel = sprintf('Время начала, %s', $filterModel->getQueryTimezone());
$periodLabel = sprintf('Период, %s', $filterModel->getQueryTimezone());

$periodRangeColumn = function (string $label) use ($filterModel) {
    return [
        'attribute' => 'action_start',
        'label' => $label,
        'class' => DateRangeDoubleColumn::class,
        'value' => function () use ($filterModel) {
            return $filterModel->action_start_from . ' - ' . $filterModel->action_start_to;
        }
    ];
};

$agentIdColumn = [
    'attribute' => 'agent_id',
    'label' => 'ID агента',
    'class' => \app\classes\grid\column\universal\IntegerColumn::class,
];

$agentNameColumn = [
    'attribute' => 'agent_name',
    'label' => 'Имя агента',
    'class' => StringColumn::class,
];

$durationSecTotalColumn = [
    'attribute' => 'duration_total_sec',
    'label' => 'Суммарно, сек',
    'value' => function (AiDialogRaw $row) use ($integerFormat) {
        return $row instanceof AiDialogFilter ? $integerFormat($row->duration_total_sec) : null;
    }
] + $numericColumnOptions;

$durationMinTotalColumn = [
    'attribute' => 'duration_total_min',
    'label' => 'Суммарно, мин',
    'value' => function (AiDialogRaw $row) use ($integerFormat) {
        return $row instanceof AiDialogFilter ? $integerFormat($row->duration_total_min) : null;
    }
] + $numericColumnOptions;

$dialogsCountColumn = [
    'attribute' => 'dialogs_count',
    'label' => 'Количество диалогов',
    'value' => function (AiDialogRaw $row) use ($integerFormat) {
        return $row instanceof AiDialogFilter ? $integerFormat($row->dialogs_count) : null;
    }
] + $numericColumnOptions;

$columns = [];

if ($filterModel->isGroupByAgent()) {
    $columns = [
        $periodRangeColumn($periodLabel),
        $agentIdColumn,
        $agentNameColumn,
        $durationSecTotalColumn,
        $durationMinTotalColumn,
        $dialogsCountColumn,
    ];
} elseif ($filterModel->isGroupByAccount()) {
    $columns = [
        $periodRangeColumn($timeLabel),
        [
            'attribute' => 'account_id',
            'label' => 'ЛС',
        ],
        $durationSecTotalColumn,
        $durationMinTotalColumn,
        $dialogsCountColumn,
    ];
} elseif ($filterModel->isGroupedByDate()) {
    $periodLabels = [
        AiDialogFilter::GROUP_BY_DAY => sprintf('День, %s', $filterModel->getQueryTimezone()),
        AiDialogFilter::GROUP_BY_MONTH => sprintf('Месяц, %s', $filterModel->getQueryTimezone()),
        AiDialogFilter::GROUP_BY_YEAR => sprintf('Год, %s', $filterModel->getQueryTimezone()),
    ];

    $columns = [
        [
            'attribute' => 'action_start',
            'label' => $periodLabels[$filterModel->group_by] ?? $periodLabel,
            'class' => DateRangeDoubleColumn::class,
            'value' => function (AiDialogRaw $row) use ($filterModel) {
                if (!$row instanceof AiDialogFilter || !$row->period_group) {
                    return null;
                }

                $period = new DateTimeImmutable($row->period_group, new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));

                if ($filterModel->group_by === AiDialogFilter::GROUP_BY_YEAR) {
                    return $period->format('Y');
                }

                if ($filterModel->group_by === AiDialogFilter::GROUP_BY_MONTH) {
                    return $period->format('Y-m');
                }

                return $period->format(DateTimeZoneHelper::DATE_FORMAT);
            }
        ],
        $durationSecTotalColumn,
        $durationMinTotalColumn,
        $dialogsCountColumn,
    ];
} else {
    if (!$filterModel->accountId) {
        $columns[] = [
            'attribute' => 'account_id',
            'label' => 'ЛС',
        ];
    }

    $columns = array_merge($columns, [
        [
            'attribute' => 'action_start',
            'label' => $timeLabel,
            'value' => function (AiDialogRaw $raw) use ($filterModel) {
                $time = new DateTimeImmutable($raw->action_start, new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
                return $time->setTimezone(new DateTimeZone($filterModel->getQueryTimezone()))
                    ->format(DateTimeZoneHelper::DATETIME_FORMAT);
            },
            'class' => DateRangeDoubleColumn::class,
        ],
        $agentIdColumn,
        $agentNameColumn,
        [
            'attribute' => 'duration_minute',
            'label' => 'Длительность, минуты',
            'value' => function (AiDialogRaw $raw) use ($integerFormat) {
                return $integerFormat(ceil($raw->duration / 60));
            }
        ] + $numericColumnOptions,
        [
            'attribute' => 'duration',
            'label' => 'Длительность, сек',
            'class' => IntegerRangeColumn::class,
            'value' => function (AiDialogRaw $raw) use ($integerFormat) {
                return $integerFormat($raw->duration);
            }
        ] + $numericColumnOptions,
    ]);
}

echo GridView::widget([
    'dataProvider' => $filterModel->search(),
    'filterModel' => $filterModel,
    'columns' => $columns,
]);

ActiveForm::end(); ?>
