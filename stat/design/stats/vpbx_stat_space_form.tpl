<H2>Статистика использования дискового пространства виртуальными АТС</H2>
<H3>Создайте отчёт сами: (или - посмотрите отчёты за <a href="?module=stats&action=report_vpbx_stat_space&vpbx={$vpbx_id}&date_from={$prev_date_from}&date_to={$prev_date_to}">прошлый месяц</a>,
      								за <a href="?module=stats&action=report_vpbx_stat_space&vpbx={$vpbx_id}&date_from={$cur_date_from}&date_to={$cur_date_to}">текущий месяц</a>,
      								за <a href="?module=stats&action=report_vpbx_stat_space&vpbx={$vpbx_id}&date_from={$today}&date_to={$today}">текущий день</a>)</H3>
<FORM action="?" method=get>
	<input type=hidden name=module value=stats>
	<input type=hidden name=action value=report_vpbx_stat_space>
	<TABLE class=mform cellSpacing=4 cellPadding=2 width="100%" border=0>
		<TBODY>
			{if $fixclient}
				<TR>
					<TD class=left>
						<label for="vpbx">Виртуальная АТС:</label>
					</TD>
					<TD>
						<select name="vpbx" id="vpbx">
							<option value="0" {if $vpbx == 0}selected="selected"{/if}>Все</option>
							{foreach from=$vpbxs item="vpbx"}
								<option value="{$vpbx->id}" {if $vpbx_id == $vpbx->id}selected="selected"{/if}>
									ВАТС {$vpbx->id} {if $vpbx->tariff_period_id} (включена) {/if}
								</option>
							{/foreach}
						</select>
					</TD>

				</TR>
			{/if}
			<tr>
				<td class=left>С:</td>
				<td>
					<input class="datepicker-input" type=text class="" name="date_from" value="{$date_from}" id="date_from">
					По:<input class="datepicker-input" type=text name="date_to" value="{$date_to}" id="date_to">
				</td>
			</tr>
		</TBODY>
       </TABLE>
      <HR>

      <DIV align=center><INPUT class=button type=submit value="Сформировать отчёт"></DIV></FORM>
<script>
	optools.DatePickerInit();
</script>

	