<br />
<TABLE class=price cellSpacing=4 cellPadding=2 width="100%" border=0>
<tr>
    <td style="text-align: center;" class="header" vAlign=bottom rowspan="2">АТС</td>
    {if !$fixclient}
        <td style="text-align: center;" class="header" vAlign=bottom rowspan="2">Клиент</td>
    {/if}
    <td style="text-align: center;" class="header" vAlign=bottom rowspan="2">Работатет с</td>
    <td style="text-align: center; border-right: 1px solid #D0D0D0;" class="header" vAlign=bottom rowspan="2">Тариф</td>
    <td style="text-align: center; border-right: 1px solid #D0D0D0;" class="header" vAlign=bottom colspan="3">Максимум за период</td>
    <td style="text-align: center; border-right: 1px solid #D0D0D0;" class="header" vAlign=bottom colspan="3">Минимум за период</td>
    <td style="text-align: center;" class="header" vAlign=bottom colspan="3">Среднее за период</td>
</tr>
<tr>
    <td style="text-align: center;" class="header" vAlign=bottom>Дисковое<br/>пространство</td>
    <td style="text-align: center;" class="header" vAlign=bottom>Внутренние<br/>номера</td>
    <td style="text-align: center; border-right: 1px solid #D0D0D0;" class="header" vAlign=bottom>Номера стороннего<br />оператора</td>

    <td style="text-align: center;" class="header" vAlign=bottom>Дисковое<br/>пространство</td>
    <td style="text-align: center;" class="header" vAlign=bottom>Внутренние<br/>номера</td>
    <td style="text-align: center; border-right: 1px solid #D0D0D0;" class="header" vAlign=bottom>Номера стороннего<br />оператора</td>

    <td style="text-align: center;" class="header" vAlign=bottom>Дисковое<br/>пространство</td>
    <td style="text-align: center;" class="header" vAlign=bottom>Внутренние<br/>номера</td>
    <td style="text-align: center;" class="header" vAlign=bottom>Номера стороннего<br />оператора</td>
