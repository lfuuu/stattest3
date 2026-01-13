
<html>

<head>
<meta http-equiv=Content-Type content="text/html; charset=utf-8">

<title>Универсальный передаточный документ N {if !$inv_number}{$bill.bill_no}{$inv_no}{else}{$inv_number}{/if} от {$inv_date|mdate:"d.m.Y г."}</title>


<style>
{literal}
@page {size: landscape;}
@page rotated {size: landscape;}
div.Section1
	{page:Section1;
	align:center;}

span {
font-size:7.5pt;
font-family:"Arial";
}

td {
padding:0cm 2.4pt 0cm 2.4pt;
}

.sign_main {
	position: absolute !important;
}
.tr_h15 td p span{font-size:7.5pt;}
.tr_h11 td p span{font-size:7.5pt;}
.tr_h20 td p span{font-size:7.5pt;}

.tr_h30 td p span{font-size:7.5pt;}
.tr_h50 td p span{font-size:7.5pt;}

.tr_h8 td p span{font-size:7.0pt;}

.tr_h2 td p span{font-size:1.0pt;}
.td_item {height: {/literal}{$print_upd.row_size}{literal}px;}
.to_client td{ font: normal 8pt sans-serif;text-decoration: underline; }

table.contract_table td {
	padding: 0;
}
{/literal}
</style>

</head>

<body marginwidth=5 marginheight=5>

{if $inv_date >= strtotime("2017-08-19")}
    {assign var="isChanges20170819" value=1}
{else}
    {assign var="isChanges20170819" value=0}
{/if}

{if $inv_date >= strtotime("2021-07-01")}
    {assign var="isChanges20210701" value=1}
{else}
    {assign var="isChanges20210701" value=0}
{/if}

