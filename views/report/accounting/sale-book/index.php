<?php

use app\helpers\DateTimeZoneHelper;
use app\models\ClientContragent;
use app\models\filter\SaleBookFilter;

/** @var SaleBookFilter $filter */
/** @var array $skipping_bps */

$printSum = function ($sum, $len = 2) {
    return str_replace(".", ",", sprintf("%0." . $len . "f", $sum));
};


?>
<form style="display:inline" action="/report/accounting/sale-book" class="form-vertical">
    <div class="row">
        <div class="col-sm-1">
            <label class="control-label">От:</label>
            <input id="date_from" type="text" name="SaleBookFilter[date_from]" value="<?= $filter->date_from ?>"
                   class="form-control"/>
        </div>
        <div class="col-sm-1">
            <label class="control-label">До:</label>
            <input id="date_to" type="text" name="SaleBookFilter[date_to]" value="<?= $filter->date_to ?>"
                   class="form-control"/>
        </div>
        <div class="col-sm-1">
            <?=\app\classes\Html::activeCheckbox($filter, 'is_euro_format')?>
        </div>
        <div class="col-sm-1">
            <?=\app\classes\Html::activeCheckbox($filter, 'is_register')?>
        </div>
        <div class="col-sm-1">
            <?=\app\classes\Html::activeCheckbox($filter, 'is_register_vp')?>
        </div>
        <div class="col-sm-1">
            <?=\app\classes\Html::activeCheckbox($filter, 'is_invoice_off')?>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-3">
            <label class="control-label">Компания:</label>
            <?= \app\classes\Html::dropDownList('SaleBookFilter[organization_id]', $filter->organization_id, \app\models\Organization::dao()->getList(), ['class' => 'select2']) ?>
        </div>
        <div class="col-sm-3">
            <label class="control-label">Валюта:</label>
            <?= \app\classes\Html::dropDownList('SaleBookFilter[currency]', $filter->currency, \app\models\Currency::getList(true), ['class' => 'select2']) ?>
        </div>
    </div>
    <div class="row">
        <div class="col-sm-3">
            <label class="control-label">
                <input type="checkbox" name="is_excel" value="1"/>в Excel
            </label>
            <?php if ($filter->is_euro_format): ?>
            <br>
            <label class="control-label">
                <input type="checkbox" name="is_excel_eu_bmd" value="1"/>в Excel (BMD)
            </label>
            <?php endif; ?>
            <div>
            </div>

            <div class="row">
            </div>

            <?php if (false) { ?>
                Фильтр: <?= \app\classes\Html::dropDownList('SaleBookFilter[filter]', $filter->filter, \app\models\filter\SaleBookFilter::$filters) ?>
            <?php } ?>

            <!-- Полный экран: <input type="checkbox" name="fullscreen" value="1"/>&nbsp;
            в Excel: <input type="checkbox" name="excel" value="1"/>
            -->
            <br/>
            <input type="submit" value="Показать" class="btn btn-primary" name="do"/>
</form>

<?=$this->render(($filter->is_register || $filter->is_register_vp ? 'register' :
                ($filter->is_euro_format ? 'format_eu' :
                    'format_ru')), ['filter' => $filter, 'printSum' => $printSum])?>

<script type="text/javascript">
  optools.DatePickerInit();
</script>
