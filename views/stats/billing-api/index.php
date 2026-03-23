<?php
/**
 * Статистика: Вызовы-API
 *
 * @var \app\classes\BaseView $this
 * @var BillingApiFilter $filterModel
 */

use app\classes\grid\column\universal\DateRangeDoubleColumn;
use app\classes\grid\column\universal\DropdownColumn;
use app\classes\grid\column\universal\IntegerRangeColumn;
use app\classes\grid\GridView;
use app\classes\Html;
use app\helpers\DateTimeZoneHelper;
use app\models\billing\api\ApiMethod;
use app\models\billing\api\ApiRaw;
use app\models\filter\BillingApiFilter;
use DateTimeImmutable;
use DateTimeZone;
use yii\helpers\Url;
use yii\widgets\ActiveForm;
use yii\widgets\Breadcrumbs;

echo Html::formLabel($this->title = 'Статистика: Вызовы-API');

echo Breadcrumbs::widget([
    'links' => [
        'Статистика',
        ['label' => $this->title, 'url' => $baseUrl = Url::toRoute('stats/billing-api')]
    ],
]);

$utcNow = new DateTimeImmutable('now', new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
$requestParams = Yii::$app->request->queryParams;
unset($requestParams['page'], $requestParams['sort']);

$filterParams = $requestParams[$filterModel->formName()] ?? [];
$buildPeriodUrl = function (string $dateFrom, string $dateTo) use ($baseUrl, $filterModel, $requestParams, $filterParams) {
    $requestParams[$filterModel->formName()] = array_merge($filterParams, [
        'connect_time_from' => $dateFrom,
        'connect_time_to' => $dateTo,
    ]);

    return Url::to(array_merge([$baseUrl], $requestParams));
};

$periodAnchor = new DateTimeImmutable(
    $filterModel->connect_time_from ?: $utcNow->format(DateTimeZoneHelper::DATE_FORMAT),
    new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC)
);
$previousMonth = $periodAnchor->modify('first day of previous month');
$currentPeriodMonth = $periodAnchor->modify('first day of this month');
$today = $utcNow->format(DateTimeZoneHelper::DATE_FORMAT);

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
            <div class="well">Итоговое потребление: <?=round($filterModel->getTotal(), 4)?></div>
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

<?php
?>

<div class="row" style="margin-bottom: 15px;">
    <div class="col-sm-4">
        <label style="font-weight: normal; margin-top: 7px;">
            <?= Html::activeCheckbox($filterModel, 'group_by_method', ['label' => false, 'value' => 1, 'uncheck' => 0]) ?>
            Группировка по API-методам
        </label>
    </div>
    <div class="col-sm-3">
        <?= Html::submitButton('Сформировать отчёт', ['class' => 'btn btn-primary btn-sm']) ?>
    </div>
</div>

<?php

if ($filterModel->isGroupByMethod()) {
    $columns = [
        [
            'attribute' => 'connect_time',
            'label' => 'Период, UTC',
            'class' => DateRangeDoubleColumn::class,
            'value' => function () use ($filterModel) {
                return $filterModel->connect_time_from . ' - ' . $filterModel->connect_time_to;
            }
        ],
        [
            'attribute' => 'api_method_id',
            'class' => DropdownColumn::class,
            'filter' => ApiMethod::getList(true),
            'value' => function (ApiRaw $row) {
                return $row->method ? $row->method->name : null;
            }
        ],
        [
            'attribute' => 'api_weight_total',
            'label' => 'Суммарный вес вызова',
            'value' => function (ApiRaw $row) {
                return $row->api_weight_total;
            }
        ],
        [
            'attribute' => 'cost_total',
            'label' => 'Суммарная стоимость',
            'value' => function (ApiRaw $row) {
                return round((float)$row->cost_total, 3);
            }
        ],
    ];
} else {
    $columns = [
        [
            'attribute' => 'connect_time',
            'class' => DateRangeDoubleColumn::class,
        ],
        [
            'attribute' => 'api_method_id',
            'class' => DropdownColumn::class,
            'filter' => ApiMethod::getList(true),
            'value' => function (ApiRaw $row) {
                return $row->method ? $row->method->name : null;
            }
        ],
        [
            'attribute' => 'api_weight',
            'class' => IntegerRangeColumn::class,
        ],
        [
            'attribute' => 'rate',
            'class' => IntegerRangeColumn::class,
        ],
        [
            'attribute' => 'cost',
            'class' => IntegerRangeColumn::class,
            'value' => function (ApiRaw $row) {
                return -$row->cost;
            }
        ]
    ];
}

echo GridView::widget([
    'dataProvider' => $filterModel->search(),
    'filterModel' => $filterModel,
    'columns' => $columns,
]);

ActiveForm::end(); ?>