<div align="center"><center>
{include file="newaccounts/details_for_print.tpl"}
<table border=0 cellspacing=0 cellpadding=0 style='border-collapse:collapse;'>
	<tr valign='top'>
		<td colspan="2" style='border-right:solid windowtext 1.5pt;'>
			<table border=0 cellspacing=0 cellpadding=0>
				<tr>
					<td colspan="3" nowrap><p><span>Универсальный<br>передаточный<br>документ<br><br></span></p></td>
				</tr>
				<tr>
					<td style="text-align: left;"><p  style='text-align:center'><span>Статус:</span></p></td>
					<td style='border:solid windowtext 2.0pt;'><p style='text-align:center'><span>1</span></p></td>
					<td>&nbsp;</td>
				</tr>
				<tr>
					<td colspan="3" valign=top><p><span style='font-size:6.5pt;'><br><br>1 - счет-фактура<br>и передаточный документ<br>(акт)<br> 2 - передаточный<br>документ (акт)</span></p></td>
				</tr>
			</table>
		</td>
		<td colspan="13">
			<div style="padding-left:10px; padding-bottom:6px;">
                <table>
                    <tr>
                        <td>
                            <table border=0 cellspacing=0 cellpadding=0>
                                <tr>
                                    <td colspan=6><p style='text-align:right'><span style='font-size:6.5pt;'>
												{if !$isChanges20170819}
													Приложение N 1 к письму ФНС России от 21.10.2013 N ММВ-20-3/96@
												{/if}
											</span></p></td>
                                </tr>
								{if !$is_document_ready}
									<tr>
										<td colspan=6><b style="color:red;">***ДОКУМЕНТ ДЛЯ ВНУТРЕННЕГО ИСПОЛЬЗОВАНИЯ***</b><br></td>
									</tr>
								{/if}
                                <tr>
                                    <td style='width:100pt;'>
										<p><span>Счет-фактура N</span></p></td>
                                    <td style='width:100pt;border-bottom:solid windowtext 1.0pt;'><p style='text-align:center'><span> {capture name=invoice_name}{if !$inv_number}{$bill.bill_no}{$inv_no}{else}{$inv_number}{/if}{/capture}{$smarty.capture.invoice_name}</span></p></td>
                                    <td valign=bottom style='width:20pt;'><p style='text-align:center'><span>от</span></p></td>
                                    <td valign=bottom style='width:100pt;border-bottom:solid windowtext 1.0pt;'><p style='text-align:center'><span>
                               {capture name=invoice_date}{if !$without_date_date} 
                                    {if $is_four_order && isset($inv_pays)}
                                        {$inv_pays[0].payment_date_ts|mdate:"d месяца Y г."}
                                    {else}
                                        {$inv_date|mdate:"d месяца Y г."}
                                    {/if}
                                {else} 
                                    {$without_date_date|mdate:"d месяца Y г."} 
                                {/if} {/capture}{$smarty.capture.invoice_date}
                                    </span></p></td>
                                    <td style='width:20pt;'><p style='text-align:center'><span>(1)</span></p></td>
                                    <td rowspan="2" valign=top style='width:460pt;'><p style='text-align:right'><span style='font-size:6.5pt;'>
												{if $isChanges20210701}
													Приложение №1 к постановлению Правительства от 26 декабря 2011г. № 1113<br/>
													(в редакции постановления Правительства Российской Федерации <br/> от 2 апреля 2021 г. №534)
												{elseif $isChanges20170819}
													Приложение №1 к постановлению Правительства от 26 декабря 2011г. № 1113<br/>
													(в редакции постановления Правительства Российской Федерации <br/> от 19 августа 2017 г. №981)
												{else}
													Приложение N 1<br>к постановлению Правительства Российской Федерации<br>от 26 декабря 2011 г. N 1137
												{/if}
									</span></p></td>
                                </tr>
                                <tr>
                                    <td><p><span>Исправление N</span></p></td>
                                    <td style='border-bottom:solid windowtext 1.0pt;'><p style='text-align:center'><span>--</span></p></td>
                                    <td><p  style='text-align:center'><span>от</span></p></td>
                                    <td style='border-bottom:solid windowtext 1.0pt;'><p style='text-align:center'><span>--</span></p></td>
                                    <td><p style='text-align:center'><span>(1а)</span></p></td>
                                </tr>
                            </table>
                            <table border=0 cellspacing=0 cellpadding=0>
                                <tr>
                                    <td style='width:155pt;'><p><b><span >Продавец:</span></b></p></td>
                                    <td style='width:635pt;border-bottom:solid windowtext 1.0pt;'><p><span>{$smarty.capture.seller}</span></p></td>
                                    <td><p style='width:20pt;text-align:right'><span>(2)</span></p></td>
                                </tr>
                                <tr>
                                    <td><p><span>Адрес:</span></p></td>
                                    <td style='border-bottom:solid windowtext 1.0pt;'><p><span>{$smarty.capture.seller_address}</span></p></td>
                                    <td><p style='text-align:right'><span>(2а)</span></p></td>
                                </tr>
                                <tr>
                                    <td><p><span>ИНН/КПП продавца:</span></p></td>
                                    <td style='border-bottom:solid windowtext 1.0pt;'><p><span>{$smarty.capture.seller_inn}</span></p></td>
                                    <td><p style='text-align:right'><span>(2б)</span></p></td>
                                </tr>
                                <tr>
                                    <td><p><span>Грузоотправитель и его адрес:</span></p></td>
                                    <td style='border-bottom:solid windowtext 1.0pt;'><p><span>{$smarty.capture.consignor}</span></p></td>
                                    <td><p style='text-align:right'><span>(3)</span></p></td>
                                </tr>
                                <tr>
                                    <td><p><span>Грузополучатель и его адрес:</span></p></td>
                                    <td style='border-bottom:solid windowtext 1.0pt;'><p><span>{$smarty.capture.consignee}</span></p></td>
                                    <td><p style='text-align:right'><span>(4)</span></p></td>
                                </tr>
                                <tr>
                                    <td><p><span>К платежно-расчетному документу N</span></p></td>
                                    <td style='border-bottom:solid windowtext 1.0pt;'><p><span>{if isset($inv_pays)} {foreach from=$inv_pays item=inv_pay name=outer}N{$inv_pay.payment_no} от {$inv_pay.payment_date_ts|mdate:"d.m.Y г."}{if !$smarty.foreach.outer.last}, {/if}{/foreach}{/if}</span></p></td>
                                    <td><p style='text-align:right'><span>(5)</span></p></td>
                                </tr>

								{if $isChanges20210701}
								<tr>
                                    <td><p><span>Документ об отгрузке № п/п</span></p></td>
                                    <td style='border-bottom:solid windowtext 1.0pt;'><p><span>{if $invoice_source != 3 || $shipped_date}{if $bill_lines|@count > 1}1-{$bill_lines|@count}{else}1{/if} № {$smarty.capture.invoice_name} от {$smarty.capture.invoice_date}{else}{section loop="43" name="mysec"}&nbsp;{/section}------{/if}</span></p></td>
                                    <td><p style='text-align:right'><span>(5а)</span></p></td>
                                </tr>
								{/if}

                                <tr>
                                    <td><p><span><b>Покупатель:</b></span></p></td>
                                    <td style='border-bottom:solid windowtext 1.0pt;'><p><span>{$smarty.capture.customer}</span></p></td>
                                    <td><p style='text-align:right'><span>(6)</span></p></td>
                                </tr>
                                <tr>
                                    <td><p><span>Адрес:</span></p></td>
                                    <td style='border-bottom:solid windowtext 1.0pt;'><p><span>{$smarty.capture.customer_address}</span></p></td>
                                    <td><p style='text-align:right'><span>(6а)</span></p></td>
                                </tr>
                                <tr>
                                    <td><p><span>ИНН/КПП покупателя:</span></p></td>
                                    <td style='border-bottom:solid windowtext 1.0pt;'><p><span>{$smarty.capture.customer_inn}</span></p></td>
                                    <td><p style='text-align:right'><span>(6б)</span></p></td>
                                </tr>
                                <tr>
                                    <td><p><span>Валюта: наименование, код</span></p></td>
                                    <td style='border-bottom:solid windowtext 1.0pt;'><p><span>Российский рубль, 643</span></p></td>
                                    <td><p style='text-align:right'><span>(7)</span></p></td>
                                </tr>
								{if $isChanges20170819}
									<tr>
										<td colspan="3">
											<table width="100%" border=0 cellspacing=0 cellpadding=0 class="contract_table">
												<td width="30%"><p><span>Идентификатор&nbsp;государственного&nbsp;контракта,&nbsp;договора&nbsp;(соглашения)&nbsp;(при&nbsp;наличии):&nbsp;</span>
													</p></td>
												<td width="69%" style='border-bottom:solid windowtext 1.0pt;'>&nbsp;</td>
												<td  width="1%"><p style='text-align:right'><span>(8)</span></p></td>
											</table>
										</td>
									</tr>
                                {/if}
                            </table>
                        </td>
                        <td align=center style="width: 100pt;">
                            {if $bill_no_qr}<img src="{if $is_pdf == '1'}{$WEB_PATH}{else}./{/if}utils/qr-code/get?data={$bill_no_qr.upd[$source]}">{else}&nbsp;{/if}</td>
                        </td>
                    </tr>
                </table>
			</div>
		</td>
	</tr>
	<tr class='tr_h30'>
		<td rowspan=2 style='border:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>N<br>п/п</span></p>
		</td>
		<td rowspan=2 style='border:solid windowtext 1.0pt;border-right:solid windowtext 1.5pt;'>
			<p style='text-align:center'><span>Код<br>товара/<br>работ,<br>услуг</span></p>
		</td>
		{if $isChanges20210701}
			<td rowspan=2 style='border:solid windowtext 1.0pt;'>
				<p style='text-align:center'><span>N<br>п/п</span></p>
			</td>
		{/if}
		<td rowspan=2 style='border:solid windowtext 1.0pt;mso-border-left-alt: solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>Наименование товара (описание выполненных работ, оказанных услуг), имущественного права</span></p>
		</td>
		{if $isChanges20170819}
			<td rowspan=2 style='border:solid windowtext 1.0pt;mso-border-left-alt: solid windowtext 1.0pt;'>
				<p style='text-align:center'><span>Код<br>вида<br>това-<br>ра</span></p>
			</td>
		{/if}
		<td colspan=2 style='border:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>Единица<br>измерения</span></p>
		</td>
		<td rowspan=2 style='border:solid windowtext 1.0pt;' nowrap>
			<p style='text-align:center'><span>Коли-<br>чество<br>(объем)</span></p>
		</td>
		<td rowspan=2 style='border:solid windowtext 1.0pt;' nowrap>
			<p style='text-align:center'><span>Цена (тариф)<br>за<br>единицу<br>измерения</span></p>
		</td>
		<td rowspan=2 style='border:solid windowtext 1.0pt;' nowrap>
			<p style='text-align:center'><span>Стоимость товаров<br>(работ, услуг),<br>имущественных<br>прав без<br>налога - всего</span></p>
		</td>
		<td rowspan=2 style='border:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>В том<br>числе<br>сумма <br>акциза</span></p>
		</td>
		<td rowspan=2 style='border:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>Нало-<br>говая ставка</span></p>
		</td>
		<td rowspan=2 style='border:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>Сумма налога, предъяв-<br>ляемая покупателю</span></p>
		</td>
		<td rowspan=2 style='border:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>Стоимость товаров<br>(работ, услуг),<br>имущественных прав<br>с налогом - всего</span></p>
		</td>
		<td colspan="2" style='border:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>Страна<br> происхождения товара</span></p>
		</td>
		<td rowspan=2 style='border:solid windowtext 1.0pt;' nowrap>
			<p style='text-align:center'><span>{if $isChanges20210701}Регистрационный номер<br>декларации на товары или<br>регистрационный номер<br>партии товара, подлежащего<br>прослеживаемости{elseif $isChanges20170819}Регистраци-<br>онный номер<br>таможенной<br>декларации } {else}Номер<br>таможен<br>ной<br>декла<br>рации{/if}</span></p>
		</td>
		{if $isChanges20210701}
			<td colspan="2" style='border:solid windowtext 1.0pt;'>
				<p style='text-align:center'><span>Количественная единица<br> измерения товара,<br> используемая в
										целях<br> осуществления<br> прослеживаемости</span></p>
			</td>
			<td rowspan=2 style='border:solid windowtext 1.0pt;' nowrap>
				<p style='text-align:center'><span>Количество товара,<br> подлежащего<br> прослеживаемости, в<br>
										количественной<br> единице<br> измерения товара,<br> используемой в целях<br>
										осуществления<br> прослеживаемости</span></p>
			</td>
		{/if}
	</tr>
	<tr class='tr_h50'>
		<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>к<br>о<br>д</span></p>
		</td>
		<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>условное обозна-<br>чение (нацио-<br>нальное)</span></p>
		</td>
		<td style='border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.0pt;' nowrap>
			<p style='text-align:center'><span>циф-<br>ро-<br>вой код</span></p>
		</td>
		<td style='border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>краткое наиме-<br>нование</span></p>
		</td>
		{if $isChanges20210701}
			<td style='border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.0pt;' nowrap>
				<p style='text-align:center'><span>код</span></p>
			</td>
			<td style='border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.0pt;'>
				<p style='text-align:center'><span>условное <br> обозначение</span></p>
			</td>
		{/if}
	</tr>
	<tr class='tr_h11'>
		<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>А</span></p>
		</td>
		<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.5pt;'>
			<p style='text-align:center'><span>Б</span></p>
		</td>
		<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>1</span></p>
		</td>
		{if $isChanges20210701}

			<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
				<p style='text-align:center'><span>1a</span></p>
			</td>
		{else}

			<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
				<p style='text-align:center'><span>1</span></p>
			</td>
		{/if}
		
		{if $isChanges20210701}
			<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
				<p style='text-align:center'><span>1б</span></p>
			</td>
		{elseif $isChanges20170819}
			<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
				<p style='text-align:center'><span>1a</span></p>
			</td>
		{/if}
		<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>2</span></p>
		</td>
		<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>2а</span></p>
		</td>
		<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>3</span></p>
		</td>
		<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>4</span></p>
		</td>
		<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>5</span></p>
		</td>
		<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;' >
			<p style='text-align:center'><span>6</span></p>
		</td>
		<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;' >
			<p style='text-align:center'><span>7</span></p>
		</td>
		<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;' >
			<p style='text-align:center'><span>8</span></p>
		</td>
		<td style='border:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>9</span></p>
		</td>
		<td style='border-bottom:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>10</span></p>
		</td>
		<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>10а</span></p>
		</td>
		<td style='border:solid windowtext 1.0pt;' >
			<p style='text-align:center'><span>11</span></p>
		</td>
		{if $isChanges20210701}
			<td style='border-bottom:solid windowtext 1.0pt;'>
				<p style='text-align:center'><span>12</span></p>
			</td>
			<td style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
				<p style='text-align:center'><span>12а</span></p>
			</td>
			<td style='border:solid windowtext 1.0pt;' >
				<p style='text-align:center'><span>13</span></p>
			</td>
		{/if}
	</tr>
{foreach from=$bill_lines item=row key=key name='list'}
	{if $print_upd.newPageLineIndex.$key}
		<tr class='tr_h15' style="page-break-after: always;">
			<td colspan="{if $isChanges20170819}16{else}15{/if}" style="height: {$print_upd.newPageLineIndex.$key}px;"><p ><span>&nbsp;</span></p></td>
		</tr>
	{/if}
	<tr class='tr_h15'>
		
		<td valign=top style='border:solid windowtext 1.0pt;'>
			<p  align=right style='text-align:right'><span>{$smarty.foreach.list.iteration}</span></p>
		</td>
		<td valign=top style='border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.5pt;'>
			<p ><span>&nbsp;</span></p>
		</td>
		{if $isChanges20210701}
			<td valign=top style='border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.5pt;'>
				<p  align=right style='text-align:right'><span>{$smarty.foreach.list.iteration}</span></p>
			</td>
		{/if}
		
		<td valign=top style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;' class="td_item">
			<p ><span>{$row.item}</span></p>
		</td>
		{if $isChanges20170819}
			<td valign=top style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
				<p ><span>--</span></p>
			</td>
		{/if}
		<td valign=top style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>
                {if $is_four_order}
                --
                {else}
                    {if $inv_is_new4}
                        {if isset($row.okvd_code) && $row.okvd_code}
                            {$row.okvd_code|string_format:"%03d"}
                        {else}
                            {if $row.type == "service"}
                            --
                            {else}
                            796
                            {/if}
                        {/if}
                    {else}
                    796
                    {/if}
                {/if}</span></p>
		</td>
		<td valign=top style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>
            {if $is_four_order}
                --
            {else}
                {if $inv_is_new4}
                    {if isset($row.okvd_code) && $row.okvd_code}
                        {$row.okvd_code|okei_name}
                    {else}
                        {if $row.type == "service"}
                            --
                        {else}
                            шт.
                        {/if}
                    {/if}
                {else}
                    шт.
                {/if}
            {/if}</span></p>
		</td>
		<td valign=top style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p  align=right style='text-align:right'><span>
        {if $bill.bill_date < "2012-01-01"}{$row.amount|round:2}{else}
            {if $is_four_order}
                --
            {else}
                {if $inv_is_new4}
                    {if isset($row.okvd_code) && $row.okvd_code}
                        {$row.amount|round:2}
                    {else}

                        {if $row.type == "service"}
                            --
                        {else}
                            {$row.amount|round:2}
                        {/if}
                    {/if}
                {else}
                    {$row.amount|round:2}
                {/if}
            {/if}
        {/if}</span></p>
		</td>
		<td valign=top style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p  align=right style='text-align:right'><span>
            {if $is_four_order}
                --
            {else}
                {if $inv_is_new4}
                    {if isset($row.okvd_code) && $row.okvd_code}
                        {$row.outprice|round:2}
                    {else}
                        {if $row.type == "service"}
                            --
                        {else}
                            {$row.outprice|round:2}
                        {/if}
                    {/if}
                {else}
                    {$row.outprice|round:2}
                {/if}
            {/if}</span></p>
		</td>
		<td valign=top style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p  align=right style='text-align:right'><span>
            {if $is_four_order}
                --
            {else}
                {$row.sum_without_tax|round:2}
            {/if}</span></p>
		</td>
		<td valign=top style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;' nowrap>
			<p ><span>{if $inv_is_new4}без акциза{else}--{/if}</span></p>
		</td>
		<td valign=top style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>{if $row.tax_rate == 0}без НДС{else}{if $is_four_order eq true}{$row.tax_rate}%/1{$row.tax_rate}%{else}{$row.tax_rate}%{/if}{/if}</span></p>
		</td>
		<td valign=top style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p align=right style='text-align:right'><span>
            {if $row.tax_rate == 0}
                --
            {else}
                {$row.sum_tax|round:2}
            {/if}
            </span></p>
		</td>
		<td valign=top style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p align=right style='text-align:right'><span>
                {$row.sum|round:2}
            </span></p>
		</td>
		<td valign=top style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>{if !isset($row.country_id) || $row.country_id == 0}--{else}{$row.country_id}{/if}</span></p>
		</td>
		<td valign=top style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>{if isset($row.country_name)}{$row.country_name|default:"--"}{else}--{/if}</span></p>
		</td>
		<td valign=top style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.0pt;'>
			<p ><span>{if isset($row.gtd)}{$row.gtd|default:"--"}{else}--{/if}</span></p>
		</td>
		{if $isChanges20210701}
			<td valign=top style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
				<p ><span>--</span></p>
			</td>
			<td valign=top style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
				<p ><span>--</span></p>
			</td>
			<td valign=top style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.0pt;'>
				<p ><span>--</span></p>
			</td>
		{/if}
	</tr>
{/foreach}
	<tr class='tr_h11'>
		<td colspan="2" valign=bottom style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.5pt;'>
			<p  align=right style='text-align:right'><span>&nbsp;</span></p>
		</td>
		{if $isChanges20210701}
			<td colspan="1" valign=bottom style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.5pt;'>
			<p  align=right style='text-align:right'><span>&nbsp;</span></p>
		</td>
		{/if}
		<td colspan={if $isChanges20170819}6{else}5{/if} valign=bottom style='border-left:solid windowtext 1.0pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><b><span>Всего к оплате</span></b></p>
		</td>
		<td valign=bottom style='border:solid windowtext 1.0pt;'>
			<p  align=right style='text-align:right'><span>{if $is_four_order}--{else}{$bill.sum_without_tax|round:2}{/if}</span></p>
		</td>
		<td colspan=2 valign=bottom style='border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span><b>Х</b></span></p>
		</td>
		<td valign=bottom style='border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.0pt;'>
			<p  align=right style='text-align:right'><span>
            {if $bill.sum_tax == 0 && $bill.sum}
                --
            {else}
                {$bill.sum_tax|round:2}
            {/if}
            </span></p>
		</td>
		<td valign=bottom style='border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.0pt;'>
			<p  align=right style='text-align:right'><span>{$bill.sum|round:2}</span></p>
		</td>
		<td colspan="3" valign=bottom style='border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.0pt;'>
			<p ><span>&nbsp;</span></p>
		</td>
		{if $isChanges20210701}
			<td colspan="1" valign=bottom style='border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.0pt;'>
				<p ><span>&nbsp;</span></p>
			</td>
			<td colspan="1" valign=bottom style='border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.0pt;'>
				<p ><span>&nbsp;</span></p>
			</td>
			<td colspan="1" valign=bottom style='border-bottom:solid windowtext 1.0pt;border-right:solid windowtext 1.0pt;'>
				<p ><span>&nbsp;</span></p>
			</td>
		{/if}
		
	</tr>
	<tr>
		<td colspan="2" valign="top" style='border-right:solid windowtext 1.5pt;'><p ><span><br />Документ<br>составлен на<br>{if $print_upd.pages == 1}1 листе{else}{$print_upd.pages} листах{/if}</span></p></td>
		<td colspan="13"  style='border-bottom:solid windowtext 1.5pt;'>
			<div style="padding-bottom:4px;">
	<table border=0 cellspacing=0 cellpadding=0>
	<tr class='tr_h20'>
		<td valign=bottom style='width:160pt;'>
			<p ><span>Руководитель организации<br>или иное уполномоченное лицо</span></p>
		</td>
		<td valign=bottom style='width:90pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span style="position: relative;">{if !$client.is_upd_without_sign && $bill.is_rollback != 1 && isset($firm_director.sign) && $firm_director.sign && isset($emailed) && $emailed==1} <img src="{if $is_pdf == '1'}{$WEB_PATH}images/{else}{$IMAGES_PATH}{/if}{$firm_director.sign.src}"  border="0" alt="" align="top"{if $firm_director.sign.width} width="{$firm_director.sign.width}" height="{$firm_director.sign.height}"{/if} style="position: absolute; top: -40px;">{else}&nbsp;{/if}</span></p>
		</td>
		<td valign=bottom style="width:5pt">
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=bottom style='width:90pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>{$smarty.capture.seller_head_name}</span></p>
		</td>
		<td valign=bottom style="width:5pt">
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=bottom style='width:160pt;'>
			<p ><span>Главный бухгалтер<br>или иное уполномоченное лицо</span></p>
		</td>
		<td valign=bottom style='width:90pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span style="position: relative;">{if !$client.is_upd_without_sign && $bill.is_rollback != 1 && isset($firm_buh.sign) && $firm_buh.sign && isset($emailed) && $emailed==1} <img src="{if $is_pdf == '1'}{$WEB_PATH}images/{else}{$IMAGES_PATH}{/if}{$firm_buh.sign.src}"  border="0" alt="" align="top"{if $firm_buh.sign.width} width="{$firm_buh.sign.width}" height="{$firm_buh.sign.height}"{/if} style="position: absolute; top: -25px;">{else}&nbsp;{/if}</span></p>
		</td>
		<td valign=bottom style="width:5pt">
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=bottom style='width:90pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>{$smarty.capture.seller_buh_name}</span></p>
		</td>
	</tr>
	<tr class='tr_h8'>
		<td valign=bottom>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=top>
			<p style='text-align:center'><span>(подпись)</span></p>
		</td>
		<td valign=bottom>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=top>
			<p style='text-align:center'><span>(ф.и.о.)</span></p>
		</td>
		<td valign=bottom>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=bottom>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=top>
			<p style='text-align:center'><span>(подпись)</span></p>
		</td>
		<td valign=bottom>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=top>
			<p style='text-align:center'><span>(ф.и.о.)</span></p>
		</td>
	</tr>
	<tr class='tr_h20'>
		<td valign=bottom>
			<p ><span>Индивидуальный&nbsp;предприниматель{if $isChanges20170819} или<br />иное уполномоченное лицо{/if}</span></p>
		</td>
		<td valign=bottom style='border-bottom:solid windowtext 1.0pt;'>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=bottom>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=bottom style='border-bottom:solid windowtext 1.0pt;'>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=bottom>
			<p ><span>&nbsp;</span></p>
		</td>
		<td colspan="4" valign=bottom style='border-bottom:solid windowtext 1.0pt;'>
			<p style='text-align:center'><span>&nbsp;</span></p>
		</td>
	</tr>
	<tr class='tr_h8'>
		<td valign=bottom>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=top>
			<p  style='text-align:center'><span>(подпись)</span></p>
		</td>
		<td valign=bottom>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=top>
			<p  style='text-align:center'><span>(ф.и.о.)</span></p>
		</td>
		<td valign=bottom>
			<p ><span>&nbsp;</span></p>
		</td>
		<td colspan=4 valign=top>
			<p style='text-align:center'><span>(реквизиты свидетельства о государственной регистрации индивидуального предпринимателя)</span></p>
		</td>
	</tr>
	</table>
			</div>
		</td>
	</tr>
