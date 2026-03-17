<?php
/**
 * Вознаграждения партнеров
 *
 * @var \app\classes\BaseView $this
 * @var PartnerRewardsNewFilter $filterModel
 */

use app\classes\Html;
use app\models\ClientContract;
use app\models\filter\PartnerRewardsNewFilter;
use app\widgets\MonthPicker;
use kartik\widgets\Select2;
use yii\helpers\Url;
use yii\widgets\ActiveForm;
use yii\widgets\Breadcrumbs;

echo Html::formLabel($this->title = 'Вознаграждения партнеров');

echo Breadcrumbs::widget([
    'links' => [
        'Статистика',
        ['label' => $this->title, 'url' => $baseUrl = Url::toRoute('stats/partner-rewards-new')]
    ],
]);
?>

<div class="row">
    <?php $form = ActiveForm::begin(['method' => 'get', 'action' => $baseUrl]) ?>
    <div class="row">
        <div class="col-sm-1 text-right">
            Партнер:
        </div>
        <div class="col-sm-3">
            <?= Select2::widget([
                'name' => 'filter[partner_contract_id]',
                'data' => ClientContract::dao()->getPartnerList(),
                'value' => $filterModel->partner_contract_id,
                'options' => [
                    'placeholder' => '-- Выбрать партнера --',
                ],
            ]);
            ?>
        </div>

        <div class="col-sm-2">
            <?= Html::submitButton('Сформировать', ['class' => 'btn btn-primary']) ?>
        </div>
    </div>
    <div class="row" style="margin-top: 10px;">
        <div class="col-sm-1 text-right">
            Дата оплаты с:
        </div>
        <div class="col-sm-2">
            <?= MonthPicker::widget([
                'name' => 'filter[payment_date_before]',
                'value' => $filterModel->payment_date_before,
                'options' => [
                    'class' => 'form-control input-sm',
                ],
            ]) ?>
        </div>
        <div class="col-sm-1 text-right">
            Дата оплаты по:
        </div>
        <div class="col-sm-2">
            <?= MonthPicker::widget([
                'name' => 'filter[payment_date_after]',
                'value' => $filterModel->payment_date_after,
                'options' => [
                    'class' => 'form-control input-sm',
                ],
            ]) ?>
        </div>
        <div class="col-sm-4" style="padding-top: 6px;">
            <?= Html::hiddenInput('filter[show_zero_rewards]', 0) ?>
            <label style="font-weight: normal;">
                <?= Html::checkbox('filter[show_zero_rewards]', $filterModel->show_zero_rewards, ['value' => 1]) ?>
                Показать клиентов с нулевым вознаграждением
            </label>
        </div>
    </div>
    <?php ActiveForm::end(); ?>
</div>
<?php
if ($filterModel->partner_contract_id) {
    $documentIssue = $filterModel->getDocumentExportIssue();
    $documentRouteParams = array_merge(
        ['stats/partner-rewards-new/document'],
        Yii::$app->request->get()
    );
    ?>
    <div class="row" style="margin-top: 15px;">
        <div class="col-sm-12">
            <?php if ($filterModel->canExportDocument()) : ?>
                <?= Html::a(
                    'Скачать XLSX',
                    array_merge($documentRouteParams, ['format' => 'xlsx']),
                    ['class' => 'btn btn-success']
                ) ?>
                <?= Html::a(
                    'Скачать PDF',
                    array_merge($documentRouteParams, ['format' => 'pdf']),
                    ['class' => 'btn btn-default', 'style' => 'margin-left: 10px;']
                ) ?>
            <?php elseif ($documentIssue) : ?>
                <div class="alert alert-warning" style="margin-bottom: 15px;">
                    <?= $documentIssue ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    echo $this->render(('_grid'), [
        'filterModel' => $filterModel,
    ]);
}
?>
