<?php

/** @var \app\models\filter\SaleBookFilter $filter */

?>
<h2>Реестр</h2>
<table class="price" cellspacing="4" cellpadding="2" border="1"
       style="border-collapse: collapse; font: normal 8pt sans-serif; padding: 2px 2px 2px 2px;">

    <thead>
    <tr>
        <td rowspan="2" class="s" align="center">Код&#160;операции</td>
        <td colspan="1" class="s" align="center">В&#160;том&#160;числе </br> </br> </br> </br> </br></td>
        <td rowspan="2" class="s" align="center">
            Общая стоимость</br>
            реализованных</br>
            (переданных)&#160;товаров</br>
            (работ, услуг) по   </br>
            видам</br>
            освобождаемых от</br>
            налогооблажения&#160;</br>операций,</br>
            отраженных в </br> налоговой </br>  декларации по налогу </br>  на добавленную </br>  стоимость, руб.
        </td>
        <td rowspan="2" class="s" align="center">
            Наименование (ФИО)
            контрагента (покупателя)
        </td>
        <td rowspan="2" class="s" align="center">ИНН</td>
        <td rowspan="2" class="s" align="center">КПП</td>
        <td colspan="4" class="s" align="center">Документы,&#160;подтверждающие&#160;право&#160;налогплательщика&#160;на&#160;налоговые&#160;льготы</td>
    </tr>
    <td class="s" align="center">вид&#160;операции,&#160;по&#160;которой</br> применена налоговая льгота</td>
    <td class="s" align="center">Вид&#160;документа</td>
    <td class="s" align="center">№</td>
    <td class="s" align="center">Дата</td>
    <td class="s" align="center">
        Общая&#160;стоимость&#160;реализованных </br>
        (переданных)&#160;товаров&#160;(работ,&#160;услуг) </br>
        по&#160;контрагенту </br>
        или&#160;в&#160;случае&#160;наличия&#160;типового </br>
        договора&#160;по&#160;нескольким </br>
        контрагентам,&#160;руб.
    </td>
    </thead>
    <tbody>
    <?php

    use app\helpers\DateTimeZoneHelper;
    use app\models\ClientContragent;
    use app\models\Invoice;
    use app\modules\uu\models\ServiceType;
    use app\helpers\SaleBookHelper;

    $query = $filter->search();

    $idx = 1;

    $total = ['sumAll' => 0, 'sumCol16' => 0];

    if ($query)
        foreach ($query->each() as $invoice) : ?>
            <?php /** @var \app\models\filter\SaleBookFilter $invoice */

//            if (!$filter->check($invoice)) {
//                continue;
//            }

            if (!$invoice->bill || !$invoice->bill->clientAccountModel) {
                Yii::$app->session->addFlash('error', 'С/ф без счета: ' . $invoice->number);
                continue;
            }
            try {
                $account = $invoice->bill->clientAccountModel;
                $contract = $account->contract;
                $contragent = $contract->contragent;

                $sum = $invoice->sum;
                $sum_without_tax = $invoice->type_id != Invoice::TYPE_PREPAID ? $invoice->sum_without_tax : null;
                $sum_tax = $invoice->sum_tax;

                $linesSum16 = 0;
                foreach ($invoice->lines as $line) {

                    if (!SaleBookHelper::isTelephonyService($line, $filter)) {
                        continue;
                    }
                    $linesSum16 += abs($line['sum_tax']) > 0 ? 0 : $line['sum'];
                }

                if (abs($linesSum16) < 0.001) {
                    continue;
                }

            } catch (Exception $e) {
                Yii::$app->session->addFlash('error', $e->getMessage());
                continue;
            }

            $total['sumAll'] += $sum;
            $total['sumCol16'] += $linesSum16 ?: 0;

            $contract = $invoice->bill->clientAccount->contract;
            $contractDate = isset($contract->document->contract_date) ? $contract->document->contract_date : $contract->offer_date;
            $contractDate = $contractDate ?: '2021-09-01';

            if (strtotime($contractDate) < strtotime('2021-09-01')) {
                $contractDate = '2021-09-01';
            }

            ?>
            <tr class="<?= ($idx % 2 == 0 ? 'odd' : 'even') ?>">
                <td rowspan="2"></td>
                <td rowspan="2"></td>
                <td rowspan="2"></td>
                <td rowspan="2"><?= trim($contragent->name_full) ?></td>
                <td rowspan="2"><?= trim($contragent->inn) ?></td>
                <td rowspan="2"><?= $contragent->legal_type == ClientContragent::LEGAL_TYPE ? (trim($contragent->kpp) ?: '') : '' ?></td>
                <td colspan="1">Лицензионное соглашение</td>
                <td colspan="1"><?= $contract->number ?></td>
                <td colspan="1"><?= date('d.m.Y', strtotime($contractDate)) ?></td>
                <td rowspan="2" align="right"><?= $printSum($linesSum16) ?></td>
            </tr>
            <td>Акт</td>
            <td><?= $invoice->number ?></td>
            <td><?= $invoice->getDateImmutable()->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED) ?></td>
        <?php
        endforeach;
    ?>
    <tr class="even">
        <td>Всего по </br> коду:</td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td align="right"><?= $printSum($total['sumCol16']) ?></td>
    </tr>
    </tbody>
</table>