</table>

<table border=0 cellspacing=0 cellpadding=0 style='width:100%;'>
	<tr class='tr_h15'>
		<td valign=bottom nowrap>
			<p ><span>Основание передачи (сдачи) / получения (приемки)</span></p>
		</td>
		<td valign=bottom style='border-bottom:solid windowtext 1.0pt;width:100%;'>
			<p ><span>&nbsp;{if isset($client_contract)}&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; договор {$client_contract}{/if}</span></p>
		</td>
		<td valign=bottom>
			<p ><span>[8]</span></p>
		</td>
	</tr>
	<tr class='tr_h8'>
		<td valign=bottom>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=bottom>
			<p style='text-align:center'><span>(договор; доверенность и др.)</span></p>
		</td>
		<td valign=bottom>
			<p ><span>&nbsp;</span></p>
		</td>
	</tr>
</table>
<table border=0 cellspacing=0 cellpadding=0 style='width:100%;'>
	<tr class='tr_h15'>
		<td valign=bottom nowrap>
			<p><span>Данные о транспортировке и грузе</span></p>
		</td>
		<td valign=bottom style='border-bottom:solid windowtext 1.0pt;width:100%;'>
			<p><span>&nbsp;</span></p>
		</td>
		<td valign=bottom>
			<p><span>[9]</span></p>
		</td>
	</tr>
	<tr class='tr_h8'>
		<td valign=bottom>
			<p><span>&nbsp;</span></p>
		</td>
		<td valign=bottom nowrap>
			<p style='text-align:center;'><span>(транспортная накладная, поручение экспедитору, экспедиторская/складская расписка и др./масса нетто/брутто груза, если не приведены ссылки на транспортные документы, содержащие эти сведения)</span></p>
		</td>
		<td valign=bottom>
			<p ><span>&nbsp;</span></p>
		</td>
	</tr>
