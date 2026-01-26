<table border="0" width="100%">
    <tr>
        <td>
            <h2>
                Бухгалтерия {$fixclient_data.id} &nbsp;&nbsp;&nbsp;
                <span style="font-size: 10px;">
        (
            <a href="?module=newaccounts&simple=1">попроще</a>
            {if $fixclient_data.id_all4net} | <a
                    href="http://all4net.ru/admin/users/balance.html?id={$fixclient_data.id_all4net}">all4net</a>{/if}
                    {if $fixclient_data.type == 'multi'}
                        |
                        <a href="./?module=newaccounts&view_canceled={if $view_canceled}0{else}1{/if}">
                    {if $view_canceled}Скрыть{else}Показать{/if} отказные счета
                </a>
                    {/if}
        )
    </span>
            </h2>
            {if $fin_type=='' || $fin_type == 'profitable'}
                <a href="{$LINK_START}module=newaccounts&action=bill_create_income">Создать доходный счёт</a> /
            {/if}
            {if $fin_type == 'consumables'}
                <a href="{$LINK_START}module=newaccounts&action=bill_create_outcome">Создать расходный счёт</a> /
                
            {/if}
            {if $fin_type == 'yield-consumable'}
                <a href="{$LINK_START}module=newaccounts&action=bill_create_income">Создать доходный счёт</a> /
                <a href="{$LINK_START}module=newaccounts&action=bill_create_outcome">Создать расходный счёт</a> /
            {/if}
            <a href="{$LINK_START}module=newaccounts&action=bill_balance">Обновить баланс</a>&nbsp;
            <a style="padding-left: 200px;" href="{$LINK_START}module=newaccounts&action=bill_balance2" title="Разносит отдельно по доходные и расходные платежи">Обновить баланс (с учетом сальдо) <div class="glyphicon glyphicon-refresh"></div></a>
            <br/><br/>
        </td>
        <td style="text-align: right">
            {if $is_bill_list_filter}
                <div class="btn-group btn-group-sm">
                    <a href="./?module=newaccounts&action=bill_list_filter&value=full"
                       class="btn btn-sm btn-{if $bill_list_filter_income == 'full'}primary{else}info{/if}">Полный
                        баланс</a>&nbsp;
                    <a href="./?module=newaccounts&action=bill_list_filter&value=income"
                       class="btn btn-sm btn-{if $bill_list_filter_income == 'income'}warning{else}info{/if}">Только
                        доходный</a>&nbsp;
                    <a href="./?module=newaccounts&action=bill_list_filter&value=expense"
                       class="btn btn-sm btn-{if $bill_list_filter_income == 'expense'}warning{else}info{/if}">Только
                        расходный</a>
                </div>
            {else}
                &nbsp;
            {/if}
        </td>
    </tr>
</table>
<span title="Клиент должен нам">Входящее сальдо</span>:
<form style="display: inline;" action="?" method="POST" onSubmit="return optools.bills.checkSubmitSetSaldo();">
    <input type="hidden" name="module" value="newaccounts" />
    <input type="hidden" name="action" value="saldo" />
    <input type="text" class="text" style="width: 70px; border:0; text-align: center;" name="saldo" value="{if isset($sum_cur.last_saldo)}{$sum_cur.last_saldo}{/if}" />
    <input type="text" class="text" style="width: 12px; border:0" readonly="1" value="{if $fixclient_data.currency=='USD'}${else}р{/if}" />
    на дату <input id="date" type="text" class="text" style="width: 85px; border: 0;" name="date" value="{if $sum_cur.last_saldo_ts}{$sum_cur.last_saldo_ts|udate|mdate:"Y-m-d"}{/if}" />
    <input type="submit" class="button" value="ok" />
</form>

&nbsp; <a href="javascript:toggle2(document.getElementById('saldo_history'))">&raquo;</a><br />
<table style="display: none; margin-left: 20px;" class="price" id="saldo_history">
    <tr>
        <td class="header">Дата изменения</td>
        <td class="header">Пользователь</td>
        <td class="header">Сальдо</td>
        <td class="header">Дата сальдо</td>
        <td class="header"></td>
    </tr>
    {foreach from=$saldo_history item=item}
        <tr class="even">
            <td>{$item.edit_time|udate_with_timezone}</td>
            <td>{$item.user_name}</td>
            <td>{if isset($item.saldo)}{$item.saldo}{/if} {if $item.currency=='USD'}${else}р{/if}</td>
            <td>{$item.ts|udate_with_timezone}</td>
            <td><a href="/client/cancel-saldo?id={$item.id}&clientId={$fixclient_data.id}" onClick="return confirm('Вы уверены, что хотите отменить сальдо ?')"><b>отменить</b></a></td>
        </tr>
    {/foreach}
