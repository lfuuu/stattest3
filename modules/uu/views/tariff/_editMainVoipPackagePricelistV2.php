<?php
/**
 * Пакеты. Прайслист с МГП только V2 (billing_uu.pricelist)
 *
 * @var \app\classes\BaseView $this
 * @var \app\modules\uu\forms\TariffForm $formModel
 * @var \yii\widgets\ActiveForm $form
 * @var int $editableType
 * @var int $pricelistServiceTypeId  тип прайслиста из billing_uu.pricelist.service_type_id
 */

use app\modules\nnp\models\PackagePricelistNnp;
use app\modules\uu\controllers\TariffController;
use app\modules\uu\models\billing_uu\Pricelist as uuPricelist;
use kartik\editable\Editable;
use unclead\multipleinput\TabularColumn;
use app\widgets\TabularInput\TabularInput;

$packagePricelistNnp = new PackagePricelistNnp();
$attributeLabels = $packagePricelistNnp->attributeLabels();

$packagePricelistsNnp = $formModel->tariff->packagePricelistsNnp;

$isRemovePackagePricelistsV2 = false;
if (!$packagePricelistsNnp) {
    $packagePricelistsNnp = [$packagePricelistNnp];
    $isRemovePackagePricelistsV2 = true;
}

$this->registerJsVariable('isRemovePackagePricelistsV2', $isRemovePackagePricelistsV2);

$nnpPricelistList = uuPricelist::getList($isWithEmpty = true, $isWithNullAndNotNull = false, $pricelistServiceTypeId);

if ($editableType <= TariffController::EDITABLE_LIGHT) {
    $options = ['disabled' => 'disabled'];
    $btnOptions = ['class' => 'hide'];
} else {
    $btnOptions = $options = [];
}

if (\Yii::$app->user->can('tarifs.priceEdit')) {
    $options = $btnOptions = [];
}

$showHistory = '';
if (!$formModel->tariff->isNewRecord) {
    $showHistory = $this->render('//layouts/_showHistory', [
        'parentModel' => [new PackagePricelistNnp(), $formModel->tariff->id],
    ]);
}

?>

<div class="well package-pricelist">
    <h2>Прайс-лист</h2>

    <?= TabularInput::widget([
            'models' => array_values($packagePricelistsNnp),
            'allowEmptyList' => true,
            'addButtonOptions' => $btnOptions,
            'removeButtonOptions' => $btnOptions,

            'columns' => [
                [
                    'name' => 'nnp_pricelist_id',
                    'title' => $attributeLabels['nnp_pricelist_id'],
                    'type' => Editable::INPUT_SELECT2,
                    'options' => $options + [
                            'data' => $nnpPricelistList,
                        ],
                ],
                [
                    'name' => 'id',
                    'type' => TabularColumn::TYPE_HIDDEN_INPUT,
                ],
            ],
        ]
    )
    ?>

    <?= $showHistory ?>
</div>