</table>

<table border=0 cellspacing=0 cellpadding=0 style='border-collapse:collapse;width:100%;'>
	<tr class='tr_h15'>
		<td colspan="6" nowrap>
			<p ><span>Товар (груз) передал / услуги, результаты работ, права сдал</span></p>
		</td>
		<td rowspan="14" style='width:4.0pt;border-left: solid windowtext 1.0pt;'>
			<p ><span style='font-size:1.0pt;'>&nbsp;</span></p>
		</td>
		<td colspan="6" nowrap>
			<p ><span>Товар (груз) получил / услуги, результаты работ, права принял</span></p>
		</td>
	</tr>
	<tr class='tr_h15'>
		<td style='width:140pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>{$smarty.capture.seller_head_position}</span></p>
		</td>
		<td>
			<p ><span style="position: relative;">{if !$client.is_upd_without_sign && $bill.is_rollback != 1 && isset($firm_director.sign) && $firm_director.sign && isset($emailed) && $emailed==1} <img src="{if $is_pdf == '1'}{$WEB_PATH}images/{else}{$IMAGES_PATH}{/if}{$firm_director.sign.src}"  border="0" alt="" align="top"{if $firm_director.sign.width} width="{$firm_director.sign.width}" height="{$firm_director.sign.height}"{/if} style="position: absolute; top: -40px;">{else}&nbsp;{/if}</span></p>
		</td>
		<td style='width:110pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>&nbsp;</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td style='width:110pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>{$smarty.capture.seller_head_name}</span></p>
		</td>
		<td style='width:20pt;'>
			<p ><span>[10]</span></p>
		</td>
		<td style='width:140pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>{$smarty.capture.customer_head_position}</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td style='width:110pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>&nbsp;</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td style='width:110pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>{$smarty.capture.customer_head_name}</span></p>
		</td>
		<td style='width:20pt;'>
			<p ><span>[15]</span></p>
		</td>
	</tr>
	<tr class='tr_h8'>
		<td valign=top>
			<p style='text-align:center'><span>(должность)</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=top>
			<p style='text-align:center'><span>(подпись)</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=top>
			<p style='text-align:center'><span>(ф.и.о.)</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=top>
			<p style='text-align:center'><span>(должность)</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=top>
			<p style='text-align:center'><span>(подпись)</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=top>
			<p style='text-align:center'><span>(ф.и.о.)</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
	</tr>
	<tr class='tr_h15'>
		<td nowrap>
			<p ><span>Дата отгрузки, передачи (сдачи)</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td style='border-bottom:solid windowtext 1.0pt;'>
			<p ><span>
                {if !$without_date_date}
                    {if $is_four_order && isset($inv_pays)}
                        {$inv_pays[0].payment_date_ts|mdate:"d месяца Y года"}
                    {else}
                        {$inv_date|mdate:"d месяца Y года"}
                    {/if}
                {else} 
                    {$without_date_date|mdate:"d месяца Y года"}
                {/if}</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td>
			<p ><span>[11]</span></p>
		</td>
		<td nowrap>
			<p ><span>Дата получения (приемки)</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td style='border-bottom:solid windowtext 1.0pt;'>
			<p><span>"{section loop="6" name="mysec"}&nbsp;{/section}"{section loop="21" name="mysec"}&nbsp;{/section}20{section loop="7" name="mysec"}&nbsp;{/section}года</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td>
			<p ><span>[16]</span></p>
		</td>
	</tr>
	<tr class='tr_h15'>
		<td colspan="6" nowrap>
			<p ><span>Иные сведения об отгрузке, передаче</span></p>
		</td>
		<td colspan="6" nowrap>
			<p ><span>Иные сведения о получении, приемке</span></p>
		</td>
	</tr>
	<tr class='tr_h15'>
		<td colspan="5" style='border-bottom:solid windowtext 1.0pt;'>
			<p ><span>&nbsp;</span></p>
		</td>
		<td style='border:none;'>
			<p ><span>[12]</span></p>
		</td>
		<td colspan="5" style='border-bottom:solid windowtext 1.0pt;'>
			<p ><span>&nbsp;</span></p>
		</td>
		<td>
			<p ><span>[17]</span></p>
		</td>
	</tr>
	<tr class='tr_h8'>
		<td colspan="5" valign=top nowrap>
			<p style='text-align:center'><span>(ссылки на неотъемлемые приложения, сопутствующие документы, иные документы и т.п.)</span></p>
		</td>
		<td valign=top>
			<p ><span>&nbsp;</span></p>
		</td>
		<td colspan="5" valign=top nowrap>
			<p style='text-align:center'><span>(информация о наличии/отсутствии претензии; ссылки на неотъемлемые приложения, и другие документы и т.п.)</span></p>
		</td>
		<td valign=top>
			<p ><span>&nbsp;</span></p>
		</td>
	</tr>
	<tr class='tr_h15'>
		<td colspan="6" nowrap>
			<p ><span>Ответственный за правильность оформления факта хозяйственной жизни</span></p>
		</td>
		<td colspan="6" nowrap>
			<p ><span>Ответственный за правильность оформления факта хозяйственной жизни</span></p>
		</td>
	</tr>
	<tr class='tr_h15'>
		<td style='width:100pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>{$smarty.capture.seller_buh_position}</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td style='width:100pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span style="position: relative;">{if !$client.is_upd_without_sign && $bill.is_rollback != 1 && isset($firm_buh.sign) && $firm_buh.sign && isset($emailed) && $emailed==1} <img src="{if $is_pdf == '1'}{$WEB_PATH}images/{else}{$IMAGES_PATH}{/if}{$firm_buh.sign.src}"  border="0" alt="" align="top"{if $firm_buh.sign.width} width="{$firm_buh.sign.width}" height="{$firm_buh.sign.height}"{/if} style="position: absolute; top: -22px;">{else}&nbsp;{/if}</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=bottom style='width:100pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>{$smarty.capture.seller_buh_name}</span></p>
		</td>
		<td>
			<p ><span>[13]</span></p>
		</td>
		<td style='width:100pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>{$smarty.capture.customer_buh_position}</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td style='width:100pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>&nbsp;</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td style='width:100pt;border-bottom:solid windowtext 1.0pt;'>
			<p ><span>{$smarty.capture.customer_buh_name}</span></p>
		</td>
		<td>
			<p ><span>[18]</span></p>
		</td>
	</tr>
	<tr class='tr_h8'>
		<td valign=top>
			<p style='text-align:center'><span>(должность)</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=top>
			<p style='text-align:center'><span>(подпись)</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=top>
			<p style='text-align:center'><span>(ф.и.о.)</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=top>
			<p style='text-align:center'><span>(должность)</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=top>
			<p style='text-align:center'><span>(подпись)</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=top>
			<p style='text-align:center'><span>(ф.и.о.)</span></p>
		</td>
		<td>
			<p ><span>&nbsp;</span></p>
		</td>
	</tr>
	<tr class='tr_h15'>
		<td colspan="6" nowrap>
			<p ><span>Наименование экономического субъекта - составителя документа (в т.ч. комиссионера / агента)</span></p>
		</td>
		<td colspan="6" nowrap>
			<p ><span>Наименование экономического субъекта - составителя документа</span></p>
		</td>
	</tr>
	<tr class='tr_h15'>
		<td colspan="5" style='border-bottom:solid windowtext 1.0pt;'>
			<p ><span>{$smarty.capture.seller_firm_info}</span></p>
		</td>
		<td valign=bottom>
			<p ><span>[14]</span></p>
		</td>
		<td colspan="5" style='border-bottom:solid windowtext 1.0pt;'>
			<p ><span>{$smarty.capture.customer_firm_info}</span></p>
		</td>
		<td>
			<p ><span>[19]</span></p>
		</td>
	</tr>
	<tr class='tr_h8'>
		<td colspan="5" valign=top nowrap>
			<p style='text-align:center'><span>(может не заполняться при проставлении печати в М.П., может быть указан ИНН / КПП)</span></p>
		</td>
		<td valign=top>
			<p ><span>&nbsp;</span></p>
		</td>
		<td colspan="5" valign=top nowrap>
			<p style='text-align:center'><span>(может не заполняться при проставлении печати в М.П., может быть указан ИНН / КПП)</span></p>
		</td>
		<td valign=top>
			<p ><span>&nbsp;</span></p>
		</td>
	</tr>
	<tr class='tr_h15'>
		<td valign=bottom>
			<p style='text-align:center'><span>МП</span></p>
		</td>
		<td colspan="5">
			<p ><span>&nbsp;</span></p>
		</td>
		<td valign=bottom>
			<p style='text-align:center'><span>МП</span></p>
		</td>
		<td colspan="5">
			<p ><span>&nbsp;</span></p>
		</td>
	</tr>