</tr>
{if $stats}
    {foreach from=$stats item="s" name=outer key="k"}
        <tr class={if $smarty.foreach.outer.iteration%2==0}even{else}odd{/if}>
            <td style="text-align: center;">
                <a href="?module=stats&action=report_vpbx_stat_space&usage_id={$s->usage_id}&date_from={$date_from}&date_to={$date_to}">АТС {$s->usage_id}</a>
            </td>
            {if !$fixclient}
                <td style="text-align: center;">
                    <a href="/client/view?id={$s->client}">{$s->client}</a>
                </td>
            {/if}
            <td style="text-align: center;">{$s->actual|mdate:"j месяца Y"}</td>
            <td style="text-align: center;">{$s->tarif}</td>

            <td style="text-align: center;">{$s->max|bytesize:"b"}</td>
            <td style="text-align: center;">{$s->max_number}</td>
            <td style="text-align: center; border-right: 1px solid #D0D0D0;">{$s->max_ext_did_count}</td>

            <td style="text-align: center;">{$s->min|bytesize:"b"}</td>
            <td style="text-align: center;">{$s->min_number}</td>
            <td style="text-align: center; border-right: 1px solid #D0D0D0;">{$s->min_ext_did_count}</td>

            <td style="text-align: center;">{$s->avg|bytesize:"b"}</td>
            <td style="text-align: center;">{$s->avg_number|number_format:"2":",":" "}</td>
            <td style="text-align: center;">{$s->avg_ext_did_count|number_format:"2":",":" "}</td>
        </tr>
    {/foreach}
{else}
	<tr><td colspan="7" style="text-align: center;">Нет информации</td></tr>
{/if}
</table>
{if isset($stat_detailed.0)}
    {assign var="details" value=$stat_detailed.0}
{else}
    {assign var="details" value=''}
{/if}
{if isset($stat_detailed.1)}
    {assign var="totals" value=$stat_detailed.1}
{else}
    {assign var="totals" value=''}
{/if}
{if $stat_detailed}
    <hr />
	<TABLE class=price cellSpacing=4 cellPadding=2 width="100%" border=0>
		<tr>
			<td style="text-align: center;" class="header" vAlign=bottom rowspan="2">Дата</td>
			<td style="text-align: center;" class="header" vAlign=bottom colspan="2">Показатель</td>
			<td style="text-align: center;" class="header" vAlign=bottom colspan="2">Изменение за день</td>
			<td style="text-align: center;" class="header" vAlign=bottom colspan="3">Сумма за день</td>
		</tr>
		<tr>
			<td style="text-align: center;" class="header" vAlign=bottom>Дисковое<br/>пространство</td>
			<td style="text-align: center;" class="header" vAlign=bottom>Внутренние<br/>номера</td>
			<td style="text-align: center;" class="header" vAlign=bottom>Номера стороннего<br />оператора</td>

			<td style="text-align: center;" class="header" vAlign=bottom>Дисковое<br/>пространство</td>
			<td style="text-align: center;" class="header" vAlign=bottom>Внутренние<br/>номера</td>
			<td style="text-align: center;" class="header" vAlign=bottom>Номера стороннего<br />оператора</td>

			<td style="text-align: center;" class="header" vAlign=bottom>Дисковое<br/>пространство</td>
			<td style="text-align: center;" class="header" vAlign=bottom>Внутренние<br/>номера</td>
			<td style="text-align: center;" class="header" vAlign=bottom>Номера стороннего<br />оператора</td>

			<td style="text-align: center;" class="header" vAlign=bottom>Итого</td>
		</tr>
		{foreach from=$stat_detailed.0 item="data" name=outer}
			<tr class={if $smarty.foreach.outer.iteration%2==0}even{else}odd{/if}>
				<td style="text-align: center;">{$data->mdate|mdate:"j месяца Y"}</td>

				<td style="text-align: center;">{$data->use_space|bytesize:"b"}</td>
				<td style="text-align: center;">{$data->numbers}</td>
				<td style="text-align: center;">{$data->ext_did_count}</td>

				<td style="text-align: center;color: {if $data->diff > 0}#000033{elseif $data->diff < 0}#663300{else}#C0C0C0{/if};">{if $data->diff > 0}+{/if}{$data->diff|bytesize:"b"}</td>
				<td style="text-align: center;color: {if $data->diff_number > 0}#000033{elseif $data->diff_number < 0}#663300{else}#C0C0C0{/if}">{if $data->diff_number > 0}+{/if}{$data->diff_number}</td>
				<td style="text-align: center;color: {if $data->diff_number > 0}#000033{elseif $data->diff_number < 0}#663300{else}#C0C0C0{/if}">{if $data->diff_ext_dids > 0}+{/if}{$data->diff_ext_dids}</td>

				<td style="text-align: right;">
						{$data->sum_space|num_format:true:2}{if $data->sum_space}<sup><small>за {$data->for_space|bytesize:"Gb"}</small></sup>{/if}
				</td>
				<td style="text-align: right;">
					{$data->sum_number|num_format:true:2}{if $data->sum_number}<sup><small>за {$data->for_number} порт(ов)</small></sup>{/if}
				</td>
				<td style="text-align: right;">
					{$data->sum_ext_dids|num_format:true:2}{if $data->sum_ext_dids}<sup><small>за {$data->for_ext_did_count}</small></sup>{/if}
				</td>

				<td style="text-align: right;">
					{$data->sum|num_format:true:2}
				</td>
			</tr>
		{/foreach}
		<tr>
			<td colspan=6></td>
			<td>
				<b>Итого</b>
			</td>
			<td style="text-align: right;">
				{$totals.sum_space|num_format:true:2}
			</td>
			<td style="text-align: right;">
				{$totals.sum_number|num_format:true:2}
			</td>
			<td style="text-align: right;">
				{$totals.sum|num_format:true:2}
			</td>
		</tr>
	</table>
{/if}