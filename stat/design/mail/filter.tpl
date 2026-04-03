<ul class="breadcrumb">
       <li class="active">Письма клиентам.</li>
       <li><a href='{$LINK_START}&module=mail&action=view&id={$mail_id}'>Письмо &#8470;{$mail_id}</a></li>
       <li class="active">Добавление клиентов в очередь на отправку писем</li>
</ul>

{if count($mail_clients)}
<TABLE class=price cellSpacing=4 cellPadding=2 border=0 style='width:*' width="*">
<FORM action="?" method=post id=form name=form>
<input type=hidden name=action value=client>
<input type=hidden name=id value={$mail_id}>
<input type=hidden name=module value=mail>
<input type="hidden" name="filter[organization][0]" value="{$mail_filter.organization.0}">
<input type="hidden" name="filter[region_for][0]" value="{$mail_filter.region_for.0}">
<input type="hidden" name="filter[manager][0]" value="{$mail_filter.manager.0}">
<input type="hidden" name="filter[bill][0]" value="{$mail_filter.bill.0}">
<input type="hidden" name="date_from" value="{$date_from}">
<input type="hidden" name="date_to" value="{$date_to}">
<input type="hidden" name="filter[closing_docs][0]" value="{$mail_filter.closing_docs.0}">
<input type="hidden" name="filter[closing_docs][1]" value="{$mail_filter.closing_docs.1}">
<input type="hidden" name="filter[pay_type][0]" value="{$mail_filter.pay_type.0}">
<input type="hidden" name="filter[s8800][0]" value="{$mail_filter.s8800.0}">
<input type="hidden" name="filter[node][0]" value="{$mail_filter.node.0}">
<input type="hidden" name="disable_filter" value="Y">
{if isset($mail_filter.regions)}
{foreach from=$mail_filter.regions item="r"}
	<input type="hidden" name="filter[regions][]" value="{$r}">
{/foreach}
{/if}
{if isset($mail_filter.tarifs)}
{foreach from=$mail_filter.tarifs item="t"}
	<input type="hidden" name="filter[tarifs][]" value="{$t}">
{/foreach}
{/if}

<TBODY>
<TR>
  <TD class=header vAlign=bottom>Клиент</TD>
  <TD class=header valign=bottom><input type=checkbox id='allconfirm' {if !$disable_filter}checked{/if} onclick='javascript:check_all()'></td>
  <TD class=header valign=bottom><input type=checkbox id='allconfirm2' checked onclick='javascript:check_all2()'></td>
  <TD>&nbsp;</TD>
  </TR>
{foreach from=$mail_clients item=r name=outer}{if !isset($r.letter_state) || $r.letter_state!="sent"}
<TR class={if $smarty.foreach.outer.iteration%2==0}even{else}odd{/if}>
	<TD><input type=hidden value='{$r.client}' name='clients[{$smarty.foreach.outer.iteration}]'><a href='/client/view?id={$r.id}'>{$r.client}</a></TD>
	<TD><input type=checkbox value=1 name='flag[{$smarty.foreach.outer.iteration}]' id='flag_{$smarty.foreach.outer.iteration}'{if $r.filtered && !$disable_filter} checked{/if}></TD>
	<TD><input type=checkbox value=1 name='flag2[{$smarty.foreach.outer.iteration}]' id='flag2_{$smarty.foreach.outer.iteration}'{if $r.selected} checked{/if}></TD>
	<TD><input type=hidden value='{if isset($r.email)}{$r.email}{/if}' name='emails[{$smarty.foreach.outer.iteration}]'>{if isset($r.email)}{$r.email}{/if}</TD>
</TR>
{/if}{/foreach}
</TBODY></TABLE>
	<div>Количество: {$smarty.foreach.outer.iteration}</div>
<INPUT id=submit class=button type=submit value="Добавить всех этих клиентов в список на отправку">
</FORM>
<script>
function check_all(){ldelim}
	v=form.allconfirm.checked;
{foreach from=$mail_clients item=r name=outer}{if $r.filtered && (!isset($r.letter_state) || $r.letter_state!="sent")}
	form.flag_{$smarty.foreach.outer.iteration}.checked=v;
{/if}{/foreach}
{rdelim}
function check_all2(){ldelim}
	v=form.allconfirm2.checked;
{foreach from=$mail_clients item=r name=outer}{if $r.selected && (!isset($r.letter_state) || $r.letter_state!="sent")}
	form.flag2_{$smarty.foreach.outer.iteration}.checked=v;
{/if}{/foreach}
{rdelim}
</script>
{/if}

<form action="?" method="post" id="form2" name="form2" class="form-horizontal">
<input type="hidden" name="action" value="client">
<input type="hidden" name="id" value="{$mail_id}">
<input type="hidden" name="module" value="mail">
<input type="hidden" name="ack" value="1">

