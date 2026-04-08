<?php
/**
 * Бухгалтерия: Контроль закрывающих документов
 *
 * @var \app\classes\BaseView $this
 * @var \app\models\filter\accounting\ClosingDocumentCoverageFilter $filterModel
 * @var array|null $summary
 * @var \yii\data\SqlDataProvider|null $dataProvider
 */

use app\classes\grid\GridView;
use app\classes\Html;
use app\models\Organization;
use kartik\grid\ExpandRowColumn;
use DateTimeImmutable;
use app\helpers\DateTimeZoneHelper;
use DateTimeZone;
use yii\helpers\Url;
use yii\widgets\ActiveForm;
use yii\widgets\Breadcrumbs;

echo Html::formLabel($this->title = 'Контроль закрывающих документов');

echo Breadcrumbs::widget([
    'links' => [
        'Бухгалтерия',
        ['label' => $this->title, 'url' => $baseUrl = Url::toRoute('report/accounting/closing-document-coverage')],
    ],
]);

$requestParams = Yii::$app->request->queryParams;
unset($requestParams['page'], $requestParams['sort']);

$currentMonth = new DateTimeImmutable('first day of this month', new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
$previousMonth = $currentMonth->modify('first day of previous month');

$buildPeriodUrl = function (DateTimeImmutable $periodStart) use ($baseUrl, $requestParams, $filterModel) {
    $periodEnd = $periodStart->modify('first day of next month');
    $requestParams[$filterModel->formName()] = [
        'bill_date_from' => $periodStart->format(DateTimeZoneHelper::DATE_FORMAT),
        'bill_date_to' => $periodEnd->format(DateTimeZoneHelper::DATE_FORMAT),
        'service_date_from' => $periodStart->format(DateTimeZoneHelper::DATE_FORMAT),
        'service_date_to' => $periodEnd->format(DateTimeZoneHelper::DATE_FORMAT),
    ];

    return Url::to(array_merge([$baseUrl], $requestParams));
};

$currentMonthUrl = $buildPeriodUrl($currentMonth);
$previousMonthUrl = $buildPeriodUrl($previousMonth);
$organizationList = Organization::dao()->getList();

$form = ActiveForm::begin([
    'method' => 'get',
    'action' => $baseUrl,
]);
?>

<div style="margin-bottom: 15px;">
    Сформируйте отчёт за период:
    <?= Html::a('за текущий месяц', $currentMonthUrl) ?>,
    <?= Html::a('за прошлый месяц', $previousMonthUrl) ?>
</div>

<div class="row" style="margin-bottom: 15px;">
    <div class="col-sm-3">
        Дата счета с
        <?= Html::activeInput('date', $filterModel, 'bill_date_from', ['class' => 'form-control input-sm']) ?>
    </div>
    <div class="col-sm-3">
        Дата счета по
        <?= Html::activeInput('date', $filterModel, 'bill_date_to', ['class' => 'form-control input-sm']) ?>
    </div>
    <div class="col-sm-3">
        Период услуги с
        <?= Html::activeInput('date', $filterModel, 'service_date_from', ['class' => 'form-control input-sm']) ?>
    </div>
    <div class="col-sm-3">
        Период услуги по
        <?= Html::activeInput('date', $filterModel, 'service_date_to', ['class' => 'form-control input-sm']) ?>
    </div>
</div>

<div class="row" style="margin-bottom: 15px;">
    <div class="col-sm-3">
        <?= Html::submitButton('Сформировать отчёт', ['class' => 'btn btn-primary btn-sm', 'style' => 'margin-top: 20px']) ?>
    </div>
</div>

<?php if ($summary !== null): ?>
    <div style="margin-bottom: 15px;">
        Период счета: <?= Html::encode($filterModel->bill_date_from) ?> - <?= Html::encode($filterModel->bill_date_to) ?>,
        период услуги: <?= Html::encode($filterModel->service_date_from) ?> - <?= Html::encode($filterModel->service_date_to) ?>
    </div>

    <div class="row">
        <div class="col-sm-3">
            <div class="well">
                Всего строк в обработке: <?= number_format((int)$summary['processed_line_count'], 0, '.', ' ') ?>
            </div>
        </div>
        <div class="col-sm-3">
            <div class="well">
                Строк без УПД: <?= number_format((int)$summary['missing_upd_line_count'], 0, '.', ' ') ?>
            </div>
        </div>
        <div class="col-sm-3">
            <div class="well">
                Счетов: <?= number_format((int)$summary['bill_count'], 0, '.', ' ') ?>
            </div>
        </div>
        <div class="col-sm-3">
            <div class="well">
                Клиентов: <?= number_format((int)$summary['client_count'], 0, '.', ' ') ?>
            </div>
        </div>
    </div>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'columns' => [
            [
                'class' => ExpandRowColumn::class,
                'width' => '50px',
                'header' => '',
                'filter' => false,
                'value' => function () {
                    return GridView::ROW_COLLAPSED;
                },
                'detailUrl' => Url::toRoute(['report/accounting/closing-document-coverage/detail']),
                'detailRowCssClass' => GridView::TYPE_DEFAULT,
                'detailOptions' => ['class' => 'kv-state-enable'],
                'extraData' => [
                    'bill_date_from' => $filterModel->bill_date_from,
                    'bill_date_to' => $filterModel->bill_date_to,
                    'service_date_from' => $filterModel->service_date_from,
                    'service_date_to' => $filterModel->service_date_to,
                ],
                'contentOptions' => ['style' => 'text-align: center; vertical-align: middle; width: 50px;'],
                'headerOptions' => ['style' => 'width: 50px;'],
                'filterOptions' => ['style' => 'width: 50px;'],
            ],
            [
                'attribute' => 'bill_no',
                'label' => 'Счет',
                'format' => 'raw',
                'value' => function ($row) {
                    return Html::a(
                        $row['bill_no'],
                        '/?module=newaccounts&action=bill_view&bill=' . $row['bill_no'],
                        ['target' => '_blank']
                    );
                }
            ],
            [
                'attribute' => 'bill_date',
                'label' => 'Дата счета',
            ],
            [
                'attribute' => 'client_id',
                'label' => 'ЛС',
            ],
            [
                'attribute' => 'organization_id',
                'label' => 'Организация',
                'value' => function ($row) use ($organizationList) {
                    return $organizationList[(int)$row['organization_id']] ?? $row['organization_id'];
                }
            ],
            [
                'attribute' => 'missing_line_count',
                'label' => 'Строк без УПД',
                'contentOptions' => ['style' => 'text-align: right; white-space: nowrap;'],
            ],
            [
                'attribute' => 'missing_sum',
                'label' => 'Сумма без УПД',
                'contentOptions' => ['style' => 'text-align: right; white-space: nowrap;'],
                'value' => function ($row) {
                    return number_format((float)$row['missing_sum'], 2, '.', ' ');
                }
            ],
        ],
        'isFilterButton' => false,
    ]) ?>
<?php endif; ?>

<?php ActiveForm::end(); ?>
