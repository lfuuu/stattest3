<H2>Письма клиентам</H2>
<H3>Редактирование письма</H3>
<TABLE cellspacing=5 cellpadding=2 border=0 width=100%><FORM action="?" method=post id=form name=form><TR><TD width=85%>
	<input type=hidden name=module value=mail>
	<input type=hidden name=action value=edit>
	<input type=hidden name=id value='{$template.job_id}'>
	<input type=text name=subject style='width:100%' value='{$template.template_subject}'><br>
	<textarea name=body id=body style='width:100%;height:250px'>{$template.template_body}</textarea><br>
	<DIV align=center ><INPUT id=submit class=button type=submit value="Изменить"></DIV>
				<div style="width:300px">Отправить с ящика: {html_options options=$emails selected=$template.from_email name='from_email'}</div>
</TD><TD valign=top>
<div style="max-height:250px;overflow-y:auto;border:1px solid #ddd;padding:5px;">
<a href='#' onclick='form.body.value+="\n%CLIENT%";return false;'>логин клиента</a><br>
<a href='#' onclick='form.body.value+="\n%CLIENT_NAME%";return false;'>полное название компании</a><br>
<b>Счета:</b>
<div style="margin-left:15px;">
<a href='#' onclick='form.body.value+="\n%ABILL"+prompt("Год-месяц","{$smarty.now|date_format:"%Y-%m"}")+"%";return false;'>за месяц</a><br>
<a href='#' onclick='form.body.value+="\n%UBILL"+prompt("Год-месяц","{$smarty.now|date_format:"%Y-%m"}")+"%";return false;'>полностью неоплаченные (красные)</a><br>
<a href='#' onclick='form.body.value+="\n%PBILL"+prompt("Год-месяц","{$smarty.now|date_format:"%Y-%m"}")+"%";return false;'>оплаченные не полностью (желтые)</a><br>
<a href='#' onclick='form.body.value+="\n%NBILL"+prompt("Год-месяц","{$smarty.now|date_format:"%Y-%m"}")+"%";return false;'>не полностью оплаченные (красные и желтые)</a><br>
<a href='#' onclick='form.body.value+="\n%APDFBILL"+prompt("Год-месяц","{$smarty.now|date_format:"%Y-%m"}")+"%";return false;'>за месяц в PDF</a><br>
<a href='#' onclick='form.body.value+="\n%UPDFBILL"+prompt("Год-месяц","{$smarty.now|date_format:"%Y-%m"}")+"%";return false;'>полностью неоплаченные (красные) в PDF</a><br>
<a href='#' onclick='form.body.value+="\n%PPDFBILL"+prompt("Год-месяц","{$smarty.now|date_format:"%Y-%m"}")+"%";return false;'>оплаченные не полностью (желтые) в PDF</a><br>
<a href='#' onclick='form.body.value+="\n%NPDFBILL"+prompt("Год-месяц","{$smarty.now|date_format:"%Y-%m"}")+"%";return false;'>не полностью оплаченные (красные и желтые) в PDF</a><br>
</div>
<b>Закрывающие документы (с/ф, УПД):</b>
<div style="margin-left:15px;">
<a href='#' onclick='form.body.value+="\n%INVOICE"+prompt("Год-месяц","{$smarty.now|date_format:"%Y-%m"}")+"%";return false;'>по счету</a><br>
<a href='#' onclick='form.body.value+="\n%INVOICE_BY_DATE"+prompt("Год-месяц","{"first day of last month"|strtotime|date_format:"%Y-%m"}")+"%";return false;'>по дате</a><br>
</div>
<a href='#' onclick='form.body.value+="\n%ORDER_TELEKOM%";return false;'>Приказ (Телеком)</a><br>
<a href='#' onclick='form.body.value+="\n%NOTICE_TELEKOM%";return false;'>Уведомление (Телеком)</a><br>
<a href='#' onclick='form.body.value+="\n%DIRECTOR_TELEKOM%";return false;'>Новый директор Надточеева</a><br>
<a href='#' onclick='form.body.value+="\n%DOGOVOR_TELEKOM%";return false;'>Договор</a><br>
<a href='#' onclick='form.body.value+="\n%NOTICE_MCM_TELEKOM%";return false;'>Уведомление о передаче прав (МСН Телеком => МСН Телеком Ретайл)</a><br>
<b>Соглашения о передаче прав:</b>
<div style="margin-left:15px;">
<a href='#' onclick='form.body.value+="\n%SOGL_MCM_TELEKOM%";return false;'>МСН Телеком => МСМ Телеком</a><br>
<a href='#' onclick='form.body.value+="\n%SOGL_MCN_TELEKOM%";return false;'>МСН Телеком => МСН Телеком Ритейл</a><br>
<a href='#' onclick='form.body.value+="\n%SOGL_MCNSERVICE%";return false;'>МСН Телеком Ритейл => МСН Телеком Сервис</a><br>
<a href='#' onclick='form.body.value+="\n%SOGL_MCNTELEKOMTOSERVICE%";return false;'>МСН Телеком => МСН Телеком Сервис</a><br>
<a href='#' onclick='form.body.value+="\n%SOGL_MCNSERVICETOABONSERV%";return false;'>МСН Телеком Сервис => АбонСервис</a><br>
<a href='#' onclick='form.body.value+="\n%SOGL_ABONSERVTOMCNTELEKOM%";return false;'>АбонСервис => МСН Телеком</a><br>
</div>
</div>

			</TD></TR></FORM></TABLE>