<div class="form-group">
	<label class="col-sm-2 control-label">Организация</label>
	<div class="col-sm-10">
		<select class="select2-mail form-control" name='filter[organization][0]' data-placeholder="(не фильтровать по этому полю)">
			<option value=''></option>
			{foreach from=$f_organization item=r}
				<option value="{$r.organization_id}" {if $mail_filter.organization.0 == $r.organization_id}selected="selected"{/if}>{$r.name}</option>
			{/foreach}
		</select>
	</div>
</div>

<div class="form-group">
	<label class="col-sm-2 control-label">Статус клиента</label>
	<div class="col-sm-10">
		<p class="form-control-static">Все статусы бизнес-процессов с разрешенной отправкой счетов</p>
	</div>
</div>

<div class="form-group">
	<label class="col-sm-2 control-label">Менеджер</label>
	<div class="col-sm-10">
		<select class="select2-mail form-control" name='filter[manager][0]' data-placeholder="(не фильтровать по этому полю)">
			<option value=''></option>
			{foreach from=$f_manager item=r}<option value='{$r.user}'{if $r.user==$mail_filter.manager.0} selected="selected"{/if}>{$r.name} ({$r.user})</option>{/foreach}
		</select>
	</div>
</div>

<div class="form-group">
	<label class="col-sm-2 control-label">Счета</label>
	<div class="col-sm-10 form-inline">
		<select class="select2-mail form-control" name='filter[bill][0]' data-placeholder="(не фильтровать по этому полю)">
			<option value=''></option>
			<option value='1' {if $mail_filter.bill.0 == 1}selected{/if}>любые</option>
			<option value='2' {if $mail_filter.bill.0 == 2}selected{/if}>полностью неоплаченные(красные)</option>
			<option value='3' {if $mail_filter.bill.0 == 3}selected{/if}>оплаченные не полностью(желтые)</option>
			<option value='4' {if $mail_filter.bill.0 == 4}selected{/if}>не полностью оплаченные(красные и желтые)</option>
		</select>
		с <input type="text" class="form-control input-sm" style="width:120px" name="date_from" id="date_from" value="{$date_from}">
		по <input type="text" class="form-control input-sm" style="width:120px" name="date_to" id="date_to" value="{$date_to}">
	</div>
</div>

<div class="form-group">
	<label class="col-sm-2 control-label">Закрывающие документы</label>
	<div class="col-sm-10 form-inline">
		<select class="select2-mail form-control" name='filter[closing_docs][0]' data-placeholder="(не фильтровать по этому полю)">
			<option value=''></option>
			<option value='1' {if $mail_filter.closing_docs.0 == 1}selected{/if}>есть закрывающие документы</option>
		</select>
		за месяц: <input type="month" class="form-control input-sm" style="width:180px" name="filter[closing_docs][1]" value="{$mail_filter.closing_docs.1}">
	</div>
</div>

<div class="form-group">
	<label class="col-sm-2 control-label">Тип оплаты</label>
	<div class="col-sm-10">
		<select class="select2-mail form-control" name='filter[pay_type][0]' data-placeholder="(не фильтровать по этому полю)">
			<option value=''></option>
			{foreach from=$f_payment_types key=k item=v}<option value='{$k}' {if $mail_filter.pay_type.0 !== '' && $mail_filter.pay_type.0 === (string)$k}selected{/if}>{$v}</option>{/foreach}
		</select>
	</div>
</div>

<div class="form-group">
	<label class="col-sm-2 control-label">Услуга 8800</label>
	<div class="col-sm-10">
		<select class="select2-mail form-control" name='filter[s8800][0]' data-placeholder="(не фильтровать по этому полю)">
			<option value=''></option>
			<option value='with'{if $mail_filter.s8800.0 == 'with'} selected{/if}>с услугой</option>
			<option value='without'{if $mail_filter.s8800.0 == 'without'} selected{/if}>без услуги</option>
		</select>
	</div>
</div>

<div class="form-group">
	<label class="col-sm-2 control-label">Роутер</label>
	<div class="col-sm-10">
		<select class="select2-mail form-control" name='filter[node][0]' data-placeholder="(не фильтровать по этому полю)">
			<option value=''></option>
			{foreach from=$f_node item=r}<option value='{$r.node}'{if $r.node==$mail_filter.node.0} selected="selected"{/if}>{$r.node} ({$r.address})</option>{/foreach}
		</select>
	</div>
</div>

<div class="form-group">
	<label class="col-sm-2 control-label">Регионы</label>
	<div class="col-sm-10">
		<select class="select2-mail form-control" name="filter[region_for][0]" data-placeholder="(не фильтровать по этому полю)" onchange="show_all_regions(this.value);">
			<option value="" {if !$mail_filter.region_for.0 || $mail_filter.region_for.0 == ''} selected="selected"{/if}></option>
			<option value="client" {if $mail_filter.region_for.0 == 'client'} selected="selected"{/if}>Регионы для клиентов</option>
			<option id="for_tarifs" value="tarif" {if $mail_filter.region_for.0 == 'tarif'} selected="selected"{/if}>Регионы для номеров</option>
		</select>
	</div>