</table>

<table width="100%">
    <tr>
        <td valign="top" width="50%">
            <table width="100%" border="0">
                <tr>
                    <td></td>
                    <td align="right" style="padding-right: 20px;">{if $bill_list_filter_income}Счет{/if}</td>
                    <td align="right" style="padding-right: 20px; width: 100px;">{if $bill_list_filter_income}С/ф{/if}</td>
                    <td></td>
                    <td align="right"></td>
                </tr>
                <tr style="background-color: #eaeaea;">
                    <td>Всего залогов:</td>
                    <td align="right"> <b>{$sum_l.zalog.RUB|money:$currency}</b> </td>
                    <td></td>
                    <td></td>
                    <td align="right"> <b>{$sum_l.zalog.USD|money:'USD'}</b> </td>
                </tr>
                <tr>
                    <td>Всего платежей:</td>
                    <td align="right"> <b>{$sum_l.payments|default:'0.00'|money:$currency}</b></td>
                    <td align="right">{if $bill_list_filter_income} <b>{$sum_l.payments|default:'0.00'|money:$currency}</b>{/if}</td>
                    <td></td>
                    <td></td>
                </tr>
                <tr style="background-color: #eaeaea;">
                    <td>Общая сумма оказанных услуг:</td>
                    <td align="right"><b>{if $fixclient_data.currency=='USD'}{$sum.RUB.bill|money:'RUB'}{else}{$sum_l.service.RUB|money:$currency}{/if}</b></td>
                    <td align="right">{if $bill_list_filter_income}<b>{$sum_cur.invoice|money:$currency}</b>{/if}</td>
                    <td></td>
                    <td align="right"><b>{if $fixclient_data.currency=='USD'}{$sum_cur.bill|money:'USD'}{else}{$sum.USD.bill|money:'USD'}{/if}</b></td>
                </tr>
                {if $fixclient_data.status == 'distr' or $fixclient_data.status == 'operator'}
                    <tr>
                        <td>По счетам:</td>
                        <td align="right" colspan="4"> <b>+{$bill_total_add.p} / {$bill_total_add.n} = {$bill_total_add.t}</b>
                    </tr>
                {/if}
                <tr  style="background-color: #eaeaea;">
                    <td>Общая сумма <span title="Клиент должен нам">долга</span> (с учётом сальдо) (счета "минус" платежи):</td>
                    <td align="right">
                        <b>
                            {if $fixclient_data.currency!='USD'}
                                {if isset($sum_cur.saldo)}{$sum_cur.delta+$sum_cur.saldo|money:$currency}{else}{$sum_cur.delta|money:$currency}{/if}
                            {else}
                                {if isset($sum.RUB.saldo)}{$sum.RUB.delta+$sum.RUB.saldo|money:$currency}{else}{$sum.RUB.delta|money:$currency}{/if}
                            {/if}
                        </b>
                    </td>
                    <td align="right">
                        {if $bill_list_filter_income}
                            <b>
                                {if isset($sum_cur.saldo)}{$sum_cur.delta+$sum_cur.saldo|money:$currency}{else}{$sum_cur.invoice-$sum_l.payments|money:$currency}{/if}
                            </b>
                        {/if}
                    </td>
                    <td></td>
                    <td align="right">
                        &nbsp;
                    </td>
                </tr>
                <tr>
                    <td>RT Баланс:</td>
                    <td align="right">
                        <b>
                            {$fixclient_data.balance|money:$currency}
                        </b>
                    </td>
                    <td></td>
                    <td align="right">
                        &nbsp;
                    </td>
                </tr>
                <tr>
                    <td>Баланс с учетом текущей выписки:</td>
                    <td align="right">
                        <b>
                            <span title="{$sum_cur.delta|default:'0.00'|money:$currency} {if $sum_current_statement > 0}+{/if} {$sum_current_statement|default:'0.00'|money:$currency}">{$sum_cur.delta+$sum_current_statement|default:'0.00'|money:$currency}</span>
                        </b>
                    </td>
                    <td></td>
                    <td align="right">
                        &nbsp;
                    </td>
                </tr>

            </table>
        </td>
        <td valign="top" style="padding-left: 100px;" align="right">
            <div>
                <form action="?" name="show_incomegoods" method="get">
                    <input type="hidden" name="module" value="newaccounts" />
                    <input type="hidden" name="action" value="show_income_goods" />
                    <input id="with_income" type="checkbox" value="Y" name="show" {if $get_income_goods_on_bill_list}checked{/if} onchange="show_income_goods();" />
                    <label for="with_income">Показывать заказы поставщика</label>
                </form>
            </div>
            {if $counters}
                <table>
                    <tr>
                        <td>
                            <b>IP-Телефония:</b><br/>
                            Расход за день: <b>{$counters.amount_day_sum}</b><br/>
                            Расход за день МН: <b>{$counters.amount_mn_day_sum}</b><br/>
                            Расход за месяц: <b>{$counters.amount_month_sum}</b><br/>
                            Текущий баланс: <b>{$realtime_balance|money:$currency}</b><br/>
                        </td>
                    </tr>
                </table>
            {/if}
        </td>
    </tr>