{if $template.job_id}
<H3>Отправка письма</H3>
{if $template.job_state=='PM'}
Это - сообщение.<br>
<a href='{$LINK_START}module=mail&action=state&id={$template.job_id}&state=stop'>Сделать письмом</a><br>
{elseif $template.job_state=='stop'}
<a href='{$LINK_START}module=mail&action=state&id={$template.job_id}&state=test'>Тестовая отправка писем</a><br>
<a href='{$LINK_START}module=mail&action=state&id={$template.job_id}&state=ready'>Реальная отправка писем</a><br>
{else}
<a href='{$LINK_START}module=mail&action=state&id={$template.job_id}&state=stop'>Запретить отправку</a><br>
{/if}
<br>
<a onclick="$('#mail_files').toggle();"><img class="icon" alt="Изменить" src="{$IMAGES_PATH}icons/edit.gif"></a> <span>Прикрепить файл</span>
<div id="mail_files" style="{if !$count_files}display: none;{/if}font-size: 10px;">
	<table cellspacing=4 cellpadding=2 border=0>
	<tr><th>имя файла</th><th>&nbsp;</th></tr>
	{foreach from=$files item=item}<tr>
		<td style="font-size: 10px;">
			<a href='{$LINK_START}module=mail&file_id={$item.id}&action=file_get&job_id={$job_id}'>{$item.name}</a> 
		</td>
		<td style="font-size: 10px;">
			<a href='{$LINK_START}module=mail&action=file_del&file_id={$item.id}&job_id={$job_id}' onclick="return confirm('Вы уверены, что хотите удалить файл {$item.name}?')"><img style='margin-left:-2px;margin-top:-3px' class=icon src='{$IMAGES_PATH}icons/delete.gif' alt="Удалить"></a>
		</td>
	</tr>{/foreach}
	<FORM action="?" method=post enctype="multipart/form-data"><tr>
		<input type=hidden name="module" value="mail">
		<input type=hidden name="action" value="file_put">
		<input type=hidden name="job_id" value="{$job_id}">
		<td><input style="font-size: 10px;" type=file name=file></td>
		<td><input style="font-size: 10px;" class=button type=submit value="загрузить"></td>
	</tr></form>
	</table>
</div>
<br>
<a href='{$LINK_START}module=mail&action=client&id={$template.job_id}'>Добавить клиентов</a>

<TABLE class=price cellSpacing=4 cellPadding=2 border=0>
<TBODY>
<TR>
  <TD class=header vAlign=bottom>&nbsp;</TD>
  <TD class=header vAlign=bottom>&nbsp;</TD>
  <TD class=header vAlign=bottom>Клиент</TD>
  <TD class=header vAlign=bottom>Объекты</TD>
  <TD class=header vAlign=bottom>Состояние</TD>
  <TD class=header vAlign=bottom>Дата отправки</TD>
  <TD class=header valign=bottom>Сообщение об ошибке, если есть</TD>
</TR>
{foreach from=$mail_letter item=r name=outer}
<TR class={if $smarty.foreach.outer.iteration%2==0}even{else}odd{/if}{if $r.letter_state=='sent'} style='color:gray'{/if}>
	<TD>{$smarty.foreach.outer.iteration}</TD>
	<TD><a href='{$LINK_START}module=mail&action=preview&id={$template.job_id}&client={$r.client}'>pre</a></TD>
	<TD><a href="/client/view?id={$r.client_id}" target="_blank">{$r.client_id}</a></TD>
	<TD>{if isset($r.objects) && count($r.objects)}
		<table cellspacing=2 cellpadding=1 border=0 class=mform>
		{foreach from=$r.objects item=obj name=inner}
			<tr{if ($obj.view_count)} style="color: #00aa00;"{/if}><td>{$obj.object_type}</td><td>{$obj.object_param}</td><td>{if ($obj.view_count)}<small>{$obj.view_ts|date_full}{else}&nbsp;&nbsp;&nbsp;&nbsp;{/if}</small></td></tr>
		{/foreach}
		</table>
	{/if}</TD>
	<TD>{$r.letter_state}</TD>
	<TD>{if $r.send_date && $r.send_date > '2000-01-01'}{$r.send_date|date_full}{/if}</TD>
	<TD style='font-size:80%'>{$r.send_message}</TD>
</TR>
{/foreach}
</TBODY></TABLE>
{/if}
