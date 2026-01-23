<table border=0 width=100%>
    <tr>
        <td width="33%">
            <a href="/client/view?id={$bill_client.id}"><img src="images/client.jpg" title="Клиент" border=0></a>&nbsp;
            <a href='./?module=newaccounts&action=bill_list&clients_client={$bill_client.id}'><img src="images/cash.png"
                                                                                                   title="Счета"
                                                                                                   border=0></a>&nbsp;
            <a href='{$LINK_START}module=newaccounts&action=bill_list&clients_client={$bill_client.id}'
               style="font-weight: bold; font-size: large">
                {$bill_client.client}
            </a>
            {assign var="isClosed" value="0"}
            {if isset($tt_trouble) && $tt_trouble.state_id == 20}{assign var="isClosed" value="1"}{/if}
            {if isset($tt_trouble) && $tt_trouble.trouble_name}{$tt_trouble.trouble_name}{else}{if $bill.sum >= 0}Заказ{else}{$operationType}{/if}{/if}
            {if $bill.is_rollback}-<b><u>возврат</u></b>{/if}
            <b style="font-weight: bold; font-size: large">{$bill.bill_no}{if strlen($bill_ext.ext_bill_no)} ({$bill_ext.ext_bill_no}){/if}</b>

            {if !isset($1c_bill_flag)}
                {assign var="1c_bill_flag" value=0}
            {/if}
            {if !$all4net_order_number && !$1c_bill_flag}
                {if !$isClosed}
                <br>
                    <a href='{$LINK_START}module=newaccounts&action=bill_edit&bill={$bill.bill_no}'>редактировать</a>
                    /
                    {if $bill_is_editable}
                        {if !$bill.uu_bill_id}
                            <a href='{$LINK_START}module=newaccounts&action=bill_delete&bill={$bill.bill_no}'>удалить</a>
                            /
                            {if !$is_new_invoice}
                                <a href='{$LINK_START}module=newaccounts&action=bill_clear&bill={$bill.bill_no}'>очистить</a>
                                /
                            {/if}
                        {/if}
                    {/if}
                {/if}
            {elseif $1c_bill_flag}
                {if !$isClosed}
                    <a href='{$LINK_START}module=newaccounts&action=make_1c_bill&bill_no={$bill.bill_no}'>редактировать</a>
                    /
                    <a href='{$LINK_START}module=newaccounts&action=bill_delete&bill={$bill.bill_no}'>удалить</a>
                    /
                {/if}
            {/if}
            {* //@TODO выпилить *}
            {if false && !$1c_bill_flag}
                {if $bill_invoices[1]}
                    <a href="/bill/correction-invoice?bill_no={$bill.bill_no}&type_id=1">
                        {if $bill_correction_info.1}
                            Редактировать исправление
                            #{$bill_correction_info.1}
                        {else}
                            Создание исправления
                        {/if}
                        к с/ф 1</a>
                    /
                {/if}
                {if $bill_invoices[2]}
                    <a href="/bill/correction-invoice?bill_no={$bill.bill_no}&type_id=2">
                        {if $bill_correction_info.2}
                            Редактировать исправление
                            #{$bill_correction_info.2}
                        {else}
                            Создание исправления
                        {/if}
                        к с/ф 2</a>
                    /
                {/if}
            {/if}

            <a href="/custom-print/print-bill?bill_no={$bill.bill_no}" onClick="return ImmediatelyPrint(this)">распечатать</a>
        </td>
        <b style ="font-size:30px; text-align:center;">{if isset($tt_trouble) && $tt_trouble.trouble_name}{$tt_trouble.trouble_name}
                                                      {else} {if $orig_bill} {$operationType} {if $orig_bill.correction_number} правка {else} сторно {/if} 
                                                       (от <a href="/?module=newaccounts&action=bill_view&bill={$orig_bill.original_bill_no}">{$orig_bill.original_bill_no}</a>)  
                                                      {else} {$operationType} {/if}{/if}</b>
        <td>&nbsp;</td>
        <td width="33%">
            {if !$bill.is_approved}Cчет не проведен{else}Счет проведен{/if}
            {if $bill.is_show_in_lk}
                <br>
                Счет опубликован{else}
                <br>
            <a href="/bill/publish/bill?bill_no={$bill.bill_no}">Опубликовать счет </a>{/if}
            {if $bill_ext.ext_bill_no}<br>Номер внешнего счета: {$bill_ext.ext_bill_no}{/if}
            {if $bill_ext.ext_bill_date}<br>Дата внешнего счета: {$bill_ext.ext_bill_date}{/if}
            {if $bill_ext.ext_akt_no}<br>Номер внешнего акта: {$bill_ext.ext_akt_no}{/if}
            {if $bill_ext.ext_akt_date}<br>Дата внешнего акта: {$bill_ext.ext_akt_date}{/if}
            {if $bill_ext.ext_invoice_no}<br>Номер внешней с/ф: {$bill_ext.ext_invoice_no}{/if}
            {if $bill_ext.ext_invoice_date}<br>Дата внешней с/ф: {$bill_ext.ext_invoice_date}{/if}
            {if $bill_ext.ext_sum_without_vat}<br>Счет без НДС из с/ф постав.: {$bill_ext.ext_sum_without_vat}{/if}
            {if $bill_ext.ext_vat}<br>НДС из с/ф поставщика: {$bill_ext.ext_vat}{/if}
            {if $bill_file_name}<br>&nbsp;<a href="/?module=newaccounts&action=bill_ext_file_get&bill_no={$bill.bill_no|escape:"url"}"><span class="glyphicon glyphicon-upload"></span> {$bill_file_name}</a>{/if}

            {if false && access('newaccounts_bills','edit') && !$isClosed}
                <form action="?" method="post">
                    <input type="hidden" name="module" value="newaccounts"/>
                    <input type="hidden" name="action" value="bill_cleared"/>
                    <input type="hidden" name="bill_no" value="{$bill.bill_no}"/>
                    <input type="submit" name="ok" value="{if $bill.is_approved}Не проведен{else}Проведен{/if}"/>
                </form>
            {/if}
            {if $bill_client.type == "multi"}
                <br>
            <a href="./?module=newaccounts&action=make_1c_bill&tty=shop_orders&from_order={$bill.bill_no}"> Создать
                заказ на основе данных этого</a>{/if}
            {if $bill.is_payed != 1}
                <br>
            <a href="/payment/add?clientAccountId={$bill.client_id}&billId={$bill.id}">Внести платеж</a>{/if}
        </td>
    </tr>
    {if !$isClosed}
        <tr>
            <td width="33%">
                <div style="float: left;">Комментарий</div>
                {if $bill.comment}
                    <div style="float: left; margin-left: 10px; background: url('/images/icons/edit.gif') no-repeat 0 0; width: 16px; height: 16px;">
                        <a href="javascript:void(0)" data-edit="#bill-comment" class="switchEditable"
                           style="margin-left: 22px;">Редактировать</a>
                    </div>
                    <div id="bill-comment-text" class="content-wrap">
                        <div class="full-text">{$bill.comment}</div>
                        <div class="more-info">{$bill.comment}</div>
                    </div>
                {/if}
                <div id="bill-comment" style="{if $bill.comment}display: none;{/if}width: 300px;">
                    <form action="?" method=post>
                        <input type=hidden name=module value=newaccounts>
                        <input type=hidden name=bill value="{$bill.bill_no}">
                        <input type=hidden name=action value="bill_comment">
                        <textarea name="comment" style="width: 300px;"
                                  class="text">{$bill.comment|strip_tags}</textarea><br/>
                        <div style="float: right;">
                            <input type="submit" value="Сохранить"/>
                        </div>
                    </form>
                </div>
            </td>
            <td width="33%">
                <form method='POST'>
                    <input type="hidden" name="select_doer" value="1"/>
                    <input type="checkbox" id="courier_id" name="courier_id" {if $bill.courier_id > 0}checked{/if}/>
                    <label for="courier_id">Оплата проверена</label>
                    <input type='submit' value='Выбрать'/>
                </form>
            </td>
        </tr>
    {else}
        <tr>
            <td>&nbsp;</td>
            <td>{$bill.comment}</td>
            <td>&nbsp;</td>
        </tr>
    {/if}
    {if $bill_manager}
        <tr>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>
                <span title="Менеджер, который провел сделку по данному счету, и получит с него бонусы.">Менеджер счета*</span>: {$bill_manager}
            </td>
        </tr>
    {/if}
</table>
<br/>
<div>
    {if $bill_extends_info.acc_no}
        <span style="background-color: #F0F0F0;">
            <b>Лицевой счет:</b> {$bill_extends_info.acc_no}
        </span>
        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    {/if}
    Дата проводки: <b>{$bill.bill_date}</b>
    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    Оплатить счет до: <b{if ($bill.is_pay_overdue)} class="text-danger"{/if}>{$bill.pay_bill_until}</b>
    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    Валюта проводки: <b{if $bill.currency=='RUB'} style='color:blue'{/if}>{$bill.currency}</b>
    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    {if $bill.courier_id != 0}Исполнитель: <i
            style="color: green">{$bill_courier}</i>{else}{$bill_courier|replace:"-":""}{/if}
    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    Предполагаемый тип платежа:
    <i{if $bill.nal != "beznal"} style="background-color: {if $bill.nal=="nal"}#ffc0c0{else}#c0c0ff{/if}"{/if}>{$bill.nal}</i>
</div>

{if $bill_comment.comment}
    <br>
    <b><i>Комментарий:</i></b>
    <br>
    Дата: {$bill_comment.date}
    <br>
    Автор: {$bill_comment.user}
    <br>
    Текст: {$bill_comment.comment}
{/if}
{if $store}
    <br>
    Склад:
    <b>{$store}</b>
{/if}
{assign var="discount" value=0}
{assign var="sum_tax" value=0}
{assign var="sum_without_tax" value=0}
{foreach from=$bill_lines item=item key=key name=outer}
    {if !$item.is_deleted}
        {assign var="discount" value=`$discount+$item.discount_auto+$item.discount_set`}
        {assign var="sum_tax" value=`$sum_tax+$item.sum_tax`}
        {assign var="sum_without_tax" value=`$sum_without_tax+$item.sum_without_tax`}
    {/if}
{/foreach}
<table class="table table-condensed table-hover table-striped">
    <tr class=even style='font-weight:bold'>
        {* {if $bill.operation_type_id == 1 || $is_automatic} operation_type_id = 1 - доходный документ *}
            <th>&#8470;</th>
            <th width="1%">Артикул</th>
            <th>Наименование</th>
            <th>Период</th>
            <th>Количество{if isset($cur_state) && $cur_state == 17}/Отгружено{/if}</th>
            <th style="text-align: right">Цена ({if $bill.price_include_vat > 0}вкл. НДС{else}без НДС{/if})</th>
            {if $discount != 0}
                <th style="text-align: right">Скидка</th>
            {/if}
            {if $bill.price_include_vat == 0}
                <th style="text-align: right">Сумма</th>
                <th style="text-align: right">Сумма НДС</th>
                <th style="text-align: right">Сумма с НДС</th>
            {else}
                <th style="text-align: right">Сумма</th>
                <th style="text-align: right">Сумма НДС</th>
            {/if}
            {if $bill_bonus}
                <th style="text-align: right">Бонус</th>
            {/if}
            <th style="text-align: right">Тип</th>
            <th style="text-align: right">Маржа</th>
            {if $reward_lines}
            <th style="text-align: right">Вознаграждение</th>
            {/if}
        {* {else}
            <th style="text-align: left">Цена без НДС</th>
            <th style="text-align: left">НДС</th>
            <th style="text-align: left">Сумма</th>
        {/if} *}
    </tr>
    {assign var="bonus_sum" value=0}

    {foreach from=$bill_lines item=item key=key name=outer}
        <tr class='{cycle values="odd,even"}
                {if isset($item.is_deleted) && $item.is_deleted} uu-bill-view-deleted{/if}
                {if isset($item.is_updated) && $item.is_updated} uu-bill-view-updated{/if}
                '
        >
            <td>{if (isset($item.is_updated) && $item.is_updated)}^^^{else}{counter}.{/if}</td>
            <td align=left>
            <span title="{$item.art|escape}">{$item.art|truncate:10}<br>
                {if $item.type == "good"}
                    {if $item.store == "yes"}
                        <b style="color: green;">Склад</b>
                    {elseif $item.store == "no"}
                        <b style="color: blue;">Заказ</b>
                    {elseif $item.store == "remote"}
                        <b style="color: #c40000;">ДалСклад</b>
                    {/if}
                {/if}
            </span>
            </td>
            <td>
                {if $item.service && $item.service != '1C'}
                    <a target="_blank" href="{$item.service|usage_link:$item.id_service}">
                        {$item.item}
                    </a>
                {else}
                    {$item.item}
                {/if}
            </td>
            <td nowrap>
                {if access('newaccounts_bills','edit') && $bill_is_editable}
                    <a href='#'
                       onclick='optools.bills.changeBillItemDate(event,"{$bill.bill_no}",{$item.sort});return false'
                       style='text-decoration:none;color:#333333;'>
                        {$item.date_from}<br>{$item.date_to}
                    </a>
                {else}
                    <span{if !$bill_is_editable} title="Содержимое счета нельзя редактировать"{/if}>{$item.date_from}
                    <br>
                    {$item.date_to}
                    </span>
                {/if}
            </td>
            <td>{$item.amount}{if isset($cur_state) && $cur_state == 17}/<span
                        {if $item.amount != $item.dispatch}style="font-weight: bold; color: #c40000;"{/if}>{$item.dispatch}{/if}
            </td>
            <td style="text-align: right">{$item.price}</td>
            {if $discount != 0}
                {assign var="row_discount" value=`$item.discount_auto+$item.discount_set`}
                <td style="text-align: right">{$row_discount}</td>
            {/if}
            {if $bill.price_include_vat == 0}
                <td style="text-align: right">{$item.sum_without_tax}</td>
                <td style="text-align: right">{$item.sum_tax} ({$item.tax_rate}%)</td>
                <td style="text-align: right">{$item.sum}</td>
            {else}
                <td style="text-align: right">{$item.sum}</td>
                <td style="text-align: right">{$item.sum_tax} ({$item.tax_rate}%)</td>
            {/if}
            {if $bill_bonus}
                <td style="text-align: right">{if $bill_bonus[$item.code_1c]}{$bill_bonus[$item.code_1c]}{assign var="bonus_sum" value=`$bill_bonus[$item.code_1c]+$bonus_sum`}{/if}</td>
            {/if}
            <td align=right>{$item.type}</td>
            {if false}
            <td align=right{if $item.cost_price != 0} title="Сумма:{$item.sum} - НДС:{$item.sum_tax} - Себестоимость:{$item.cost_price}"{/if}>
                {if $item.cost_price != 0}{assign var="margin" value=`$item.sum-$item.sum_tax-$item.cost_price`}{$margin|round:2}{else}-{/if}
            </td>
            {/if}
            <td align=right>
                {if $item.cost_price != 0}{assign var="margin" value=`$item.cost_price*-1`}{$margin|round:2}{else}-{/if}
            </td>
            {if $reward_lines}
                <td align=right>{if isset($reward_lines[$item.pk])} {$reward_lines[$item.pk].sum|round:2} {else} {0|round:2} {/if}</td>
            {/if}
        </tr>
    {/foreach}
    {* {/if} *}
    <tr> 
            {* {if $bill.operation_type_id == 1 || $is_automatic}  operation_type_id = 1 - доходный документ *}
            <th colspan=6 style="text-align: left">Итого:</th>
            {if $discount != 0}
                <td style="text-align: right">{$discount}</td>
            {/if}
                {if $bill.price_include_vat == 0}
                    <th style="text-align: right">{$sum_without_tax|round:2}</th>
                    <td style="text-align: right">{if $bill.sum != 0}{$bill.sum-$sum_without_tax|round:2}{else}---{/if}</td>
                    <th style="text-align: right">{$bill.sum|round:2}</th>
                {else}
                    <th style="text-align: right">{if $bill.sum_correction}<strike>{$bill.sum|round:2}</strike>
                            <br>
                            {$bill.sum_correction|round:2}{else}{$bill.sum|round:2}{/if}</th>
                    <td style="text-align: right">в т.ч. {$sum_tax|round:2} </td>
                {/if}
            {if $bill_bonus}
                <td style="text-align: right">{$bonus_sum|round:2}</td>
            {/if}
        {* {else}
            <th style="text-align: left">{$bill_ext.ext_sum_without_vat}</th>
                <th style="text-align: left">{$bill_ext.ext_vat}</th>
                <th style="text-align: left">{assign var="c" value=$bill_ext.ext_sum_without_vat+$bill_ext.ext_vat} {$c|round:2}</th>
        {/if} *}
        <td colspan="2" style="text-align: right">&nbsp;</td>
        {if $reward_lines} 
            <td style="text-align: right">{$reward_sum|round:2}</td>
        {/if}
    </tr>
</table>

{if !$isClosed && !$all4net_order_number && !$1c_bill_flag}
    <table>
        <tr>
            <td>
                <a href='{$LINK_START}module=newaccounts&action=bill_add&bill={$bill.bill_no}&obj=avans'>Аванс</a> /
                <a href='{$LINK_START}module=newaccounts&action=bill_add&bill={$bill.bill_no}&obj=deposit'>Задаток</a> /
                <a href='{$LINK_START}module=newaccounts&action=bill_add&bill={$bill.bill_no}&obj=deposit_back'>возврат</a>
                /
                <a href='{$LINK_START}module=newaccounts&action=bill_add&bill={$bill.bill_no}&obj=deposit_sub'>вычет</a>
            </td>
            <td>Услуги со статусом connecting: <a
                        href='{$LINK_START}module=newaccounts&action=bill_add&bill={$bill.bill_no}&obj=connecting'>всё</a>
                <a href='{$LINK_START}module=newaccounts&action=bill_add&bill={$bill.bill_no}&obj=connecting_ab'>только
                    абонентку</a></td>
            <td>
                <a href='{$LINK_START}module=newaccounts&action=bill_add&bill={$bill.bill_no}&obj=regular'>Ежемесячное</a>
                {if isset($fixclient_data.is_bill_only_contract) && $fixclient_data.is_bill_only_contract}
                    (
                    <a href='{$LINK_START}module=newaccounts&action=bill_add&bill={$bill.bill_no}&obj=regular&unite=N'>Без
                        объединения</a>
                    )
                {/if}

            </td>
        </tr>
    </table>
    <FORM action="?" method=get id=form2 name=form2 style='padding-top:6px;padding-bottom:0px;margin:0 0 0 0'>
        <input type=hidden name=module value=newaccounts>
        <input type=hidden name=bill value="{$bill.bill_no}">
        <input type=hidden name=action value=bill_add>
        <input type=hidden name=obj value=template>
        <select name=tbill class=text>
            {foreach from=$template_bills item=item name=outer}
                <option value="{$item.bill_no}">{$item.comment}</option>
            {/foreach}
        </select><input type=submit class=button value="добавить">
    </form>
{/if}

{$_showHistoryBill}

<hr/>

<form action="?" method="get" target="_blank" name="formsend" id="formsend">
    <table cellspacing="2" cellpadding="10" valign="top" style="float: left">
        <tr>
            <td style="border-width:1 0 1 1; border-style:solid;border-color:#808080" valign="top">
                <b>Печать/отправка:</b><br>
                <input type="hidden" name="module" value="newaccounts"/>
                <input type="hidden" name="bill" value="{$bill.bill_no}"/>

                {if $available_documents}
                    {foreach from=$available_documents item=item}
                        <input type="checkbox" name="document_reports[]" value="{$item.class}" id="{$item.class}"/>
                        <label for="{$item.class}">{$item.title}</label>
                        <a href="/document/get-mhtml?billNo={$bill.bill_no}">MS Word</a>
                        <br/>
                    {/foreach}
                {/if}

                <!--input type=checkbox value=1 name="bill-2-RUB" id=cb3><label for=cb3>Счет (предоплата)</label><br-->
                <input type=checkbox value="1" name="envelope" id=cb4c><label
                        for="cb4c"{if !in_array('payment', $client|client_options:mail_delivery_variant)} style="text-decoration: line-through;"{/if}>Сопроводительное
                    письмо</label><br/>

                <input type=checkbox value="1" name="invoice-1" id=cb5>
                <label for="cb5" class="{if !$bill_invoices[1]}notactive {/if}{if $bill_invoices[1] == -1}invalid{/if}">
                    Счёт-фактура (1 абонентка)
                </label>
                <a class="{if !$bill_invoices[1]}notactive {/if}"
                   href="?module=newaccounts&action=bill_print&bill={$bill.bill_no}&object=invoice-1&to_print=true&is_word=true">MS
                    Word</a><br/>
                <input type=checkbox value="1" name="invoice-2" id=cb6>
                <label for="cb6" class="{if !$bill_invoices[2]}notactive {/if}{if $bill_invoices[2] == -1}invalid{/if}">
                    Счёт-фактура (2 превышение)
                </label>
                <a class="{if !$bill_invoices[2]}notactive {/if}"
                   href="?module=newaccounts&action=bill_print&bill={$bill.bill_no}&object=invoice-2&to_print=true&is_word=true">MS
                    Word</a><br/>
                <input type=checkbox value="1" name="invoice-3" id=cb7>
                <label for="cb7" class="{if !$bill_invoices[3]}notactive {/if}{if $bill_invoices[3] == -1}invalid{/if}">
                    Счёт-фактура (3 оборудование)
                </label>
                <a class="{if !$bill_invoices[3]}notactive {/if}"
                   href="?module=newaccounts&action=bill_print&bill={$bill.bill_no}&object=invoice-3&to_print=true&is_word=true">MS
                    Word</a><br/>
                <input type=checkbox value="1" name="invoice-4" id=cbc>
                <label for="cbc"{if $bill_invoices[5] eq 0} style="color:#C0C0C0"{elseif $bill_invoices[5] eq -1} style="background-color:#ffc0c0;font-style: italic;"{/if}>
                    Счёт-фактура (4 аванс)
                </label>
                <a class="{if $bill_invoices[5] eq 0}notactive {/if}"
                   href="?module=newaccounts&action=bill_print&bill={$bill.bill_no}&object=invoice-4&to_print=true&is_word=true">MS
                    Word</a><br/>
                <input type=checkbox value="1" name="upd-1" id="upd1">
                <label for="upd1" class="{if !$bill_upd[1]}notactive {/if}{if $bill_upd[1] == -1}invalid{/if}">
                    УПД (1 абонентка)
                </label>

                <a class="{if !$bill_upd[1]}notactive {/if}"
                   href="?module=newaccounts&action=bill_print&bill={$bill.bill_no}&object=upd-1&to_print=true&is_word=true">MS
                    Word</a><br/>

                <input type=checkbox value="1" name="upd-2" id="upd2">
                <label for="upd2" class="{if !$bill_upd[2]}notactive {/if}{if $bill_upd[2] == -1}invalid{/if}">
                    УПД (2 превышение)
                </label>
                <a class="{if !$bill_upd[2]}notactive {/if}"
                   href="?module=newaccounts&action=bill_print&bill={$bill.bill_no}&object=upd-2&to_print=true&is_word=true">MS
                    Word</a><br/>

                <input type=checkbox value="1" name="upd-3" id="updt">
                <label for="updt" class="{if !$bill_invoice[3]}notactive {/if}{if $bill_invoice[3] == -1}invalid{/if}">
                    УПД (Т оборудование)
                </label>
                <a class="{if !$bill_invoice[3]}notactive {/if}"
                   href="?module=newaccounts&action=bill_print&bill={$bill.bill_no}&object=upd-3&to_print=true&is_word=true">MS
                    Word</a><br/>

                Действие:
                <select name="action" id="action">
                    <option value="bill_mprint">печать</option>
                    <option value="bill_email">отправка</option>
                </select><br/>
                PDF: <input type="checkbox" name="is_pdf" = value="1" /><br/><br/>
                <input type=button class=button value='Поехали' onclick="doFormSend()"/>
            </td>
            <td valign=top style="border-width:1 1 1 0; border-style:solid;border-color:#808080">
                <input type=checkbox value="1" name="akt-1" id="cb8">
                <label for="cb8" class="{if !$bill_akts[1]}notactive {/if}{if $bill_akts[1] == -1}invalid{/if}">
                    Акт (1 абонентка)
                </label>
                <a class="{if !$bill_akts[1]}notactive {/if}"
                   href="?module=newaccounts&action=bill_print&bill={$bill.bill_no}&object=akt-1&to_print=true&is_word=true">MS
                    Word</a><br/>

                <input type=checkbox value="1" name="akt-2" id="cb9">
                <label for="cb9" class="{if !$bill_akts[2]}notactive {/if}{if $bill_akts[2] == -1}invalid{/if}">
                    Акт (2 превышение)
                </label>
                <a class="{if !$bill_akts[2]}notactive {/if}"
                   href="?module=newaccounts&action=bill_print&bill={$bill.bill_no}&object=akt-2&to_print=true&is_word=true">MS
                    Word</a><br/>

                <input type=checkbox value="1" name="akt-3" id="cba">
                <label for="cba" class="{if !$bill_akts[3]}notactive {/if}{if $bill_akts[3] == -1}invalid{/if}">
                    Акт (3 оборудование)
                </label><br/>

                <input type=checkbox value="1" name="lading" id="cbb"><label
                        for="cbb"{if !$bill_invoices[4]} style="color:#C0C0C0"{/if}>Накладная</label>
                <a class="{if !$bill_invoices[4]}notactive {/if}"
                   href="?module=newaccounts&action=bill_print&bill={$bill.bill_no}&object=lading&to_print=true&is_word=true">MS
                    Word</a><br/>

                <input type="checkbox" value="1" name="gds" id="gds"/><label
                        for="gds"{if !$bill_invoices[7]} style="color:#C0C0C0"{/if}>Товарный чек</label><br/>

                <input type="checkbox" value="1" name="gds-serial" id="gds_serial"/><label
                        for="gds_serial"{if !$bill_invoices[7]} style='color:#C0C0C0'{/if}>Товарный чек (с серийными
                    номерами)</label><br/>

                <input type="checkbox" value="1" name="gds-2" id="cbd"/><label for="cbd" style="color:#808080">Товарный
                    чек (все позиции)</label>
                <hr/>

                <input type=checkbox value="1" name="upd2-1" id="upd2-1">
                <label for="upd2-1" class="{if !$bill_upd2[1]}notactive {/if}{if $bill_upd2[1] == -1}invalid{/if}">
                    УПД2 (1 абонентка)
                </label>
                <br/>
                <input type=checkbox value="1" name="upd2-2" id="upd2-2">
                <label for="upd2-2" class="{if !$bill_upd2[2]}notactive {/if}{if $bill_upd2[2] == -1}invalid{/if}">
                    УПД2 (2 превышение)
                </label>
                <br/>
                <input type=checkbox value="1" name="upd2-3" id="upd2-3">
                <label for="upd2-3" class="{if !$bill_upd2[3]}notactive {/if}{if $bill_upd2[3] == -1}invalid{/if}">
                    УПД2 (3 оборудование)
                </label>
                <br/>


                {if $is_set_date}
                    <input type="text"
                           value="{if $bill.doc_ts}{$bill.doc_ts|date_format:"%d.%m.%Y"}{else}{$smarty.now|date_format:"%d.%m.%Y"}{/if}"
                           name="without_date_date"
                           size="10"{if $bill.doc_ts} style="color: #c40000; font-weight: bold;"{/if}>
                    <br/>
                    <input type="checkbox" name="without_date" value="1" id="wd"/>
                    <label for="wd">Установить дату документа</label>
                    <br/>
                    <hr/>
                {/if}
                {if $bill_client.client_orig == "nbn"}
                    <input type="checkbox" value="1" name="nbn_deliv" id="wm9">
                    <label for="wm9">NetByNet: акт доставка</label>
                    <br/>
                    <input type="checkbox" value="1" name="nbn_modem" id="wm10">
                    <label for="wm10">NetByNet: акт модем</label>
                    <br/>
                    <input type="checkbox" value="1" name="nbn_gds" id="wm11">
                    <label for="wm11">NetByNet: заказ</label>
                    <br/>
                {/if}
                {if $bill_is_new_company.retail_to_service}
                    <!-- input type="checkbox" value="1" name="notice_mcm_telekom" id="wm10">
                    <label for="wm10"> Уведомление (МСН Телеком -> Ретайл)</label>
                    <br/ -->
                    <input type="checkbox" value="1" name="sogl_mcn_service" id="wm113"/>
                    <label for="wm113">Соглашение (Ритейл -> Сервис)</label>
                    <br/>
                {/if}
                {if $bill_is_new_company.telekom_to_service}
                    <!-- input type="checkbox" value="1" name="notice_mcm_telekom" id="wm10">
                    <label for="wm10"> Уведомление (МСН Телеком -> Ретайл)</label>
                    <br/ -->
                    <input type="checkbox" value="1" name="sogl_mcn_telekom_to_service" id="wm114"/>
                    <label for="wm114">Соглашение (Телеком -> Сервис)</label>
                    <br/>
                {/if}
                {if $bill_is_new_company.service_to_abonserv}
                    <input type="checkbox" value="1" name="sogl_mcn_service_to_abonservice" id="wm115"/>
                    <label for="wm115">Соглашение (ТелекомСервис -> АбонСервис)</label>
                    <br/>
                {/if}
                {if $bill_is_new_company.abonserv_to_telekom}
                    <input type="checkbox" value="1" name="sogl_mcn_abonserv_to_telekom" id="wm116"/>
                    <label for="wm116">Соглашение (АбонСервис -> ТелекомСервис)</label>
                    <br/>
                {/if}
                {if false && $bill_client.firma == 'mcn_telekom'}
                    <input type="checkbox" value="1" name="sogl_mcm_telekom" id="wm112"/>
                    <label for="wm112">Договор переуступки c МСН Телеком на МСМ Телеком</label>
                    <br/>
                {/if}
                {if isset($isPartnerRewards) && $isPartnerRewards}
                    <input type="checkbox" value="1" name="partner_reward"/>
                    <label>Отчет агента
                    </label>
                    <br/>
                {/if}
                {if isset($isPartnerRewardsV3) && $isPartnerRewardsV3}
                    <input type="checkbox" value="1" name="partner_reward_2"/>
                    <label>Отчет агента v3
                    </label>
                    <br/>
                {/if}
                {if $bill_is_credit_note}
                    <input type="checkbox" value="1" name="credit_note" id="label_credit_note"/>
                    <label for="label_credit_note">Credit Note</label>
                    <br/>
                {/if}
            </td>
        </tr>
    </table>

    {if $bill.sum > 0 || $bill_is_one_zadatok}
        <div style="float: left; margin: 0 4px 4px 4px" class="well well-sm">
            <table border="0">
                <tr>
                    <td valign="top" style="width: 400px;">
                        <b>Счета-фактуры</b>
                        <table>
                            <tr>
                                {foreach from=$invoice2_info key=typeId item=item}
                                    <td nowrap="nowrap">
                                        <b>{if $typeId == 4}Авансовая с/ф{else}С/ф №{$typeId}{/if}</b>
                                    </td>
                                    <td>&nbsp;&nbsp;&nbsp;&nbsp;</td>
                                {/foreach}

                            </tr>
                            <tr>
                                {foreach from=$invoice2_info key=typeId item=item}
                                    <td valign="top">
                                        <table>
                                            {foreach from=$item.invoices item=invoice}
                                                <tr>
                                                    {if $invoice.idx}
                                                        <td nowrap="nowrap">
                                                            <!-- a href="/?module=newaccounts&bill={$bill.bill_no}&invoice2=1&action=bill_mprint&invoice_id={$invoice.id}"
                                                               target="_blank">{$invoice.number}{if $invoice.correction_idx} ({$invoice.correction_idx}){/if}</a -->
                                                            <a href="/?module=newaccounts&bill={$bill.bill_no}&upd2-{$invoice.type_id}=1&action=bill_mprint&invoice_id={$invoice.id}&is_for_print=0"
                                                               target="_blank">{$invoice.number}{if $invoice.correction_idx} ({$invoice.correction_idx}){/if}</a>
                                                            {if $bill_client.exchange_group_id && !$invoice->is_reversal && !$invoice.sbisDraft}<a href="/?module=newaccounts&bill={$bill.bill_no}&action=create_draft&invoice_id={$invoice.id}" title="Создать драфт в СБИС" class="glyphicon glyphicon-transfer">В_Сбис</a>{/if}:
                                                        </td>
                                                        <td class="text-right">{$invoice.sum|round:2}</td>
                                                    {elseif $invoice.is_reversal}
                                                        <td>&nbsp;</td>
                                                        <td class="text-right">{$invoice.sum|round:2}</td>
                                                    {/if}
                                                </tr>
                                            {/foreach}

                                            {if $item.status == 'draft'}
                                                <tr>
                                                    <td nowrap="nowrap">
                                                        <a href="/?module=newaccounts&bill={$bill.bill_no}&invoice2=1&action=bill_mprint&invoice_id={$invoice.id}"
                                                           target="_blank">*{$invoice.bill_no}*</a>:
                                                    </td>
                                                    <td class="text-right"> {$invoice.sum|round:2}</td>
                                                </tr>
                                            {/if}
                                        </table>
                                    </td>
                                    <td>&nbsp;&nbsp;&nbsp;&nbsp;</td>
                                {/foreach}
                            </tr>
                            <tr>
                                {foreach from=$invoice2_info key=typeId item=item}
                                    <td valign="top">
                                        <div class="row">
                                        {if $item.status == 'empty' || $item.status == 'reversal'}
                                            <div style="text-align: center;">
                                            <a href="/bill/publish/invoice-draft?bill_no={$bill.bill_no}&type_id={$typeId}" class="glyphicon glyphicon-plus btn btn-xs btn-success" title="Создать драфт">
                                            </a>
                                                <div>
                                        {elseif $item.status == 'draft'}
                                                    <br>
                                            <div class="col-sm-4 text-center">
                                                <a href="/bill/publish/invoice-edit?invoice_id={$item.lastId}" class="glyphicon glyphicon-edit btn btn-xs btn-primary" title="Редактирование"></a>
                                            </div>
                                            <div class="col-sm-4 text-center">
                                                <a href="/bill/publish/invoice-delete?bill_no={$bill.bill_no}&type_id={$typeId}" class="glyphicon glyphicon-remove btn btn-xs btn-danger" title="Удалить"></a>
                                            </div>
                                            <div class="col-sm-4 text-center">
                                                <a href="/bill/publish/invoice-register?bill_no={$bill.bill_no}&type_id={$typeId}" class="glyphicon glyphicon-download-alt btn btn-xs btn-warning" title="Регистрировать"></a>
                                            </div>
                                        {elseif $item.status == 'invoice'}
                                            <div style="text-align: center;">
                                                <a href="/bill/publish/invoice-storno?bill_no={$bill.bill_no}&type_id={$typeId}&id={$item.stornoId}" class="glyphicon glyphicon-repeat btn btn-xs btn-info">торно</a>
                                            </div>
                                        {/if}
                                        </div>
                                    </td>
                                    <td>&nbsp;&nbsp;&nbsp;&nbsp;</td>
                                {/foreach}
                            </tr>
                            <tr>
                                {foreach from=$invoice2_info key=typeId item=item}
                                    <td>
                                    </td>
                                    <td>&nbsp;&nbsp;&nbsp;&nbsp;</td>
                                {/foreach}
                            </tr>
                        </table>

                    </td>
                </tr>
            </table>
        </div>
    {/if}

    <div style="width:300px; height: 350px; float: left;">
        Почтовый реестр: {$bill.postreg}
        <br/>
        <a href="{$LINK_START}module=newaccounts&action=bill_postreg&bill={$bill.bill_no}">зарегистрировать</a><br/>
        <a href="{$LINK_START}module=newaccounts&action=bill_postreg&option=1&bill={$bill.bill_no}">удалить</a><br/>
        <br/><br/>
        Счёт-фактура (2):
        {if $bill.inv2to1==1}<a href="/bill/bill/set-invoice2-date-as-invoice1?billId={$bill.id}&value=0">как
            обычно</a>{else}как обычно{/if} /
        {if $bill.inv2to1==0}<a href="/bill/bill/set-invoice2-date-as-invoice1?billId={$bill.id}&value=1">по
            дате первой</a>{else}по дате первой{/if}<br/>

        <hr>
    </div>
    <div style="width:300px; float: left;">
        {if $bill_client.account_version == 5 && $bill.uu_bill_id}
            {foreach from=$listOfInvoices item=item}
                <div title="{$item.langTitle}" class="flag flag-{$item.langFlag}"></div>
                <a href="/uu/invoice/view?langCode={$item.langCode}&month={$item.month}" target="_blank">
                    Счет-фактура № {$item.number}
                </a>
                <br/>
            {/foreach}
        {/if}
        <br>
        <a href="/bill/print?&billNo={$bill.bill_no}&docType=uu_invoice&emailed=1&isPdf=0" target="_blank">
            <div class="flag flag-hu"></div>
            Invoice #{if $bill.uu_bill_id}{$bill.uu_bill_id}{else}{$bill.bill_no}{/if}</a>

        <br>
        <a href="/bill/print?&billNo={$bill.bill_no}&docType=proforma&emailed=1&isPdf=0" target="_blank">
            <div class="flag flag-us"></div>
            Proforma Invoice #{$bill.bill_no}</a>
        <br>
        <a href="/bill/print?&billNo={$bill.bill_no}&docType=bill_operator&emailed=1&isPdf=0" target="_blank">
            <div class="flag flag-us"></div>
            Счет операторский #{$bill.bill_no}</a>

        <a href='#'>
    </div>
    <div style="width:300px; float: left; margin-top:100px; ">
        <a href="{$LINK_START}module=newaccounts&action=bill_create_correction&orig_bill={$bill.bill_no}">Внести правки</a>
        </br></br>

        {if $connected_bills}
            {foreach from=$connected_bills key=id item=item}
                <a href="/?module=newaccounts&action=bill_view&bill={$item.bill_no}">{$id+1}. {if $item.correction_number} Правка {else} Сторно {/if} {$bill_ext.ext_invoice_no}</a>
                <b style="font-weight: bold;"> ({$item.sum}) </b>
                <a href='/?module=newaccounts&action=bill_delete&bill={$item.bill_no}'>(X)</a>
                </br>
            {/foreach}
        {/if}

    </div>
    <div style="clear: both"></div>
</form>

<script>
    {literal}
    function doFormSend() {
      $("#formsend").submit();
      return true;
      if ($("#formsend select[name=action]").val() == "bill_email") {
        var s = "";
        $("#formsend input[type=hidden]").each(function (o, i) {
          var a = $(i);
          s += "&" + a.attr("name") + "=" + a.val()
        });

        $("#formsend input:checked").each(function (o, i) {
          var a = $(i);
          s += "&" + a.attr("name") + "=" + a.val()
        });
        $("#formsend select").each(function (o, i) {
          var a = $(i);
          s += "&" + a.attr("name") + "=" + a.val()
        });
        $("#formsend input[type=text]").each(function (o, i) {
          var a = $(i);
          s += "&" + a.attr("name") + "=" + escape(a.val())
        });

        window.open("./?" + s, "_blank", "width=1000,height=600");
      } else {
        $("#formsend").submit();
      }
    }
    {/literal}
</script>

<br/>

<button class="showHistoryButton"
        onclick="showHistory(this, {literal}{params: [['app\\models\\Bill', {/literal}{$bill.id}{literal}]]{/literal})">
    Открыть историю изменений
</button>

<h3>События счета:</h3>
{if count($bill_history)}{foreach from=$bill_history item=L key=key name=outer}
    <b>{$L.ts|udate_with_timezone} - {$L.user}</b>
    : {$L.comment}
    <br>
{/foreach}{/if}
<table style='display:none;position:absolute;background-color:silver;border:double;' id='ItemsDatesTable'>
    <tr>
        <td><input type='text' id='billItemDateTable_date_from' size='10'/></td>
        <td><input type='text' id='billItemDateTable_date_to' size='10'/></td>
    </tr>
    <tr>
        <td colspan='2' align='center'>
            <input type='button' value='Ok' onclick='optools.bills.fixItemDate();'/>
        </td>
    </tr>
</table>

{*if $tt_trouble.doer_id}
<a name="doer_comment"></a>
<form style='display:inline'><input type=hidden name=module value=newaccounts><input type=hidden name=action value=bill_courier_comment>
<h3>Коментарии курьеру:</h3>
<textarea name="comment" style="width: 75%;height: 60px;">{$tt_trouble.doer_comment}</textarea>
<input type=hidden name=bill value="{$bill.bill_no}"><br>
<input type=hidden name=doer_id value="{$tt_trouble.doer_id}"><br>
<input type=submit class=button value='Сохранить'>
</form><br>
{/if*}

<script type="text/javascript" src="/js/behaviors/immediately-print.js"></script>
<script type="text/javascript">
    {literal}
    jQuery(document).ready(function () {
      $('.switchEditable')
        .on('click', function (e) {
          e.preventDefault();
          var target = $($(this).data('edit')),
            source = target.prev('div');
          if (target.length && source) {
            target.toggle();
            source.toggle();
          }
        });
    });
    {/literal}
</script>

<style type="text/css">
    {literal}
    .content-wrap {
        width: 300px;
        height: 40px;
        border: 1px dashed grey;
        padding: 4px;
        position: relative;
        clear: both;
    }

    .content-wrap:hover .more-info {
        display: block;
        box-shadow: 0 0 8px rgba(0, 0, 0, 0.5);
    }

    .full-text {
        height: 35px;
        overflow: hidden;
    }

    .more-info {
        border: 1px dashed grey;
        background: #ccc;
        position: absolute;
        left: -1px;
        top: -1px;
        right: -1px;
        padding: 4px;
        display: none;
    }

    .uu-bill-view-deleted {
        text-decoration: line-through;
    }
    .uu-bill-view-updated {
        color: grey;
    }

    .uu-bill-view-updated a {
        color: gray !important;
    }

    {/literal}
</style>
