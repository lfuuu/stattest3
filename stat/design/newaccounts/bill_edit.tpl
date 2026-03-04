<ul class="breadcrumb">
    <li>
        <a href="/">Главная</a>
    </li>
    <li>
        <a href="/client/view?id={$bill.client_id}">Аккаунт: {$bill.client_id}</a>
    </li>
    <li>
        <a href="/?module=newaccounts&action=bill_view&bill={$bill.bill_no}">Счет №{$bill.bill_no}</a>
    </li>
    <li>
        <a href="?module=newaccounts&action=bill_edit&bill={$bill.bill_no}">Редактирование</a>
    </li>

</ul>

<h2>Бухгалтерия {$fixclient}</h2>
<H3>Редактирование проводки</H3>
<form action="?" method=post id=form name=form enctype="multipart/form-data">
    <input type=hidden name=module value=newaccounts>
    <input type=hidden name=bill value={$bill.bill_no}>
    <input type=hidden name=action value=bill_apply>
    <input type=hidden name=client_id value={$bill.client_id}>
    
    <div class="well">
        <div class="row">
            <div class="col-sm-4">
                <div class="form-group">
                    <label>Дата проводки:</label>
                    <input class="form-control input-sm" type=text id=bill_date_from name=bill_date
                           value="{$bill_date}">
                </div>
            </div>

            <div class="col-sm-4">
                <div class="form-group">
                    <label>Валюта проводки: </label>
                    <input class="form-control input-sm" type=text value="{$bill.currency}" readonly>
                </div>
            </div>

            {if $show_bill_no_ext || access('newaccounts_bills', 'edit_ext')}
                <div class="col-sm-2">
                    <div class="form-group">
                        <label>Внешний счет: </label>
                        <input class="form-control input-sm" type=text name=bill_no_ext value="{$bill_ext.ext_bill_no}">
                    </div>
                </div>
                <div class="col-sm-2">
                    <div class="form-group">
                        <label>Дата внешнего счета:</label>
                        <div class="form-group ">
                            <input class="form-control input-sm"
                                   id=date_from type=text name=bill_no_ext_date
                                   value="{$bill_ext.ext_bill_date}">
                        </div>
                    </div>
                </div>
                {if $is_correction}
                    <div class="col-sm-2">
                        <div class="form-group">
                            <label>Дата правки:</label>
                            <div class="form-group ">
                                <input class="form-control input-sm"
                                    id=date_created type=text name=date_created
                                    value="{$corr_bill}">
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-2">
                        <div class="form-group">
                            <label>Номер правки:</label>
                            <div class="form-group ">
                                <input class="form-control input-sm"
                                    id=corr_number type=text name=corr_number
                                    value="{$corr_number}">
                            </div>
                        </div>
                    </div>
                {/if}
            {else}
                <div class="col-sm-4">&nbsp;</div>
            {/if}
        </div>

        <div class="row">
            <div class="col-sm-4">
                <div class="form-group">
                    <label>Оплатить до:</label>
                    <input class="form-control input-sm" type=text id=pay_bill_until name=pay_bill_until
                           value="{$pay_bill_until}">
                </div>
            </div>

            <div class="col-sm-4">
                <div class="form-group">
                    <label>Предполагаемый тип платежа: </label>
                    <select name="nal" class="form-control">
                        <option value="beznal"{if $bill.nal=="beznal"} selected{/if}>безнал</option>
                        <option value="nal"{if $bill.nal=="nal"} selected{/if}>нал</option>
                        <option value="prov"{if $bill.nal=="prov"} selected{/if}>пров</option>
                    </select>
                </div>
            </div>

            {if $show_bill_no_ext || access('newaccounts_bills', 'edit_ext')}
                <div class="col-sm-2">
                    <div class="form-group">
                        <label>Номер внешнего акта:</label>
                        <input type="text" class="form-control input-sm" name="akt_no_ext"
                               value="{$bill_ext.ext_akt_no}">
                    </div>
                </div>
                <div class="col-sm-2">
                    <div class="form-group">
                        <label>Дата внешнего акта: </label>
                        <input type="text" class="form-control input-sm" name="akt_date_ext" id="akt_date_ext"
                               value="{$bill_ext.ext_akt_date}">
                    </div>
                </div>
            {else}
                <div class="col-sm-4">&nbsp;</div>
            {/if}
        </div>

        <div class="row">
            <div class="col-sm-2">
                <div class="form-group">
                    <label>Исполнитель:</label>
                    {html_options name='courier' options=$l_couriers selected=$bill.courier_id}
                </div>
            </div>

            <div class="col-sm-4">
                <div class="form-group">
                    <label>
                        Цена включает НДС
                        <input type="checkbox" value="Y"
                               name="price_include_vat" {if $bill.price_include_vat > 0} checked{/if}>
                    </label>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="form-group">
                    <label>
                        Показывать счет в ЛК
                        <input type="checkbox" value="Y"
                               name="is_show_in_lk" {if $bill.is_show_in_lk} checked{/if}>
                    </label>
                </div>
            </div>
            {if !$bill.uu_bill_id && $clientAccountVersion == 5}
                <div class="col-sm-4">
                    <div class="form-group">
                        <label>Включить в У-с/ф:</label>
                        <input type="checkbox" value="Y"
                               name="is_to_uu_invoice" {if $bill.is_to_uu_invoice} checked{/if}>
                    </div>
                </div>
            {/if}
        </div>

        {if access('newaccounts_bills', 'invoice_date')}
            <div class="row">
                <div class="col-sm-4">
                    <div class="form-group">
                        <label>Новая дата драфтов счёт-фактур и актов:</label>
                        <input class="form-control input-sm" type=text id=drafted_invoices_new_date {if !$draftedInvoices}disabled=disabled{else}name=drafted_invoices_new_date{/if}
                               value="{if $bill.invoice_date}{$bill.invoice_date|mdate:"d-m-Y"}{else}{/if}">
                    </div>
                </div>
                <div class="col-sm-5">
                    {if count($drafted_invoice_dates_history)}
                        {foreach from=$drafted_invoice_dates_history item=L key=key name=outer}
                            {if $L.comment|strstr:"Дата счёт-фактур и актов обновлена. Новая дата: "}
                                <b>{$L.ts|udate_with_timezone} - {$L.user}</b>
                                : {$L.comment}
                                <br>
                            {/if}
                        {/foreach}
                    {/if}
                </div>
            </div>
        {/if}

        {if $show_bill_no_ext || access('newaccounts_bills', 'edit_ext')}
            <div class="row">
                <div class="col-sm-2">
                    <div class="form-group">
                        <label>Дата регистр. c/ф:</label>
                        <div class="form-group">
                            <input class="form-control input-sm"
                                   id="registration_date_ext" type="text" name="registration_date_ext"
                                   value="{$bill_ext.ext_registration_date}">
                        </div>
                    </div>
                </div>

                <div class="col-sm-4">
                    <div class="form-group">
                        <label>Номер внешней с/ф:</label>
                        <input type="text" class="form-control input-sm" name="invoice_no_ext"
                               value="{$bill_ext.ext_invoice_no}">
                    </div>
                </div>
                <div class="col-sm-2">
                    <div class="form-group">
                        <label>Дата внешней с/ф:</label>
                        <div class="form-group">
                            <input class="form-control input-sm"
                                   id=invoice_ext_date type=text name=invoice_date_ext
                                   value="{$bill_ext.ext_invoice_date}">
                            <script>
                                {literal}
                                    $('#invoice_ext_date').each(function() {$(this).setMask('39-19-2099')});
                                    $('#registration_date_ext').each(function() {$(this).setMask('39-19-2099')});
                                {/literal}
                            </script>
                        </div>
                    </div>
                </div>
                <div class="col-sm-2">
                    <div class="form-group">
                        <label>Сумма без НДС из с/ф постав.:</label>
                        <input type="text" class="form-control input-sm" name="ext_sum_without_vat"
                               value="{$bill_ext.ext_sum_without_vat}">
                    </div>
                </div>
                <div class="col-sm-2">
                    <div class="form-group">
                        <label>НДС из с/ф поставщика:</label>
                        <input type="text" class="form-control input-sm" name="ext_vat"
                               value="{$bill_ext.ext_vat}">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-2">
                    <div class="form-group">
                        <label>Файл</label>
                        <div class="form-group">
                            <input type="file" name="bill_ext_file" class="form-control input-sm">
                        </div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="form-group">
                        <label>Комментарий к файлу</label>
                        <div class="form-group">
                            <input type="text" name="bill_ext_file_comment" class="form-control input-sm" value="{$bill_ext_file.comment}">
                        </div>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="form-group">
                        <label>Скачать файл</label>
                        <div class="form-group text-center">
                            {if $bill_ext_file}<a href="./?module=newaccounts&action=bill_ext_file_get&bill_no={$bill.bill_no|escape:'url'}">{$bill_ext_file.name}</a>{else}<span class="text-muted">Нет файла</span>{/if}
                        </div>
                    </div>
                </div>
            </div>
        {/if}

        <div class="row">
            <div class="col-sm-11"></div>
            <div class="col-sm-1">
                <input id='submit' class='btn btn-primary' type='submit' value="Сохранить">
            </div>
        </div>
    </div>

