<?php
/**
 * Создание/редактирование оператора
 *
 * @var \app\classes\BaseView $this
 * @var Form $formModel
 */

use app\classes\Html;
use app\modules\nnp\forms\operator\Form;
use app\modules\nnp\models\Country;
use app\modules\nnp\models\Operator;
use kartik\select2\Select2;
use yii\helpers\Url;
use yii\widgets\ActiveForm;
use yii\widgets\Breadcrumbs;

$operator = $formModel->operator;

if (!$operator->isNewRecord) {
    $this->title = ['label' => $operator->name, 'url' => $operator->getUrl()];
} else {
    $this->title = Yii::t('common', 'Create');
}
?>

<?= Breadcrumbs::widget([
    'links' => [
        ['label' => 'Национальный номерной план', 'url' => '/nnp/'],
        ['label' => 'Операторы', 'url' => $cancelUrl = '/nnp/operator/'],
        $operator->country_code ? ['label' => $operator->country,  'url' => $cancelUrl = ['/nnp/operator/', 'OperatorFilter' => ['country_code' => $formModel->operator->country_code]]] : ['label' => 'Добавление оператора'],
        $this->title
    ],
]) ?>

<div class="well">
    <?php
    $form = ActiveForm::begin();
    $viewParams = [
        'formModel' => $formModel,
        'form' => $form,
    ];
    ?>

    <div class="row">

        <?php // Страна ?>
        <div class="col-sm-2">
            <?= $form->field($operator, 'country_code')->widget(Select2::class, [
                'data' => Country::getList($isWithEmpty = true, $isWithNullAndNotNull = false, $indexBy = 'code'),
            ]) ?>
            <div>
                <?= ($country = $operator->country) ?
                    Html::a($country->name_rus, $country->getUrl()) :
                    '' ?>
            </div>
        </div>

        <?php // Название ?>
        <div class="col-sm-4">
            <?= $form->field($operator, 'name')->textInput() ?>
        </div>

        <?php // Название транслитом ?>
        <div class="col-sm-3">
            <?= $form->field($operator, 'name_translit')->textInput() ?>
        </div>

        <?php // Кол-во ?>
        <div class="col-sm-3">
            <label><?= $operator->getAttributeLabel('cnt') ?></label>
            <div>
                <?= $operator->cnt . ' (' .
                Html::a(
                    'оператор (диапазон)',
                    Url::to(['/nnp/number-range/', 'NumberRangeFilter[country_code]' => $operator->country_code, 'NumberRangeFilter[operator_id]' => $operator->id])
                ) . ', ' .
                Html::a(
                    'ориг. оператор',
                    Url::to(['/nnp/number-range/', 'NumberRangeFilter[country_code]' => $operator->country_code, 'NumberRangeFilter[orig_operator_id]' => $operator->id])
                ) . ', ' .
                Html::a(
                    'портированные',
                    Url::to(['/nnp/number/', 'NumberFilter[country_code]' => $operator->country_code, 'NumberFilter[operator_id]' => $operator->id])
                ) . ')' ?>
            </div>
            <br>
            <label><?= $operator->getAttributeLabel('cnt_active') ?></label>
            <div>
                <?= $operator->cnt_active . ' (' .
                Html::a(
                    'диапазон',
                    Url::to(['/nnp/number-range/',
                        'NumberRangeFilter[country_code]' => $operator->country_code,
                        'NumberRangeFilter[operator_id]' => $operator->id,
                        'NumberRangeFilter[is_active]' => 1,
                    ])
                ) . ')' ?>
            </div>
        </div>

    </div>

    <div class="row">
        <?php // Группа оператора ?>
        <div class="col-sm-3">
            <?= $form->field($operator, 'group')->widget(Select2::className(), [
                'data' => ['' => '- Все -'] + Operator::$groups,
            ]) ?>
        </div>

        <?php // partner code ?>
        <div class="col-sm-2">
            <?= $form->field($operator, 'partner_code')->textInput() ?>
        </div>


        <?php // operator src code ?>
        <div class="col-sm-2">
            <?php

            if (!is_array($operator->operator_src_code)) {
                $operator->operator_src_code = explode(',', $operator->operator_src_code);
            }

            ?>
            <?= $form->field($operator, 'operator_src_code')->widget(\unclead\multipleinput\MultipleInput::class, [
                'min' => 1,
                'max' => 4,
                'columns' => [
                    [
                        'name' => 'src_code',
                        'type' => \kartik\editable\Editable::INPUT_TEXT,
                    ],
                ],
            ]) ?>
        </div>

        <?php // if valid  ?>
        <?php $childs = $operator->childs; ?>
        <div class="col-sm-3">
            <br />
            <?= $form->field($operator, 'is_valid')->checkbox($childs ? ['disabled' => true] : []) . ($childs ? $form->field($operator, 'is_valid')->hiddenInput()->label('') : '')?>
        </div>
    </div>
    <div class="row">
        <?php // parent_id  ?>
        <div class="col-sm-2">
            <?php
            $operatorList = Operator::getList($isWithEmpty = true, $isWithNullAndNotNull = false, $operator->country_code, null, true);
            if ($operator->id) {
                unset($operatorList[$operator->id]);
            }
            ?>
            <?= $form->field($operator, 'parent_id')->widget(Select2::class, [
                'data' => $operatorList,
            ]) ?>
        </div>
        <div class="col-sm-2">
            <?php
            if ($parent = $operator->parent) {
                echo Html::a(
                    ' перейти к родителю ',
                    Url::to($parent->getUrl())
                );
            }

            if ($childs) {
                echo '<br><br>Синонимы: <br />';

                $i = 0;
                foreach ($childs as $child) {
                    echo ++$i . '. ' . Html::a(
                            strval($child),
                            Url::to($child->getUrl())
                        ) . '<br />';
                }
            }

            ?>
        </div>

        <?php // mnc ?>
        <div class="col-sm-2">
            <?= $form->field($operator, 'mnc')->textInput() ?>
        </div>

        <?php // uvr_operator_id ?>
        <div class="col-sm-2">
            <?= $form->field($operator, 'uvr_operator_id')->textInput() ?>
        </div>

        <?php // bdpn_code ?>
        <div class="col-sm-2">
            <?= $form->field($operator, 'bdpn_code')->textInput() ?>
        </div>

        <?php // taxpayer_identification_number ?>
        <div class="col-sm-2">
            <?= $form->field($operator, 'taxpayer_identification_number')->textInput() ?>
        </div>

    </div>

    <?php // кнопки ?>
    <div class="form-group text-right">
        <?= $this->render('//layouts/_buttonCancel', ['url' => $cancelUrl]) ?>
        <?= $this->render('//layouts/_submitButton' . ($operator->isNewRecord ? 'Create' : 'Save')) ?>
    </div>

    <?php if (!$operator->isNewRecord && $country) : ?>
        <div class="row">
            <div class="col-sm-2">
                <?= $this->render('//layouts/_submitButtonDrop') ?> &nbsp;, заменив на
            </div>
            <div class="col-sm-4">
                <?php
                $operatorsList = Operator::getList($isWithEmpty = true, $isWithNullAndNotNull = false, $country->code, 0);
                unset($operatorsList[$operator->id]); // убрать себя
                ?>
                <?= Select2::widget([
                    'name' => 'newOperatorId',
                    'data' => $operatorsList,
                ]) ?>
            </div>
        </div>
    <?php endif ?>

    <?php ActiveForm::end(); ?>
    <?php if (!$operator->isNewRecord) : ?>
        <?= $this->render('//layouts/_showHistory', ['model' => $operator]) ?>
    <?php endif; ?>
</div>