</div>

<div id="tr_regions" class="form-group" {if $mail_filter.region_for.0 != 'tarif' && $mail_filter.region_for.0 != 'client'}style="display: none;"{/if}>
	<div class="col-sm-offset-2 col-sm-10" id="all_regions">
		{foreach from=$f_regions item="reg"}
			<div style="float: left; margin-right: 15px;">
			{foreach from=$reg item="r"}
				{capture name="region_`$r.id`"}
					<div>{$r.name}</div>
				{/capture}
				<div class="checkbox">
					<label>
						<input onchange="show_regions_tarifs('{$r.id}');" id="region_{$r.id}" type="checkbox" name='filter[regions][]' value="{$r.id}" {if isset($mail_filter.regions) && $r.id|in_array:$mail_filter.regions}checked="checked"{/if}>
						{$r.name}
					</label>
				</div>
			{/foreach}
			</div>
		{/foreach}
		<div style="clear: both;"></div>
	</div>
</div>

<div id="tr_tarifs" class="form-group" {if $mail_filter.region_for.0 != 'tarif'}style="display: none;"{/if}>
	<label class="col-sm-2 control-label">Тарифы</label>
	<div class="col-sm-10">
		{foreach from=$f_tarifs item="reg" key="k"}
		{assign var="selected_region" value=false}
		{if isset($mail_filter.regions) && $k|in_array:$mail_filter.regions && $mail_filter.region_for.0 == 'tarif'}
			{assign var="selected_region" value=true}
		{/if}
		<div id="tarifs_for_{$k}" style="margin-bottom: 10px; {if !$selected_region}display:none;{/if}">
			{assign var="name" value="region_`$k`"}
			{$smarty.capture.$name}
			{foreach from=$reg item="r"}
				<div style="float: left; margin-right: 15px;">
				{foreach from=$r item="t"}
					<div class="checkbox" style="font-size: 11px;">
						<label>
							<input {if !$selected_region}disabled="disabled"{/if} id="tarif_{$t.id}" type="checkbox" name='filter[tarifs][]' value="{$t.id}" {if isset($mail_filter.tarifs) && $t.id|in_array:$mail_filter.tarifs}checked="checked"{/if}>
							{$t.name}
						</label>
					</div>
				{/foreach}
				</div>
			{/foreach}
			<div style="clear: both;"></div>
		</div>
		{/foreach}
	</div>
</div>

<div class="form-group">
	<div class="col-sm-offset-2 col-sm-10">
		<button type="submit" class="btn btn-primary">Фильтр</button>
	</div>
</div>

</form>
<script>
	optools.DatePickerInit();
	{literal}
	$(document).ready(function() {
		$('<style>')
			.text('#form2 .select2-selection--single { position: relative; } #form2 .select2-selection__clear { position: absolute; right: 20px; top: 50%; transform: translateY(-50%); z-index: 1; }')
			.appendTo('head');
		$('.select2-mail').select2({
			placeholder: '(не фильтровать по этому полю)',
			allowClear: true,
			width: '300px'
		});
	});
	{/literal}
	{literal}
	function show_regions_tarifs(id)
	{
		var isTarif=$('#for_tarifs')[0].selected;
		var isSelected=$('#region_'+id)[0].checked;
		if (isTarif && isSelected) {
			$('#tarifs_for_'+id).show();
		} else {
			$('#tarifs_for_'+id).hide();
		}
		$('#tarifs_for_'+id+' input[type=checkbox]').each(function(o,i){i.disabled = !(isSelected && isTarif);});
	}
	function show_all_regions(value)
	{
		switch (value) {
			case 'client':
				$('#tr_regions').show();
				$('#tr_regions input[type=checkbox]').each(function(o,i){i.disabled = false;});
				var regions = $('#all_regions input[type=checkbox]');
				regions.each(function(o,i){
					$('#tarifs_for_'+i.value).hide();
				});
				$('#tr_tarifs').hide();
				$('#tr_tarifs input[type=checkbox]').each(function(o,i){i.disabled = true;});
				break;
			case 'tarif':
				$('#tr_regions').show();
				$('#tr_regions input[type=checkbox]').each(function(o,i){i.disabled = false;});
				$('#tr_tarifs').show();
				var regions = $('#all_regions input[type=checkbox]');
				regions.each(function(o,i){
					if (i.checked)
					{
						show_regions_tarifs(i.value);
					}
				});
				break;
			case '':
				$('#tr_regions').hide();
				$('#tr_regions input[type=checkbox]').each(function(o,i){i.disabled = true;});
				var regions = $('#all_regions input[type=checkbox]');
				regions.each(function(o,i){
					$('#tarifs_for_'+i.value).hide();
				});
				$('#tr_tarifs').hide();
				$('#tr_tarifs input[type=checkbox]').each(function(o,i){i.disabled = true;});
				break;
		}
	}
	{/literal}
</script>
