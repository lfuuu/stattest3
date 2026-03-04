<?php

use app\classes\Html;
use app\dao\PartnerDao;
use app\models\ClientContract;
use app\models\ClientContragent;
use app\models\Country;
use app\models\Language;
use app\models\SaleChannel;
use kartik\builder\Form;
use kartik\widgets\Select2;

/**
 * @var $f kartik\widgets\ActiveForm
 * @var $model \app\forms\client\ContragentEditForm
 */

$codeOpfList = ['0' => ''] + \app\models\CodeOpf::getList($isWithEmpty = false);
$isDisabled = (bool)$model->contragent->is_lk_first;
$optionState = $isDisabled ? ['disabled' => true] : [];
?>

<?php
if ($isDisabled):
    $importLkStatus = $model->contragent->importLkStatus;
    ?>
    <div class="row max-screen">
        <div class="col-sm-12 text-center text-warning">
            <span class="glyphicon glyphicon-warning-sign"></span>&nbsp;<span>Редактирование основных данных контрагента доступно только в ЛК</span>
            <?= ($importLkStatus ? '<br><span class="text-'.($importLkStatus->status_code == 'ok' ? 'info' : 'warning').'"><small>Последнее обновление было: ' . \app\helpers\DateTimeZoneHelper::getDateTime($importLkStatus->updated_at) . '</small></span>' : '') ?>
            <?= ($importLkStatus && $importLkStatus->status_code != 'ok'? '<br><span class="text-danger">Ошибка импорта данных с ЛК: ' . $importLkStatus->status_text . '</span>' : '') ?>
            <br>
            <br>
        </div>
    </div>

<?php
endif;
?>

<div class="row max-screen">
    <div class="col-sm-6">
        <?= $f->field($model, 'country_id')->dropDownList(Country::getList()); ?>
    </div>
    <div class="col-sm-6">
        <?= $f->field($model, 'lang_code')->dropDownList(Language::getList()); ?>
    </div>
</div>

<div class="col-sm-8 text-center bottom-indent">
    <div id="type-select">
        <div class="btn-group">
            <button type="button" class="btn btn-default"
                    data-tab="#legal"<?= ($isDisabled ? ' disabled' : '') ?>><?= $model->getAttributeLabel('legalTypeLegal') ?></button>
            <button type="button" class="btn btn-default"
                    data-tab="#ip"<?= ($isDisabled ? ' disabled' : '') ?>><?= $model->getAttributeLabel('legalTypeIp') ?></button>
            <button type="button" class="btn btn-default"
                    data-tab="#person"<?= ($isDisabled ? ' disabled' : '') ?>><?= $model->getAttributeLabel('legalTypePerson') ?></button>
        </div>
    </div>