</table>
</center></div>
{if isset($emailed) && $emailed==1 && !$client.is_upd_without_sign && $bill.is_rollback != 1}
	{if $firma && isset($firma.src) && $firma.src}
	<div style="position: relative; top: -50;left: 140px;">
	<img class="sign_main" style='{$firma.style}' src="{if $is_pdf == '1'}{$WEB_PATH}images/{else}{$IMAGES_PATH}{/if}{$firma.src}"{if $firma.width} width="{$firma.width}" height="{$firma.height}"{/if}>
	</div>
	{/if}
{/if}

{if $to_client == "true" && ($bill_client.firma == 'mcn' || $bill_client.firma == 'mcn_telekom' || $bill_client.firma == 'mcm_telekom')}
<table border=0 cellspacing=0 cellpadding=0 style='width:100%;'>
	<tr>
		<td colspan="6" width=50%>
			<p ><span>&nbsp;</span></p>
		</td>
		<td colspan="6" width=50%>
			<p ><table class=to_client><tr><td valign=top><br><b>Обращаем Ваше Вниманиние!</b></td><td>Этот экземпляр УПД, просьба с подписью и печатью<br> направить в наш адрес: {$organization.post_address}, {$organization.name}</td></tr></table></div></p>
		</td>
	</tr>
</table>
{/if}
</body>
</html>
