<h2>
    Бухгалтерия {$fixclient_data.id} &nbsp;&nbsp;&nbsp;
    <span style="font-size: 10px;">
        (
            <a href="?module=newaccounts&simple=0">посложнее</a>
            {if $fixclient_data.id_all4net} | <a href="http://all4net.ru/admin/users/balance.html?id={$fixclient_data.id_all4net}">all4net</a>{/if}
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
<a href="{$LINK_START}module=newaccounts&action=bill_balance">Обновить баланс</a>
<a style="padding-left: 200px;" href="{$LINK_START}module=newaccounts&action=bill_balance2" title="Разносит отдельно по доходные и расходные платежи">Обновить баланс (с учетом сальдо)<div class="glyphicon glyphicon-refresh"></div></a>
<br/><br/>

<span title="Клиент должен нам">Входящее сальдо</span>:
<form style="display: inline;" action="?" method="POST" onSubmit="return optools.bills.checkSubmitSetSaldo();">
    <input type="hidden" name="module" value="newaccounts" />
    <input type="hidden" name="action" value="saldo" />
    <input type="text" class="text" style="width: 70px; border:0; text-align: center;" name="saldo" value="{if isset($sum_cur.last_saldo)}{$sum_cur.last_saldo}{/if}" />
    <input type="text" class="text" style="width: 12px; border:0" readonly="1" value="{if $fixclient_data.currency=='USD'}${else}р{/if}" />
    на дату <input id="date" type="text" class="text" style="width: 85px; border: 0" name="date" value="{if $sum_cur.last_saldo_ts}{$sum_cur.last_saldo_ts|udate|mdate:"Y-m-d"}{/if}" />
    <input type="submit" class="button" value="ok" />
</form>

&nbsp; <a href="javascript:toggle2(document.getElementById('saldo_history'))">&raquo;</a><br />
<table style="display:none;margin-left:20px" class="price" id="saldo_history">
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

<table width=100%>
    <tr>
        <td valign="top" width="50%">
            <table width="100%" border="0">
                <tr style="background-color: #eaeaea;">
                    <td>Всего залогов:</td>
                    <td align="right"> <b>{$sum_l.zalog.RUB|money:$currency}</b> </td>
                    <td></td>
                    <td align="right"> <b>{$sum_l.zalog.USD|money:'USD'}</b> </td>
                </tr>
                <tr>
                    <td>Всего платежей:</td>
                    <td align="right"> <b>{$sum_l.payments|default:'0.00'|money:$currency}</b></td>
                    <td></td>
                    <td></td>
                </tr>
                <tr  style="background-color: #eaeaea;">
                    <td>Общая сумма оказанных услуг:</td>
                    <td align="right"><b>{$sum_l.service.$currency|money:$currency}</b></td>
                    <td></td>
                    <td align="right"><b>{if $fixclient_data.currency=='USD'}{$sum_cur.bill|money:'USD'}{else}{$sum.USD.bill|money:'USD'}{/if}</b></td>
                </tr>
                <tr>
                    <td>Общая сумма <span title="Клиент должен нам">долга</span> (с учётом сальдо) (счета "минус" платежи):</td>
                    <td align="right">
                        <b>
                            {if isset($sum_cur.saldo)}{$sum_cur.delta+$sum_cur.saldo|money:$currency}{else}{$sum_cur.delta|money:$currency}{/if}
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
        <td valign=top style="padding-left: 100px;" align="right">
            {if $counters}
                <table>
                    <tr>
                        <td>
                            <b>IP-Телефония:</b><br/>
                            Расход за день: <b>{$counters.amount_day_sum}</b><br/>
                            Расход за день МН: <b>{$counters.amount_mn_day_sum}</b><br/>
                            Расход за месяц: <b>{$counters.amount_month_sum}</b><br/>
                            Текущий баланс: <b>{$realtime_balance|money:$fixclient_data.currency}</b><br/>
                        </td>
                    </tr>
                </table>
            {/if}
        </td>
    </tr>
</table>

{include file='newaccounts/bill_list_part_transactions.tpl'}

<form action="?" method="get" name="formsend" id="formsend">
    <input type="hidden" name="module" value="newaccounts"/>
    <input type="hidden" name="action" id="action" value=""/>
    <input type="hidden" name="document_reports[]" value="bill"/>
    <input type="hidden" name="akt-1" value="1"/>
    <input type="hidden" name="akt-2" value="1"/>
    <input type="hidden" name="akt-3" value="1"/>
    <input type="hidden" name="invoice-1" value="1"/>
    <input type="hidden" name="invoice-2" value="1"/>
    <input type="hidden" name="invoice-3" value="1"/>
    <input type="hidden" name="isBulkPrint" value="1"/>
    <div class="pull-right">
        <button type="submit" class="button" onclick="setAction('bill_email')">Отправить на e-mail</button>
        <button type="submit" class="button" onclick="setAction('bill_mprint')" name="isLandscape" value="1">Печать в альбомной ориентации</button>
        <button type="submit" class="button" onclick="setAction('bill_mprint')" name="isPortrait" value="1">Печать в книжной ориентации</button>
        <button type="submit" class="button" onclick="setAction('bill_postreg')">Зарег-ть</button>
    </div>
    <table class="price" cellspacing="3" cellpadding="1" border="0" width="100%">
        <tr>
            <td class="header" valign="bottom" colspan="3"><b>{if $fixclient_data.account_version == 5}Счёт-фактура{else}Счёт{/if}</b></td>
            <td class="header" valign="bottom">&nbsp;</td>
            <td></td>
            <td class="header" valign="bottom" colspan="4"><b>Платёж</b></td>
        </tr>
        <tr>
            <td class="header" valign="bottom">Дата</td>
            <td class="header" valign="bottom">Номер</td>
            <td class="header" valign="bottom">Дата оплаты счета</td>
            <td class="header" valign="bottom">Сумма</td>
            <td class="header" valign="bottom" title="положительные числа - мы должны клиенту, отрицательные - клиент нам">разница</td>
            <td class="header" valign="bottom">Сумма</td>
            <td class="header" valign="bottom">Дата</td>
            <td class="header" valign="bottom">Курс</td>
            <td class="header" valign="bottom">Кто</td>
            <td valign="bottom">
                <input type="checkbox" onclick="selectAllCheckboxes($(this).prop('checked'))">&nbsp&nbsp
            </td>
        </tr>
        {if $currentStatement}
            <tr class="even">
                <td>{$currentStatement.bill_date}</td>
                <td>
                    <a href="{$LINK_START}module=newaccounts&action=bill_view&bill=current_statement">Текущая выписка</a>
                </td>
                <td></td>
                <td class="text-right">{$currentStatement.sum|money:$fixclient_data.currency}</td>
                <td colspan="6">&nbsp;</td>
            </tr>
        {/if}

        {if !$billops && $sum_cur.last_saldo_ts}
            <tr>
                <td colspan="12" style="padding:0 0 0 0;margin: 0 0 0 0;background-color: #dbf09e; font-size: 8pt; text-align: center;">Сальдо: {$sum_cur.last_saldo} на {$sum_cur.last_saldo_ts}</td>
            </tr>
        {/if}

        {assign var=is_show_saldo value=0}
        {foreach from=$billops item=op key=key name=outer}
            {count_comments v=$op}
            {if
                    isset($op.bill.bill_date) && isset($sum_cur.last_saldo_ts) && $op.bill.bill_date < $sum_cur.last_saldo_ts
                || (isset($op.bill) && $op.bill && $op.bill.currency!=$fixclient_data.currency)
                || ((!isset($op.bill) || !$op.bill) && (count($op.pays)==1) && !$op.pays.0.in_sum)
            }
                {assign var=class value=other}
            {else}
                {cycle values="odd,even" assign=class}
            {/if}

            {if isset($op.bill.bill_date) && isset($sum_cur.last_saldo_ts) && $op.bill.bill_date < $sum_cur.last_saldo_ts && $is_show_saldo == 0}
                {assign var=is_show_saldo value=1}
                <tr>
                    <td colspan="12" style="padding:0 0 0 0;margin: 0 0 0 0;background-color: #dbf09e; font-size: 8pt; text-align: center;">Сальдо: {$sum_cur.last_saldo} на {$sum_cur.last_saldo_ts}</td>
                </tr>
            {/if}

        <tr class="{$class}">
            {if isset($op.bill) && $op.bill}
                <td rowspan="{$rowspan}" style="{if $op.bill.postreg!="0000-00-00"}background-color:#FFFFD0;{/if}{if $op.isCanceled==1}text-decoration: line-through;{/if}{if $op.bill.is_pay_overdue}color: #c40000;{/if}">{$op.bill.bill_date}</td>
                <td rowspan="{$rowspan}" class="pay{$op.bill.is_payed}" style="{if !$op.bill.is_show_in_lk}background-color: #aaa;{/if} {if $op.isCanceled==1}text-decoration: line-through;{/if}">
                        <a href="{$LINK_START}module=newaccounts&action=bill_view&bill={$op.bill.bill_no}">{$op.bill.bill_no}</a>
                </td>
                <td rowspan="{$rowspan}">{$op.bill.payment_date}</td>
                <td rowspan="{$rowspan}" align="right">{if $op.ext_sum} -{$op.ext_sum|money:$op.bill.currency} {else} {$op.bill.sum|money:$op.bill.currency} {/if}</td>
            {else}
                <td colspan="3" rowspan="{$rowspan}">&nbsp;</td>
            {/if}
            <td rowspan="{$rowspan}" align="right">
                {if $client_type == 'yield-consumable' && $op.bill.operation_type_id == 2}
                    {$op.ext_sum|money:$op.bill.currency}
                {elseif $fixclient_data.currency == $op.bill.currency}
                    {objCurrency op=$op obj='delta' currency=$fixclient_data.currency}
                {/if}
            </td>
            {if count($op.pays)}
                {foreach from=$op.pays item=pay key=keyin name=inner}
                    {if $smarty.foreach.inner.iteration!=1}
                        </tr><tr class="{$class}">
                    {/if}
                    <td>
                        {objCurrency op=$op obj='pay' pay=$pay currency=$fixclient_data.currency simple=1}
                    </td>
                    <td style="font-size: 85%;">
                        {$pay.payment_date} - &#8470;{$pay.payment_no} /
                        {if $pay.type=='bank'}b({$pay.bank})
                        {elseif $pay.type=='prov'}p
                        {elseif $pay.type=='neprov'}n
                        {elseif $pay.type=='webmoney'}wm
                        {elseif $pay.type=='yandex'}y
                        {else}{$pay.type}{/if}
                        {if $pay.oper_date!="0000-00-00"} - {$pay.oper_date}{/if}
                    </td>
                    <td style="padding:0 0 0 0;">{if isset($op.bill) && $op.bill.currency=='USD'}{$pay.payment_rate}{else}&nbsp;{/if}</td>
                    <td><span title="{$pay.add_date}">{$pay.user_name}</span></td>
                    {if $pay.comment}
                        </tr>
                        <tr class="{$class}">
                        <td colspan="4" class="comment">

                            {if $pay.info_json}
                                <span class="btn btn-xs " data-toggle="popover" data-html="true" data-placement="bottom" data-content="<br />
                                <pre>{$pay.info_json|escape:"html"}</pre>

    " data-original-title="" title="">{$pay.comment|escape:"html"}</span>
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
                    {/if}
                {/foreach}
                {if $op.bill.comment}
                    </tr>
                    <tr class="{$class}">
                    <td colspan="4" class="comment">{$op.bill.comment|strip_tags}</td>
                    <td colspan="4">&nbsp;</td>
                {/if}
            {else}
                {if isset($op.bill.comment) && $op.bill.comment}
                    <td colspan="4" rowspan="2">&nbsp;</td>
                    </tr>
                    <tr>
                    <td colspan="9" class="comment">{$op.bill.comment|strip_tags} {if $op.bill.file_name}&nbsp;<a href="/?module=newaccounts&action=bill_ext_file_get&bill_no={$op.bill.bill_no|escape:"url"}" class="btn btn-warning btn-sm" style="padding: 0 3px;"><span class="glyphicon glyphicon-upload"></span> {$op.bill.file_name}</a>{/if}</td>
                {else}
                    <td colspan="4">&nbsp;</td>
                {/if}
            {/if}
            <td><input type="checkbox" class="checkBoxClass" value={$op.bill.bill_no} name="bill[]"></td>
            </tr>
            {if isset($op.organization_switched) && $op.organization_switched}
                <tr>
                    <td colspan="12" style="padding:0 0 0 0;margin: 0 0 0 0;background-color: #9edbf0; font-size: 8pt; text-align: center;">{$op.organization_switched.name}</td>
                </tr>
            {/if}

        {/foreach}
    </table>
    {if $is_partner}
        <hr>
        <h2>Пересчет партнерских вознаграждений</h2>
        <div class="col-sm-2" style="padding-bottom: 1%">
            <div class="form-group" >
                <label>Рассчет с:</label>
                <input class="form-control input-sm" autocomplete="off"
                       id="date_from" type="text" name="date_from"
                       value="">
            </div>
            <button type="submit" class="button" onclick="setAction('bill_calculate_rewards')">Посчитать вознаграждения</button>
        </div>
        <div class="col-sm-2">
            <div class="form-group">
                <label>Рассчет по:</label>
                <input class="form-control input-sm" autocomplete="off"
                       id="date_to" type="text" name="date_to"
                       value="">
            </div>
        </div>
    {/if}
</form>

<script type="text/javascript">
    {literal}
        $( '#date').datepicker({dateFormat: 'yy-mm-dd'});
        $('#date_from').datepicker({
            changeMonth: true,
            changeYear: true,
            dateFormat: 'mm-yy',
            onClose: function(dateText, inst) {
                var month = $("#ui-datepicker-div .ui-datepicker-month :selected").val();
                var year = $("#ui-datepicker-div .ui-datepicker-year :selected").val();
                $(this).datepicker('setDate', new Date(year, month, 1));
            }
        });
        $('#date_to').datepicker({
            changeMonth: true,
            changeYear: true,
            dateFormat: 'mm-yy',
            onClose: function(dateText, inst) {
                var month = $("#ui-datepicker-div .ui-datepicker-month :selected").val();
                var year = $("#ui-datepicker-div .ui-datepicker-year :selected").val();
                $(this).datepicker('setDate', new Date(year, month, 1));
            }
        });
        function selectAllCheckboxes(checked) {
            $(".checkBoxClass").prop('checked', checked);
        }
        function setAction(value) {
            $('#action').val(value);
            form = $('#formsend');
            url = form.attr('action');
            if (value == 'bill_mprint') {
                form.prop("target", "_blank");
            } else if (value == 'bill_postreg') {
                form[0].addEventListener('submit', function(event) {
                    event.preventDefault();
                });
                $.ajax({
                    type: "GET",
                    url: url,
                    data: form.serialize(),
                    complete: function(data) {
                        if (data.status == 0 || data.status == 200) {
                            alert('Выбранные элементы были успешно зарегистрированы');
                        } else {
                            alert('Произошла ошибка');
                        }
                        location.reload();
                    }
                });
            }
        }
    $(function () {
        $('[data-toggle="popover"]').popover()
    })
    {/literal}
</script>
<style type="text/css">
    {literal}
    .ui-datepicker-calendar {
        display: none;
    }
    .popover{
        max-width:600px;
    }
    {/literal}
</style>