{* {if $bill.operation_type_id == 1}   operation_type_id = 1 - расходный документ *}
    <table class="table table-condensed table-striped">
        <tr>
            <th width=1%>&#8470;</th>
            <th width=80%>Наименование</th>
            <th>Количество</th>
            <th>Цена</th>
            <th>НДС (%)</th>
            <th>Период</th>
            <th>Тип</th>
            <th>
                Удаление
                <input type="checkbox" id="mark_del"
                       onchange="if (this.checked) $('input.mark_del').attr('checked','checked'); else $('input.mark_del').removeAttr('checked');"{if $isDisabledLines} disabled{/if}/>
            </th>
        </tr>
        {foreach from=$bill_lines item=item key=key name=outer}
            {assign var="isUuLine" value=false}
            {if $item.id_service >= 100000}
                {assign var="isUuLine" value=true}
            {/if}

            {assign var="isDisabledLine" value=false}
            {if $isUuLine or !$isEditable }
                {assign var="isDisabledLine" value=true}
            {/if}

            <tr {if $isUuLine}style="background-color: bisque;"{/if}>
                <td>{$smarty.foreach.outer.iteration}.</td>
                <td><input class="form-control input-sm"
                           value="{if isset($item.item)}{$item.item|escape:"input_value_quotes"}{/if}"
                           name=item[{$key}]{if !$isEditable} disabled{/if}></td>
                <td><input class="form-control input-sm" style="width: 100px"
                           value="{if isset($item.amount)}{$item.amount}{/if}"
                           name=amount[{$key}]{if !$isEditable} disabled{/if}></td>
                <td><input class="form-control input-sm" style="width: 80px"
                           value="{if isset($item.price)}{$item.price}{/if}"
                           name=price[{$key}]{if !$isEditable} disabled{/if}></td>
                <td><input class="form-control input-sm" style="width: 80px"
                           value="{if isset($item.tax_rate)}{$item.tax_rate}{/if}"
                           name=tax_rate[{$key}]{if !$isEditable} disabled{/if}
                           placeholder={$tax_rate_default}></td>
                <td>
                    <select class="form-control input-sm" style="width: 180px"
                            name=period[{$key}]{if $isDisabledLine} disabled{/if}>
                        {assign var="linePeriod" value="`$item.date_from`|`$item.date_to`"}
                        {foreach from=$periods key=pKey item=pLabel}
                            <option value="{$pKey}"{if $linePeriod == $pKey} selected{/if}>{$pLabel}</option>
                        {/foreach}
                    </select>
                </td>
                <td>
                    <select class="form-control input-sm" style="width: 90px"
                            name=type[{$key}]{if !$isEditable} disabled{/if}>
                        <option value='service'{if isset($item.type) && $item.type=='service'} selected{/if}>услуга
                            &nbsp; &nbsp; &nbsp;обычная
                        </option>
                        <option value='zalog'{if isset($item.type) && $item.type=='zalog'} selected{/if}>залог &nbsp;
                            &nbsp;&nbsp; &nbsp;&nbsp;(попадает в с/ф-3)
                        </option>
                        <option value='zadatok'{if isset($item.type) && $item.type=='zadatok'} selected{/if}>задаток
                            &nbsp; (не попадает в с/ф)
                        </option>
                        <option value='good'{if isset($item.type) && $item.type=='good'} selected{/if}>товар</option>
                    </select>
                </td>
                <td><input type="checkbox" {if $isEditable}class="mark_del" {/if}name="del[{$key}]"
                           value="1"{if !$isEditable} readonly disabled{/if}/>
                    {if isset($item.is_uu_edit) && $item.is_uu_edit}
                        <a title="Восстановить Uu-проводку в счете" href="/?module=newaccounts&action=bill_edit&bill={$item.bill_no|escape}&auid={$item.uu_account_entry_id}">uuВс</a>
                    {/if}

                </td>
            </tr>
        {/foreach}
    </table>
    {if $bill_lines_uu}
        <hr>
        <h2>Удаленные универсальные проводки</h2>
    <table class="table table-condensed table-striped">
        {foreach from=$bill_lines_uu item=line}
            <tr>
                <th>Наименование</th>
                <th>Количество</th>
                <th>Цена</th>
                <th>&nbsp;</th>
            </tr>
        <tr>
            <td>{$line.item}</td>
            <td>{$line.amount}</td>
            <td>{$line.price}</td>
            <td><a href="/?module=newaccounts&action=bill_edit&bill={$line.bill_no|escape}&auid={$line.uu_account_entry_id}">Восстановить</a></td>
        </tr>
        {/foreach}
    </table>
    {/if}

    <div style="text-align: center">
        <input id='submit' class='btn btn-primary' type='submit' value="Сохранить"{if !$isEditable} disabled{/if}>
    </div>
{* {/if} *}
</form>
{$_showHistoryLines}
<script>
    {literal}

    $('#bill_date_from').datepicker({
      dateFormat: 'dd-mm-yy',
      maxDate: $('#pay_bill_until').val(),
      onClose: function (selectedDate) {
        $('#pay_bill_until').datepicker('option', 'minDate', selectedDate);
      }
    });

    $('#pay_bill_until').datepicker({
      dateFormat: 'dd-mm-yy',
      minDate: $('#bill_date_from').val(),
      onClose: function (selectedDate) {
        $('#bill_date_from').datepicker('option', 'maxDate', selectedDate);
      }
    });

    $('#date_from').datepicker({
      dateFormat: 'dd-mm-yy',
    });

    $('#akt_date_ext').datepicker({
      dateFormat: 'dd-mm-yy',
    });

    $('#invoice_ext_date').datepicker({
      dateFormat: 'dd-mm-yy',
    });

    $('#registration_date_ext').datepicker({
      dateFormat: 'dd-mm-yy',
    });

    $('#registration_date_fix').datepicker({
      dateFormat: 'dd-mm-yy',
    });

    $('#date_created').datepicker({
      dateFormat: 'dd-mm-yy',
    });

    $('#drafted_invoices_new_date').datepicker({
      dateFormat: 'dd-mm-yy',
    });

    function mark_del() {
      if (document.getElementById('mark_del').checked)
        $('input.mark_del').attr('checked', 'checked');
      else
        $('input.mark_del').removeAttr('checked');
    }

    {/literal}
</script>