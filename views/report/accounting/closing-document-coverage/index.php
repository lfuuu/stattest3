<?php
/**
 * Бухгалтерия: Контроль закрывающих документов
 *
 * @var \app\classes\BaseView $this
 * @var \app\models\filter\ClosingDocumentCoverageFilter $filterModel
 * @var array|null $summary
 * @var \yii\data\SqlDataProvider|null $dataProvider
 */

use app\classes\grid\GridView;
use app\classes\Html;
use app\models\ClientAccount;
use app\models\Organization;
use DateTimeImmutable;
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

$currentMonth = new DateTimeImmutable('first day of this month');
$previousMonth = $currentMonth->modify('first day of previous month');

$buildPeriodUrl = function (string $month) use ($baseUrl, $requestParams, $filterModel) {
    $requestParams[$filterModel->formName()] = [
        'month' => $month,
        'type_of_bill' => $filterModel->type_of_bill,
    ];

    return Url::to(array_merge([$baseUrl], $requestParams));
};

$currentMonthUrl = $buildPeriodUrl($currentMonth->format('Y-m'));
$previousMonthUrl = $buildPeriodUrl($previousMonth->format('Y-m'));
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
        Месяц
        <?= Html::activeInput('month', $filterModel, 'month', ['class' => 'form-control input-sm']) ?>
    </div>
    <div class="col-sm-3">
        Тип счета
        <?= Html::activeDropDownList(
            $filterModel,
            'type_of_bill',
            \app\models\filter\ClosingDocumentCoverageFilter::getTypeOfBillList(),
            ['class' => 'form-control input-sm']
        ) ?>
    </div>
    <div class="col-sm-3">
        <?= Html::submitButton('Сформировать отчёт', ['class' => 'btn btn-primary btn-sm', 'style' => 'margin-top: 20px']) ?>
    </div>
</div>

<?php if ($summary !== null): ?>
    <div style="margin-bottom: 15px;">
        Период отчета: <?= Html::encode($filterModel->getDateFrom()) ?> - <?= Html::encode($filterModel->getDateTo()) ?>
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
            'line_pk',
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
                'attribute' => 'type_of_bill',
                'label' => 'Тип счета',
                'value' => function ($row) {
                    return (int)$row['type_of_bill'] === (int)ClientAccount::TYPE_OF_BILL_DETAILED
                        ? 'Полный'
                        : 'Простой';
                }
            ],
            [
                'attribute' => 'item',
                'label' => 'Наименование',
            ],
            [
                'attribute' => 'sum',
                'label' => 'Сумма',
                'contentOptions' => ['style' => 'text-align: right; white-space: nowrap;'],
                'value' => function ($row) {
                    return number_format((float)$row['sum'], 2, '.', ' ');
                }
            ],
            [
                'attribute' => 'date_from',
                'label' => 'Период от',
            ],
            [
                'attribute' => 'date_to',
                'label' => 'Период до',
            ],
        ],
        'isFilterButton' => false,
    ]) ?>
<?php endif; ?>

<?php ActiveForm::end(); ?>