</table>

{include file='newaccounts/bill_list_part_transactions.tpl'}

<table class="price" cellspacing="3" cellpadding="1" border="0" width="100%">
    <tr>
        <td class="header" valign="bottom" colspan="4"><b>{if $fixclient_data.account_version == 5}Счёт-фактура{else}Счёт{/if}</b></td>
        {* <td class="header" valign="bottom" colspan="2">Сумма</td> *}
        <td></td>
        <td></td>
        <td></td>
        <td style="align-left:30px;" class="header" valign="bottom" colspan="3"><b>Платёж</b></td>
        <td class="header" valign="bottom" colspan="3"><b>Разбивка оплаты</b></td>
        <td class="header" valign="bottom" rowspan="2">Привязка</td>
        <td class="header" valign="bottom" rowspan="2">Документы</td>
    </tr>
    <tr>
        <td class="header bill_date_column" valign="bottom">Дата</td>
        <td class="header bill_no_column" valign="bottom">Номер</td>
        <td class="header bill_date_column" valign="bottom">Дата оплаты счета</td>
        <td class="header " valign="bottom">Счет</td>
        <td class="header " valign="bottom">с/ф</td>
        <td class="header sum_column_correct" valign="bottom">Исправленная сумма</td>
        <td class="header" valign="bottom" title="положительные числа - мы должны клиенту, отрицательные - клиент нам">разница</td>
        {*<td class="header sum_column" valign="bottom">Тип операции</td>*}
        <td class="header sum_column" valign="bottom">Сумма</td>
        <td class="header payment_info_column" valign="bottom">Дата</td>
        <td class="header" valign="bottom">Кто</td>
        <td class="header" valign="bottom">разница</td>
        <td class="header sum_column" valign="bottom">Сумма оплаты</td>
        <td class="header payment_info_column" valign="bottom">Дата платежа</td>
    </tr>
    {if $currentStatement}
        <tr class="even">
            <td style="">{$currentStatement.bill_date}</td>
            <td class="">
                <a href="{$LINK_START}module=newaccounts&action=bill_view&bill=current_statement">Текущая выписка</a>
            </td>
            <td></td>
            <td class="text-right">{$currentStatement.sum|money:$fixclient_data.currency}</td>
            <td colspan="11">&nbsp;</td>
        </tr>
    {/if}

    {if !$billops && $sum_cur.last_saldo_ts}
        <tr>
            <td colspan="15" style="padding:0 0 0 0;margin: 0 0 0 0;background-color: #dbf09e; font-size: 8pt; text-align: center;">Сальдо: {$sum_cur.last_saldo} на {$sum_cur.last_saldo_ts}</td>
        </tr>
    {/if}


    {assign var=is_show_saldo value=0}
    {foreach from=$billops item=op key=key name=outer}
        {count_comments v=$op}
        {if
            isset($op.bill.bill_date) && isset($sum_cur.last_saldo_ts) && $op.bill.bill_date < $sum_cur.last_saldo_ts
            || isset($op.bill) && (($op.bill && $op.bill.currency!=$fixclient_data.currency)
            || (!$op.bill && (count($op.pays)==1) && !$op.pays.0.in_sum))
        }
            {assign var=class value=other}
        {else}
            {cycle values="odd,even" assign=class}
        {/if}

        {if isset($op.bill.bill_date) && isset($sum_cur.last_saldo_ts) && $op.bill.bill_date < $sum_cur.last_saldo_ts && $is_show_saldo == 0}
            {assign var=is_show_saldo value=1}
            <tr>
                <td colspan="15" style="padding:0 0 0 0;margin: 0 0 0 0;background-color: #dbf09e; font-size: 8pt; text-align: center;">Сальдо: {$sum_cur.last_saldo} на {$sum_cur.last_saldo_ts}</td>
            </tr>
        {/if}

        <tr class="{$class}">
            {if isset($op.bill) && $op.bill}
                <td rowspan="{$rowspan}" style="{if $op.bill.postreg!="0000-00-00"}background-color:#FFFFD0;{/if}{if $op.isCanceled==1}text-decoration: line-through;{/if}{if $op.bill.is_pay_overdue}color: #c40000;{/if}">{$op.bill.bill_date}</td>
                <td rowspan="{$rowspan}" class="pay{$op.bill.is_payed}" style="{if !$op.bill.is_show_in_lk}background-color: #aaa;{/if} {if $op.isCanceled==1}text-decoration: line-through;{/if}">
                    <a href="{$LINK_START}module=newaccounts&action=bill_view&{if $op.bill.type == "income_order"}income_order_id={$op.bill.bill_id}{else}bill={$op.bill.bill_no}{/if}">{$op.bill.bill_no}{if strlen($op.bill.bill_no_ext)}<br />({$op.bill.bill_no_ext}){/if}</a>
                </td>
                <td rowspan="{$rowspan}">{$op.bill.payment_date}</td>
                <td rowspan="{$rowspan}" align="right" nowrap>{$op.bill.sum|money:$op.bill.currency}</td>
                <td rowspan="{$rowspan}" align="right" nowrap>
                    {if $op.bill.invoice_sum != null}
                        <span style="font-size: 8pt; color: {if $op.bill.sum == $op.bill.invoice_sum}#ccc{else}#c40000{/if};"{if $op.bill.sum != $op.bill.invoice_sum} title="Корректировка с/ф"{/if}>({$op.bill.invoice_sum|money:$op.bill.currency})</span>
                    {/if}
                    {if $op.bill.correction_sum}
                        <span style="font-size: 8pt; color: #0F6AB4;" title="Корректировка счета">({$op.bill.correction_sum|money:$op.bill.currency})</span>
                    {/if}
                </td>
                <td rowspan="{$rowspan}" align="right">{if $op.bill.sum_correction}{$op.bill.sum_correction|money:$op.bill.currency}{/if}</td>
            {else}
                <td colspan="3" rowspan="{$rowspan}">&nbsp;</td>
            {/if}
            <td rowspan="{$rowspan}" align="right" nowrap>
                {objCurrency op=$op obj='delta' currency=$fixclient_data.currency}
            </td>
            {*
            <td rowspan="{$rowspan}">
                {$op.bill.operationType}
            </td>
            *}
            {if count($op.pays)}
                {foreach from=$op.pays item=pay key=keyin name=inner}
                    {if $smarty.foreach.inner.iteration!=1}
                        </tr><tr class="{$class}">
                    {/if}
                    {if isset($pay.p_bill_no) && isset($op.bill.bill_no) && $pay.p_bill_no==$op.bill.bill_no}
                        <td>
                            {objCurrency op=$op obj='pay_full' pay=$pay currency=$fixclient_data.currency}
                        </td>
                        <td style="font-size: 85%;">
                            {$pay.payment_date} - &#8470;{$pay.payment_no} /
                            {if $pay.type=='bank'}b
                            {elseif $pay.type=='prov'}p
                            {elseif $pay.type=='neprov'}n
                            {elseif $pay.type=='webmoney'}wm
                            {elseif $pay.type=='yandex'}y
                            {else}{$pay.type}{/if}
                            {if $pay.oper_date!="0000-00-00"} - {$pay.oper_date}{/if}
                        </td>
                        <td align="right">
                            <span title="{$pay.add_date}">{$pay.user_name}</span>
                            {if (access('newaccounts_payments','delete') && $pay.type != 'ecash')}
                                <a onClick="return confirm('Вы уверены?')" href="/payment/delete?paymentId={$pay.id}"><img class="icon" src="{$IMAGES_PATH}icons/delete.gif" alt="Удалить"></a>{/if}
                        </td>
                    {else}
                        <td colspan="3">&nbsp;</td>
                    {/if}
                    {if $smarty.foreach.inner.iteration==1}
                        <td rowspan="{$rowspan}" align="right" nowrap>{objCurrency op=$op obj='delta2' currency=$fixclient_data.currency}</td>
                    {/if}

                    {if isset($op.bill.bill_no) && isset($pay.bill_no) && $pay.bill_no==$op.bill.bill_no}
                        {if $pay.payment_id|strpos:"-"}
                            <td style="{if $pay.p_bill_no!=$pay.bill_no}background: #e0e0ff;{/if}">{objCurrency op=$op obj='pay2' pay=$pay currency=$fixclient_data.currency}</td>
                            <td style="font-size: 85%;{if $pay.p_bill_no!=$pay.bill_no}background: #e0e0ff;{/if}">&#8470;{$pay.payment_id}</td>
                        {else}
                            <td style="{if $pay.p_bill_no!=$pay.bill_no}background: #e0e0ff;{/if}">{objCurrency op=$op obj='pay2' pay=$pay currency=$fixclient_data.currency}</td>
                            <td style="font-size: 85%;{if $pay.p_bill_no!=$pay.bill_no}background: #e0e0ff;{/if}">{if false}
                                {$pay.payment_date} - &#8470;{$pay.payment_no} /
                                {if $pay.type=='bank'}b({$pay.bank})
                                {elseif $pay.type=='prov'}p
                                {elseif $pay.type=='neprov'}n
                                {elseif $pay.type=='webmoney'}wm
                                {elseif $pay.type=='yandex'}y
                                {else}{$pay.type}{/if}
                                {if $pay.oper_date!="0000-00-00"} - {$pay.oper_date}{/if}
                                {/if}
                            </td>
                        {/if}
                    {else}
                        <td colspan="2">&nbsp;</td>
                    {/if}
                    <td style="padding:0 0 0 0">
                        {if ($op.delta>=0.01) && access('newaccounts_payments','edit')}
                            <form name="paybill{$pay.id}" style="display: inline; width:'';" action="?">
                            <input type="hidden" name="module" value="newaccounts" />
                            <input type="hidden" name="action" value="pay_rebill" />
                            <input type="hidden" name="pay" value="{$pay.id}" />
                            <select name="bill" onChange="paybill{$pay.id}.submit()" class="text" style="border: 0; padding: 0; margin: 0; width:''">
                                <option></option>
                                {foreach from=$billops item=sop key=key name=innerselect}
                                    {if isset($sop.bill) && $sop.bill && ($sop.delta<0)}
                                        <option value="{$sop.bill.bill_no}"{if $pay.p_bill_vis_no==$sop.bill.bill_no} selected="selected"{/if}>{$sop.bill.bill_no}</option>
                                    {/if}
                                {/foreach}
                            </select>
                            </form>
                        {/if}
                    </td>
                    {if $smarty.foreach.inner.iteration==1}
                        <td rowspan="{$rowspan}">
                            {if isset($qrs[$op.bill.bill_no].11) && isset($op.bill.bill_no) && $qrs[$op.bill.bill_no].11}<a {if access('newaccounts_bills','del_docs')}class="del_doc"{/if} id="{$qrs[$op.bill.bill_no].11}" href="./?module=newaccounts&action=doc_file&id={$qrs[$op.bill.bill_no].11}" target=_blank title="Акт-1"><img border=0 src="images/icons/act.gif" title="{$qrs_date[$op.bill.bill_no].11}"></a>А1<br/>{/if}
                            {if isset($qrs[$op.bill.bill_no].12) && isset($op.bill.bill_no) && $qrs[$op.bill.bill_no].12}<a {if access('newaccounts_bills','del_docs')}class="del_doc"{/if} id="{$qrs[$op.bill.bill_no].12}" href="./?module=newaccounts&action=doc_file&id={$qrs[$op.bill.bill_no].12}" target=_blank title="Акт-2"><img border=0 src="images/icons/act.gif" title="{$qrs_date[$op.bill.bill_no].12}"></a>A2<br/>{/if}
                            {if isset($qrs[$op.bill.bill_no].21) && isset($op.bill.bill_no) && $qrs[$op.bill.bill_no].21}<a {if access('newaccounts_bills','del_docs')}class="del_doc"{/if} id="{$qrs[$op.bill.bill_no].21}" href="./?module=newaccounts&action=doc_file&id={$qrs[$op.bill.bill_no].21}" target=_blank title="УПД-1"><img border=0 src="images/icons/act.gif" title="{$qrs_date[$op.bill.bill_no].21}"></a>У1<br/>{/if}
                            {if isset($qrs[$op.bill.bill_no].22) && isset($op.bill.bill_no) && $qrs[$op.bill.bill_no].22}<a {if access('newaccounts_bills','del_docs')}class="del_doc"{/if} id="{$qrs[$op.bill.bill_no].22}" href="./?module=newaccounts&action=doc_file&id={$qrs[$op.bill.bill_no].22}" target=_blank title="УПД-2"><img border=0 src="images/icons/act.gif" title="{$qrs_date[$op.bill.bill_no].22}"></a>У2<br/>{/if}
                            {if isset($qrs[$op.bill.bill_no].23) && isset($op.bill.bill_no) && $qrs[$op.bill.bill_no].23}<a {if access('newaccounts_bills','del_docs')}class="del_doc"{/if} id="{$qrs[$op.bill.bill_no].23}" href="./?module=newaccounts&action=doc_file&id={$qrs[$op.bill.bill_no].23}" target=_blank title="УПД-3"><img border=0 src="images/icons/act.gif" title="{$qrs_date[$op.bill.bill_no].23}"></a>У3{/if}
                        </td>
                    {/if}
                    {if $pay.comment}
                        </tr>
                        <tr class="{$class}">
                            <td colspan="6" class="comment"{if $pay.info_json} style="border-bottom: 1px dotted #000; text-decoration: none;"{/if}>
                                {if $pay.info_json}
                                    <span class="btn btn-xs " data-toggle="popover" data-html="true" data-placement="bottom" data-content="<br />
                                    <pre>{$pay.info_json|escape:"html"}</pre>
                                    " data-original-title="" title="">{if $pay.comment}{$pay.comment|escape:"html"}{else}&nbsp;1111{/if}</span>
                                {else}
                                    {$pay.comment|escape:"html"}
                                {/if}
                                {if $pay.uuid_log}
                                    {if ($pay.uuid_log.status == 'done')}
                                        <a href="{$pay.uuid_log.text}" target="_blank" title="Чек в АТОЛ">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-receipt" viewBox="0 0 16 16">
                                                <path d="M1.92.506a.5.5 0 0 1 .434.14L3 1.293l.646-.647a.5.5 0 0 1 .708 0L5 1.293l.646-.647a.5.5 0 0 1 .708 0L7 1.293l.646-.647a.5.5 0 0 1 .708 0L9 1.293l.646-.647a.5.5 0 0 1 .708 0l.646.647.646-.647a.5.5 0 0 1 .708 0l.646.647.646-.647a.5.5 0 0 1 .801.13l.5 1A.5.5 0 0 1 15 2v12a.5.5 0 0 1-.053.224l-.5 1a.5.5 0 0 1-.8.13L13 14.707l-.646.647a.5.5 0 0 1-.708 0L11 14.707l-.646.647a.5.5 0 0 1-.708 0L9 14.707l-.646.647a.5.5 0 0 1-.708 0L7 14.707l-.646.647a.5.5 0 0 1-.708 0L5 14.707l-.646.647a.5.5 0 0 1-.708 0L3 14.707l-.646.647a.5.5 0 0 1-.801-.13l-.5-1A.5.5 0 0 1 1 14V2a.5.5 0 0 1 .053-.224l.5-1a.5.5 0 0 1 .367-.27m.217 1.338L2 2.118v11.764l.137.274.51-.51a.5.5 0 0 1 .707 0l.646.647.646-.646a.5.5 0 0 1 .708 0l.646.646.646-.646a.5.5 0 0 1 .708 0l.646.646.646-.646a.5.5 0 0 1 .708 0l.646.646.646-.646a.5.5 0 0 1 .708 0l.646.646.646-.646a.5.5 0 0 1 .708 0l.509.509.137-.274V2.118l-.137-.274-.51.51a.5.5 0 0 1-.707 0L12 1.707l-.646.647a.5.5 0 0 1-.708 0L10 1.707l-.646.647a.5.5 0 0 1-.708 0L8 1.707l-.646.647a.5.5 0 0 1-.708 0L6 1.707l-.646.647a.5.5 0 0 1-.708 0L4 1.707l-.646.647a.5.5 0 0 1-.708 0z"/>
                                                <path d="M3 4.5a.5.5 0 0 1 .5-.5h6a.5.5 0 1 1 0 1h-6a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h6a.5.5 0 1 1 0 1h-6a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h6a.5.5 0 1 1 0 1h-6a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1h-6a.5.5 0 0 1-.5-.5m8-6a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5"/>
                                            </svg>
                                        </a>
                                    {else}
                                        <span  title="{$pay.uuid_log.text}">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="#c40000" class="bi bi-receipt" viewBox="0 0 16 16">
                                            <path d="M1.92.506a.5.5 0 0 1 .434.14L3 1.293l.646-.647a.5.5 0 0 1 .708 0L5 1.293l.646-.647a.5.5 0 0 1 .708 0L7 1.293l.646-.647a.5.5 0 0 1 .708 0L9 1.293l.646-.647a.5.5 0 0 1 .708 0l.646.647.646-.647a.5.5 0 0 1 .708 0l.646.647.646-.647a.5.5 0 0 1 .801.13l.5 1A.5.5 0 0 1 15 2v12a.5.5 0 0 1-.053.224l-.5 1a.5.5 0 0 1-.8.13L13 14.707l-.646.647a.5.5 0 0 1-.708 0L11 14.707l-.646.647a.5.5 0 0 1-.708 0L9 14.707l-.646.647a.5.5 0 0 1-.708 0L7 14.707l-.646.647a.5.5 0 0 1-.708 0L5 14.707l-.646.647a.5.5 0 0 1-.708 0L3 14.707l-.646.647a.5.5 0 0 1-.801-.13l-.5-1A.5.5 0 0 1 1 14V2a.5.5 0 0 1 .053-.224l.5-1a.5.5 0 0 1 .367-.27m.217 1.338L2 2.118v11.764l.137.274.51-.51a.5.5 0 0 1 .707 0l.646.647.646-.646a.5.5 0 0 1 .708 0l.646.646.646-.646a.5.5 0 0 1 .708 0l.646.646.646-.646a.5.5 0 0 1 .708 0l.646.646.646-.646a.5.5 0 0 1 .708 0l.646.646.646-.646a.5.5 0 0 1 .708 0l.509.509.137-.274V2.118l-.137-.274-.51.51a.5.5 0 0 1-.707 0L12 1.707l-.646.647a.5.5 0 0 1-.708 0L10 1.707l-.646.647a.5.5 0 0 1-.708 0L8 1.707l-.646.647a.5.5 0 0 1-.708 0L6 1.707l-.646.647a.5.5 0 0 1-.708 0L4 1.707l-.646.647a.5.5 0 0 1-.708 0z"/>
                                            <path d="M3 4.5a.5.5 0 0 1 .5-.5h6a.5.5 0 1 1 0 1h-6a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h6a.5.5 0 1 1 0 1h-6a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h6a.5.5 0 1 1 0 1h-6a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1h-6a.5.5 0 0 1-.5-.5m8-6a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5m0 2a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1h-1a.5.5 0 0 1-.5-.5"/>
                                        </svg>
                                    </span>
                                    {/if}
                                {/if}

                            </td>
                            <td colspan="2">&nbsp;</td>
                    {/if}
                {/foreach}
                {if $op.bill.comment}
                    </tr>
                    <tr class="{$class}">
                        <td colspan="16" class="comment">{$op.bill.comment|strip_tags}</td>
                {/if}
            {else}
                <td colspan="8" rowspan="1">&nbsp;</td>
                <td>
                    {if isset($qrs[$op.bill.bill_no].11) && isset($op.bill.bill_no) && $qrs[$op.bill.bill_no].11}<a {if access('newaccounts_bills','del_docs')}class="del_doc"{/if} id="{$qrs[$op.bill.bill_no].11}" href="./?module=newaccounts&action=doc_file&id={$qrs[$op.bill.bill_no].11}" target=_blank title="Акт-1"><img border=0 src="images/icons/act.gif" title="{$qrs_date[$op.bill.bill_no].11}"></a>А1<br/>{/if}
                    {if isset($qrs[$op.bill.bill_no].12) && isset($op.bill.bill_no) && $qrs[$op.bill.bill_no].12}<a {if access('newaccounts_bills','del_docs')}class="del_doc"{/if} id="{$qrs[$op.bill.bill_no].12}" href="./?module=newaccounts&action=doc_file&id={$qrs[$op.bill.bill_no].12}" target=_blank title="Акт-2"><img border=0 src="images/icons/act.gif" title="{$qrs_date[$op.bill.bill_no].12}"></a>A2<br/>{/if}
                    {if isset($qrs[$op.bill.bill_no].21) && isset($op.bill.bill_no) && $qrs[$op.bill.bill_no].21}<a {if access('newaccounts_bills','del_docs')}class="del_doc"{/if} id="{$qrs[$op.bill.bill_no].21}" href="./?module=newaccounts&action=doc_file&id={$qrs[$op.bill.bill_no].21}" target=_blank title="УПД-1"><img border=0 src="images/icons/act.gif" title="{$qrs_date[$op.bill.bill_no].21}"></a>У1<br/>{/if}
                    {if isset($qrs[$op.bill.bill_no].22) && isset($op.bill.bill_no) && $qrs[$op.bill.bill_no].22}<a {if access('newaccounts_bills','del_docs')}class="del_doc"{/if} id="{$qrs[$op.bill.bill_no].22}" href="./?module=newaccounts&action=doc_file&id={$qrs[$op.bill.bill_no].22}" target=_blank title="УПД-2"><img border=0 src="images/icons/act.gif" title="{$qrs_date[$op.bill.bill_no].22}"></a>У2<br/>{/if}
                    {if isset($qrs[$op.bill.bill_no].23) && isset($op.bill.bill_no) && $qrs[$op.bill.bill_no].23}<a {if access('newaccounts_bills','del_docs')}class="del_doc"{/if} id="{$qrs[$op.bill.bill_no].23}" href="./?module=newaccounts&action=doc_file&id={$qrs[$op.bill.bill_no].23}" target=_blank title="УПД-3"><img border=0 src="images/icons/act.gif" title="{$qrs_date[$op.bill.bill_no].23}"></a>У3{/if}
                </td>

                {if isset($op.bill.comment) && $op.bill.comment}
                    </tr>
                    <tr class="{$class}">
                        <td colspan="16" class="comment">{$op.bill.comment|strip_tags} {if $op.bill.file_name}&nbsp;<a href="/?module=newaccounts&action=bill_ext_file_get&bill_no={$op.bill.bill_no|escape:"url"}" class="btn btn-warning btn-sm" style="padding: 0 3px;"><span class="glyphicon glyphicon-upload"></span> {$op.bill.file_name}</a>{/if}</td>
                {/if}
            {/if}
        </tr>
        {if isset($op.organization_switched) &&  $op.organization_switched}
            <tr>
                <td colspan="13" style="padding:0 0 0 0;margin: 0 0 0 0;background-color: #9edbf0; font-size: 8pt; text-align: center;">{$op.organization_switched.name}</td>
            </tr>
        {/if}
        {if $op.bill.bill_no == 'saldo'}
            {assign var=is_hide_after_saldo value=1}
        {/if}
    {/foreach}
</table>

{if access('newaccounts_bills','del_docs')}
    <script type="text/javascript">
    {literal}
        $(document).ready(function(){
            statlib.modules.newaccounts.bill_list_full.simple_tooltip(".del_doc" ,"tooltip");
        });
    {/literal}
    </script>
{/if}
<script type="text/javascript">
    {literal}
        function show_income_goods()
        {
            document.forms["show_incomegoods"].submit();
        }
        $( '#date').datepicker({dateFormat: 'yy-mm-dd'});

    $(function () {
        var $popovers = $('[data-toggle="popover"]');
        $popovers.length && $popovers.popover();
    })

    {/literal}
</script>
<style>
    {literal}
    .popover {
        max-width:600px;
    }
    {/literal}
</style>
{if access('newaccounts_bills','edit')}
    <div style="float: right;">
        <a href="/?module=newaccounts&action=recalc_entry" class="btn btn-info btn-sm" role="button">
            Пересчитать проводки за этот месяц
        </a>
    </div>
{/if}