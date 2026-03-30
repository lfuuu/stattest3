<?php

/** @var $invoice \app\models\Invoice  * */
/** @var $isLocked bool */

use app\models\BillCorrection;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\ActiveForm;
use yii\widgets\Breadcrumbs;

echo Breadcrumbs::widget([
    'links' => [
        ['label' => 'Главная', 'utl' => '/'],
        ['label' => 'Аккаунт: ' . $invoice->bill->client_id, 'url' => Url::to(['/client/view', 'id' => $invoice->bill->client_id])],
        ['label' => 'Счет №' . $invoice->bill->bill_no, 'url' => $invoice->bill->getUrl()],
        ['label' => 'Редактировать']
    ],
]);

$form = ActiveForm::begin();
?>

    <h2>Создание корректирующей проводки</h2>
    <div class="well">
        <div class="row">
            <div class="col-sm-6">
                <?= $form->field($invoice, 'number')->textInput(['readonly' => true]) ?>
            </div>

            <div class="col-sm-6">
                <?= $form->field($invoice, 'type_id')->dropDownList(BillCorrection::$typeList, ['disabled' => true]) ?>
            </div>
        </div>
        <div class="row">
            <div class="col-sm-6">
                <div class="form-group">
                    <label>Платежно-расчетный документ (стр. 5)</label>
                    <?= Html::textInput(null, $invoice->upd_payment_number, ['class' => 'form-control input-sm', 'readonly' => true, 'id' => 'invoice-upd-payment-number']) ?>
                    <div style="margin-top: 8px;">
                        <?= Html::checkbox('Invoice[is_hide_payment_number]', $invoice->is_hide_payment_number, [
                            'id' => 'invoice-hide-payment-number',
                            'value' => 1,
                            'uncheck' => 0,
                            'label' => 'Не показывать п/п',
                        ]) ?>
                    </div>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="form-group">
                    <label>Авансовая с/ф (стр. 5б)</label>
                    <?= Html::textInput('Invoice[upd_advance_invoice]', $invoice->upd_advance_invoice, ['class' => 'form-control input-sm']) ?>
                </div>
            </div>
        </div>
        <?php if ($isLocked): ?>
            <div class="alert alert-info" style="margin-top: 10px;">
                Счёт‑фактура зарегистрирована. Можно редактировать строку 5б и переключать отображение строки 5.
            </div>
        <?php endif; ?>
    </div>
    <br>

    <?php $inputReadonly = $isLocked ? 'readonly' : ''; ?>
    <?php $inputDisabled = $isLocked ? 'disabled' : ''; ?>
    <table class="table table-condensed table-striped">
        <tr>
            <th width=1%>&#8470;</th>
            <th width=70%>Наименование</th>
            <th width=14%>Количество</th>
            <th width=15%>Цена</th>
            <th>
                Удаление
                <?php if (!$isLocked): ?>
                <input type="checkbox" id="mark_del"
                       onchange="if (this.checked) $('input.mark_del').attr('checked','checked'); else $('input.mark_del').removeAttr('checked');"
                />
                <?php endif; ?>
            </th>
        </tr>
        <?php
        /**
         * @var \app\models\InvoiceLine $line
         */
        foreach ($invoice->lines as $idx => $line) : ?>
            <tr>
                <td><?= $idx + 1 ?>.</td>
                <td><input class="form-control input-sm" value="<?= htmlspecialchars($line->item) ?>"
                           name=InvoiceLine[<?= $idx ?>][item] <?= $inputReadonly ?>></td>
                <td><input class="form-control input-sm" value="<?= $line->amount ?>"
                           name=InvoiceLine[<?= $idx ?>][amount] <?= $inputReadonly ?>></td>
                <td><input class="form-control input-sm" value="<?= $line->price ?>"
                           name=InvoiceLine[<?= $idx ?>][price] <?= $inputReadonly ?>></td>
                <td><input type="checkbox" class="mark_del" name="delete[<?= $idx ?>]" value="<?= $idx ?>" <?= $inputDisabled ?>/>
                </td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td>&nbsp;</td>
            <td>
                <input class="form-control input-sm" value="<?= htmlspecialchars($lineAdd->item) ?>"
                       name=InvoiceLineAdd[item] <?= $inputReadonly ?>></td>
            <td>
                <input class="form-control input-sm" value="<?= $lineAdd->amount ?>" name=InvoiceLineAdd[amount] <?= $inputReadonly ?>>
            </td>
            <td>
                <input class="form-control input-sm" value="<?= $lineAdd->price ?>" name=InvoiceLineAdd[price] <?= $inputReadonly ?>>
            </td>
            <td>&nbsp;</td>
        </tr>

    </table>
    <div style="text-align: center">
        <?= $this->render('//layouts/_buttonCancel', ['url' => $invoice->bill->getUrl()]) ?>
        <?= $this->render('//layouts/_submitButtonSave') ?>
    </div>

<?php ActiveForm::end() ?>

<?php
$this->registerJs(<<<JS
(function () {
    var hidePaymentCheckbox = $('#invoice-hide-payment-number');
    var paymentNumberInput = $('#invoice-upd-payment-number');

    var syncPaymentNumberState = function () {
        paymentNumberInput.prop('disabled', hidePaymentCheckbox.is(':checked'));
    };

    syncPaymentNumberState();
    hidePaymentCheckbox.on('change', syncPaymentNumberState);
})();
JS
);
?>