</div>
<?= Html::activeHiddenInput($model, 'legal_type') ?>
<?= Html::activeHiddenInput($model, 'super_id') ?>
<div id="fs-tabs" class="row max-screen">
    <div id="legal" class="tab-pane col-sm-12">
        <?php
        echo Form::widget([
            'model' => $model,
            'form' => $f,
            'columns' => 2,
            'options' => ['class' => 'percent100'] + $optionState,
            'columnOptions' => ['class' => 'col-sm-6'],
            'attributeDefaults' => [
                'type' => Form::INPUT_TEXT
            ],
            'attributes' => [
                'name' => [],
                'address_jur' => [],
                'name_full' => [],
                'post_address_filial' => [],
            ],
        ]);
        
        if (\Yii::$app->isRus()) {
            echo Form::widget([
                'model' => $model,
                'form' => $f,
                'columns' => 2,
                'columnOptions' => ['class' => 'col-sm-6'],
                'options' => ['class' => 'pull-left percent50 block-right-indent'] + $optionState,
                'attributeDefaults' => [
                    'type' => Form::INPUT_TEXT
                ],
                'attributes' => [
                    'inn' => [],
                    'kpp' => [],
                    'okvd' => [],
                    'ogrn' => [],
                    'opf_id' => [
                        'type' => Form::INPUT_DROPDOWN_LIST,
                        'items' => $codeOpfList,
                    ],
                    'okpo' => [],
                ],
            ]);    
        }

        $attrs = [
            'tax_regime' => [
                'type' => Form::INPUT_DROPDOWN_LIST,
                'items' => ClientContragent::$taxRegtimeTypes,
                'container' => []],
            'inn_euro' => [],
        ];
        if (\Yii::$app->isEu()) {
            $attrs['inn'] = [];
            $attrs['ogrn'] = [];
        }
        echo Form::widget([
            'model' => $model,
            'form' => $f,
            'columns' => 2,
            'options' => ['class' => 'pull-right percent50 block-left-indent'] ,// + $optionState,
            'attributeDefaults' => [
                'type' => Form::INPUT_TEXT
            ],
            'attributes' => $attrs,
            'contentAfter' =>  Form::widget([
                'model' => $model,
                'form' => $f,
                'columns' => 1,
                'options' => ['class' => ''] ,//+ $optionState,
                'attributeDefaults' => [
                    'type' => Form::INPUT_TEXT
                ],
                'attributes' => [
                    'tax_registration_reason' => [
                        'options' => $optionState,
                    ],
    
                    'position' => ['options' => $optionState,],
                    'fio' => ['options' => $optionState,],
//                    'is_take_signatory' => [
//                        'type' => Form::INPUT_CHECKBOX,
//                    ],
//                    'signatory_position' => [
//                        'options' => ['disabled' => !$model->is_take_signatory],
//                    ],
//                    'signatory_fio' => [
//                        'options' => ['disabled' => !$model->is_take_signatory],
//                    ],
                ],
                'contentAfter' =>  Form::widget([
                    'model' => $model,
                    'form' => $f,
                    'columns' => 1,
                    'options' => ['class' => ''],// + $optionState,
                    'attributeDefaults' => [
                        'type' => Form::INPUT_TEXT
                    ],
                    'attributes' => [
//                        'tax_registration_reason' => [],

//                        'position' => [],
//                        'fio' => [],
                        'is_take_signatory' => [
                            'type' => Form::INPUT_CHECKBOX,
                        ],
                        'signatory_position' => [
                            'options' => ['disabled' => !$model->is_take_signatory],
                        ],
                        'signatory_fio' => [
                            'options' => ['disabled' => !$model->is_take_signatory],
                        ],
                    ],
                ])
            ])
        ]);
        unset($attrs);
        ?>
    </div>

    <div id="ip" class="tab-pane col-sm-12">
        <?php
        echo Form::widget([
            'model' => $model,
            'form' => $f,
            'columns' => 1,
            'options' => ['class' => 'pull-left percent50 block-right-indent'] + $optionState,
            'attributeDefaults' => [
                'type' => Form::INPUT_TEXT
            ],
            'attributes' => [
                'last_name' => [],
                'first_name' => [],
                'middle_name' => [],
            ],
        ]);
        echo Form::widget([
            'model' => $model,
            'form' => $f,
            'columns' => 1,
            'columnOptions' => ['class' => 'col-sm-12'],
            'options' => ['class' => 'percent50 block-left-indent'] + $optionState,
            'attributeDefaults' => [
                'type' => Form::INPUT_TEXT
            ],
            'attributes' => [
                'address_jur' => [],
                'address_registration_ip' => [],
            ],
        ]);

        echo Form::widget([
            'model' => $model,
            'form' => $f,
            'columns' => 1,
            'columnOptions' => ['class' => 'col-sm-12'],
            'options' => ['class' => 'percent50 block-left-indent'] + $optionState,
            'attributeDefaults' => [
                'type' => Form::INPUT_TEXT
            ],
            'attributes' => [
                'tax_regime' => [
                    'type' => Form::INPUT_DROPDOWN_LIST,
                    'items' => ClientContragent::$taxRegtimeTypes,
                    'container' => ['class' => 'percent50']
                ],
            ],
        ]);

        echo Form::widget([
            'model' => $model,
            'form' => $f,
            'columns' => 2,
            'options' => ['class' => 'clearix percent50 block-right-indent'] + $optionState,
            'columnOptions' => ['class' => 'col-sm-6'],
            'attributeDefaults' => [
                'container' => [
                    'type' => Form::INPUT_TEXT
                ],
            ],
            'attributes' => [
                'inn' => [],
                'ogrn' => [],
                'opf_id' => [
                    'type' => Form::INPUT_DROPDOWN_LIST,
                    'items' => $codeOpfList,
                ],
                'okpo' => [],
            ],
        ]);
        ?>
    </div>

    <div id="person" class="tab-pane col-sm-12">
        <?php
        echo Form::widget([
            'model' => $model,
            'form' => $f,
            'columns' => 1,
            'options' => ['class' => 'pull-left percent50 block-right-indent'] + $optionState,
            'attributeDefaults' => [
                'type' => Form::INPUT_TEXT
            ],
            'attributes' => [
                'last_name' => [],
                'first_name' => [],
                'middle_name' => [],
                'inn' => [],
            ],
        ]);
        echo Form::widget([
            'model' => $model,
            'form' => $f,
            'columns' => 2,
            'columnOptions' => ['class' => 'col-sm-6'],
            'options' => ['class' => 'percent50 block-left-indent block-right-indent'] + $optionState,
            'attributeDefaults' => [
                'type' => Form::INPUT_TEXT
            ],
            'attributes' => [
                'passport_serial' => [],
                'passport_number' => [],
            ],
        ]);
        echo Form::widget([
            'model' => $model,
            'form' => $f,
            'columns' => 2,
            'columnOptions' => ['class' => 'col-sm-12'],
            'options' => ['class' => 'percent50 block-left-indent block-right-indent'] + $optionState,
            'attributeDefaults' => [
                'type' => Form::INPUT_TEXT
            ],
            'attributes' => [
                'passport_date_issued' => [
                    'type' => Form::INPUT_WIDGET,
                    'widgetClass' => 'app\widgets\DateControl',
                    'convertFormat' => true,
                    'options' => [
                        'disabled' => $isDisabled,
                        'pluginOptions' => [
                            'autoclose' => true,
                            'startDate' => '-40y',
                            'endDate' => '+1y',
                        ],
                    ],
                ],
                'birthday' => [
                    'type' => Form::INPUT_WIDGET,
                    'widgetClass' => 'app\widgets\DateControl',
                    'convertFormat' => true,
                    'options' => [
                        'disabled' => $isDisabled,
                        'pluginOptions' => [
                            'autoclose' => true,
                            'startDate' => '-100y',
                            'endDate' => '0y',
                        ],
                    ],
                ],
                'passport_issued' => ['columnOptions' => ['colspan' => 2]],
                ['type' => Form::INPUT_RAW],
                'registration_address' => ['columnOptions' => ['colspan' => 2]],
                'birthplace' => ['columnOptions' => ['colspan' => 2]],
            ],
        ]);
        ?>
    </div>

    <div class="partner-block">

        <div class="col-sm-6">
            <?= $f->field($model, 'comment')
                ->textarea(['style' => 'height: 100px;']) ?>
        </div>

        <div class="col-sm-3 bottom-indent right-indent">
            <?=
            $f->field($model, 'sale_channel_id')
                ->widget(Select2::class, [
                    'data' => SaleChannel::getList($isWithEmpty = true),
                ])
            ?>
        </div>

        <div class="col-sm-3 bottom-indent right-indent">
            <?=
            $f->field($model, 'branch_code')
                ->textInput()
            ?>
            <?=
            $f->field($model, 'inn_person')
                ->textInput()
                ->label('ИНН Физического Лица')
            ?>
        </div>

    </div>
</div>