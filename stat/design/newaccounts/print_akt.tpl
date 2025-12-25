<html>
    <head>
        <link title=default href="{if $is_pdf == '1'}{$WEB_PATH}{else}{$PATH_TO_ROOT}{/if}invoice.css" type=text/css rel=stylesheet />
        <title>Акт &#8470;{if !$inv_number}{$bill.bill_no}{$inv_no}{else}{$inv_number}{/if} от {$inv_date|mdate:"d.m.Y г."}</title>
        <meta http-equiv=Content-Type content="text/html; charset=utf-8" />
    </head>

    <body bgcolor="#FFFFFF" text="#000000">
        <table width=95%>
            <tr>
                <td>
                    {if $bill_client.firma == 'mcn_telekom'}
                        {if !$inv_number}{$bill.bill_no}{$inv_no}{else}{$inv_number}{/if}
                    {/if}
                    <br /><br />
                    {if $to_client == "true" && ($bill_client.firma == 'mcn' || $bill_client.firma == 'mcn_telekom' || $bill_client.firma == 'mcn_telekom_ser')}
                        <b>Обращаем Ваше Внимание!</b> Этот экземпляр Акта, просьба с подписью и печатью направить в наш адрес: {$organization.post_address}, {$organization.name}<br /><br />
                    {/if}

                    Продавец: <strong>{$organization.name}</strong><br />
                    Адрес: <strong>{$organization.legal_address}</strong><br />
                    ИНН/КПП продавца: <strong>{$organization.tax_registration_id}&nbsp;/&nbsp;{$organization.tax_registration_reason}</strong><br />

                    {if $organization.id == 7} {*all4geo*}
                        <br />
                    {else if isset($organization.contact_phone)}
                        Телефон: <strong>{$organization.contact_phone}</strong><br />
                    {/if}
                    <br />

                    Заказчик: <strong style="font-size: 10pt;">{if $bill_client.head_company}{$bill_client.head_company}, {/if}{$bill_client.company_full}</strong><br />
                    Адрес: <strong>{$bill_client.address}</strong><br />
                    ИНН/КПП покупателя: <strong>{$bill_client.inn} / {$bill_client.kpp}</strong><br />
                </td>
                {if $bill_no_qr}
                    <td align="right"><br><img src="{if $is_pdf == '1'}{$bill_no_qr_img.akt[$source]}{else}/utils/qr-code/get?data={$bill_no_qr.akt[$source]}{/if}"></td>
                {/if}
            </tr>
        </table>
        <br />

        <div align="center">
            <h2>
                {if !$is_document_ready}<b style="color:red;">***ДОКУМЕНТ ДЛЯ ВНУТРЕННЕГО ИСПОЛЬЗОВАНИЯ***</b><br>{/if}
                Акт &#8470;{if !$inv_number}{$bill.bill_no}{$inv_no}{else}{$inv_number}{/if}{if $invoice.invoice_date} от {$invoice.invoice_date|mdate:"d.m.Y г."}{else}{if !$without_date_date} от {$inv_date|mdate:"d.m.Y г."}{else} от {$without_date_date|mdate:"d.m.Y г."}{/if}{/if}
                {if $correction_info}<br>Исправление №{$correction_info.number} от {$correction_info.date_timestamp|mdate:"d.m.Y г."}{/if}
            </h2>

            <div style="padding: 20px;"></div>

            <table border="1" cellpadding="3" cellspacing="0" width="100%">
                <tr>
                    <th>NN<br>п/п</th>
                    <th>Наименование работы (услуги)</th>
                    <th>Ед.<br>изм.</th>
                    <th>Коли-<br>чество</th>
                    <th>Цена</th>
                    <th>Сумма</th>
                </tr>
                {foreach from=$bill_lines item=line key=key}
                    <tr>
                        <td align="center">{$key+1}</td>
                        <td>{$line.item}</td>
                        <td align="center">
                            {*if $inv_is_new4 && $line.type == "service"}-{else}ЫФ.{/if*}

                            {if $inv_is_new4}
                                {if isset($line.okvd_code) && $line.okvd_code}
                                    {$line.okvd_code|okei_name}
                                {else}
                                    {if $line.type == "service"}
                                        -
                                    {else}
                                        шт.
                                    {/if}
                                {/if}
                            {else}
                               шт.
                            {/if}

                            {*if $line.okvd_code}{$line.okvd}{else}{if $line.type == "service"}-{else}шт.{/if}{/if*}
                        </td>
                        <td align="center">{$line.amount|round:4}</td>
                        <td align="center">{if isset($line.outprice)}{$line.outprice|round:4}{else}{$line.price|round:4}{/if}</td>
                        <td align="center">{$line.sum_without_tax}</td>
                    </tr>
                {/foreach}
                <tr>
                    <td colspan=5 align="right"><b>Итого:</b></td>
                    <td align="right">{$bill.sum_without_tax|round:2}</td>
                </tr>

                {if $bill.sum != $bill.sum_without_tax}
                    <tr>
                        <td colspan="5" align="right"><b>Итого НДС:</b></td>
                        <td align="right">
                            {$bill.sum_tax|round:2}
                        </td>
                    </tr>
                    <tr>
                        <td colspan="5" align="right"><b>Всего (с учетом НДС):</b></td>
                        <td align="right">{$bill.sum|round:2}</td>
                    </tr>
                {/if}
            </table>
        </div>

        <br />
        Всего оказано услуг на сумму: {$bill.sum|wordify:'RUB'}
        {if $bill.sum_tax > 0}
            <br />
            В т.ч. НДС: {$bill.sum_tax|round:2|wordify:'RUB'}
        {/if}
        <br />
        <br />
        Вышеперечисленные услуги выполнены полностью и в срок. Заказчик претензий по объему, качеству и срокам оказания услуг не имеет.
        <br />
        <br />
        {if false && $organization.is_simple_tax_system}*НДС не облагается: Упрощенная система налогообложения, ст. 346.11 НК РФ.{/if}
        <br />
        <br />

        <div style="position:relative; top:{if isset($emailed) && $emailed==1}0{else}0{/if}px; z-index:10">
            <table border="0" cellpadding="0" cellspacing="5">
                <tr>
                    <td><p>Исполнитель</td>
                    <td>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</td>
                    <td><p>Заказчик</td>
                </tr>
                <tr>
                    <td>
                        <br /><br />
                        <table border=0>
                            <tr>
                                <td>Руководитель организации</td>
                                <td>
                                    {if isset($firm_director.sign) && $firm_director.sign && isset($emailed) && $emailed==1}
                                        <img
                                            src="{if $is_pdf == '1'}{$WEB_PATH}images/{else}{$IMAGES_PATH}{/if}{$firm_director.sign.src}"
                                            border="0"
                                            alt=""
                                            align="top"
                                            {if $firm_director.sign.width} width="{$firm_director.sign.width}" height="{$firm_director.sign.height}"{/if}
                                        />
                                    {else}
                                        _______________
                                    {/if}
                                </td>
                                <td nowrap>
                                    / {$firm_director.name} /
                                </td>
                            </tr>
                        </table>
                        <br /><br />
                    </td>
                    <td>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</td>
                    <td nowrap>
                        {$bill_client.signer_position}________________________/{$bill_client.signer_name|replace:" ":"&nbsp;"}/
                    </td>
                </tr>
                <tr>
                    <td align="center"><small>(подпись)</small></td>
                    <td></td>
                    <td align="center"><small>(подпись)</small></td>
                </tr>
                <tr>
                    <td align="center"><br /><br />М.П.</td>
                    <td></td>
                    <td align="center"><br /><br />М.П.</td>
                </tr>
            </table>
        </div>

        {if isset($emailed) && $emailed==1}
            <div style="position: relative; top: -160px; left: 130px;">
                {if $firma && isset($firma.src) && $firma.src}
                    <img style="{$firma.style}" src="{if $is_pdf == '1'}{$WEB_PATH}images/{else}{$IMAGES_PATH}{/if}{$firma.src}"{if $firma.width} width="{$firma.width}" height="{$firma.height}"{/if}>
                {/if}
            </div>
        {/if}

    </body>
</html>