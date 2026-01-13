<?php

use app\classes\Utils;
use app\classes\Wordifier;
use app\classes\Html;
use app\helpers\MediaFileHelper;

/** @var $document app\classes\documents\DocumentReport */
/** @var $inline_img bool */

$isCurrentStatement = isset($isCurrentStatement) ? $isCurrentStatement : false;

$hasDiscount = $document->sum_discount > 0;

$currencyWithoutValue = Utils::money('', $document->getCurrency());

$organization = $document->organization;

// $this->bill->c->getOrganization($this->bill->bill_date)->setLanguage($this->getLanguage());
/** @var \app\models\Bill $b */

$b = $document->bill;
$organizationOrig = $organization;
// Для ОТТ и Межоператорки - с 1 ноября - оставляем реквизиты банка по старому
$isNewPayAcc = true;
if (
    \Yii::$app->isRus()
    && in_array($b->clientAccount->clientContractModel->business_id, [\app\models\Business::OTT, \app\models\Business::OPERATOR])
) {
    $organization = $b->clientAccount->getOrganization('2025-10-31')->setLanguage($document->getLanguage());
    $isNewPayAcc = false;
}

// но если поменялась компания - то показывается её
if ($organizationOrig->organization_id != $organization->organization_id) {
    $organization = $organizationOrig;
    $isNewPayAcc = true;
}

$director = $organization->director;
$accountant = $organization->accountant;

$payerCompany = $document->getPayer();

$isOsn = $payerCompany->getTaxRate() != 0;


?>

<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN">
<html>
<head>
    <title><?= $isCurrentStatement ? 'Текущая выписка' : 'Счёт &#8470;' . $document->bill->bill_no; ?></title>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <?php if ($inline_img) : ?>
        <style type="text/css">
            <?= file_get_contents(Yii::$app->basePath .'/web/bill.css') ?>
        </style>
    <?php else : ?>
        <link href="/bill.css" rel="stylesheet"/>
    <?php endif; ?>
</head>

<body bgcolor="#FFFFFF">
<table width="100%">
    <tr>
        <td>
            <?php
            echo !$isCurrentStatement
                ? Yii::$app->view->renderFile($document->getHeaderTemplate() . '.php', [
                    'organization' => $organization,
                    'payer_company' => $payerCompany,
                ])
                : '';
            ?>
        </td>
        <td align="right">
            <div style="width: 110px;text-align: center;padding-right: 10px;">
                <?php if (MediaFileHelper::checkExists('ORGANIZATION_LOGO_DIR', $organization->logo_file_name)): ?>
                    <?php
                    if ($inline_img):
                        echo Html::inlineImg(MediaFileHelper::getFile('ORGANIZATION_LOGO_DIR', $organization->logo_file_name), ['width' => 115, 'border' => 0]);
                    else: ?>
                        <img src="<?= MediaFileHelper::getFile('ORGANIZATION_LOGO_DIR', $organization->logo_file_name); ?>"
                             width="115" border="0"/>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (isset($company['site']) && !empty($company['site'])): ?>
                    <?= $company['site']; ?>
                <?php endif; ?>
            </div>

            <?php if ($document->bill->clientAccount->client != 'salvus'): ?>
                <table border="0" align="right">
                    <tr<?= ($document->sum > 300 ? ' bgcolor="#FFD6D6"' : ''); ?>>
                        <td>Клиент</td>
                        <td><?= $document->bill->clientAccount->client; ?></td>
                    </tr>
                    <tr>
                        <td colspan="2" align="center">
                            <?php
                            if (!$isCurrentStatement) {
                                if ($inline_img) {
                                    echo Html::inlineImg(Yii::$app->request->hostInfo . '/utils/qr-code/get?data=' . $document->getQrCode(), [], 'image/gif');
                                } else {
                                    ?><img src="/utils/qr-code/get?data=<?= $document->getQrCode(); ?>"
                                           border="0"/><?php
                                };
                            } ?>
                        </td>
                    </tr>
                </table>
            <?php endif; ?>
        </td>
    </tr>
</table>
<hr/>

<center><h2><?= $isCurrentStatement ? 'Текущая выписка' : 'Счёт &#8470;' . $document->bill->bill_no; ?><?php

        $time = time() + (3600 * 3); // moscow TZ
        $billNo = $document->bill->bill_no;
        $hourLimit = 14;

        $isCompleted = true;
        if (
            $document->bill->uu_bill_id
            && (date('d', $time) == 1 && date('H', $time) < $hourLimit)
            && strpos($billNo, date('Ym', $time) . '-') === 0
        ) {
            $isCompleted = false;
        };

        if ($isCurrentStatement) {
            $isCompleted = false;
        }
        ?>
        <?php if (!$isCompleted && !$isCurrentStatement): ?>
            <br><b style="color:red; font-size: +140%;">*** Формирование счета ещё не закончено ***</b>
            <br><b style="color:red; ">
                Планируемое время завершения:
                <?= Yii::$app->formatter->asDatetime(date('Y-m-d', $time), 'php:1.m.Y') . ' ' . $hourLimit . ':00 (время московское)' ?></b>
        <?php endif; ?></h2></center>


<p align=right>Дата: <b> <?= Yii::$app->formatter->asDatetime($document->bill->bill_date, 'php:d.m.Y'); ?> г.</b></p>

<hr/>
<br/>
<p>
    <b>Плательщик: <?= ($payerCompany['head_company'] ? $payerCompany['head_company'] . ', ' : '') . $payerCompany->company_full; ?></b>
</p>

<table border="1" width="100%" cellspacing="0" cellpadding="2" style="font-size: 15px;">
    <tbody>
    <tr>
        <td align="center"><b> п/п</b></td>
        <td align="center"><b>Предмет счета</b></td>
        <?php if (!$isCurrentStatement) : ?>
            <td align="center"><b>Количество</b></td>
            <td align="center"><b>Единица измерения</b></td>
            <td align="center"><b>Стоимость,&nbsp;<?= $currencyWithoutValue; ?></b></td>
            <?php if ($isOsn): ?>
                <td align="center"><b>Сумма,&nbsp;<?= $currencyWithoutValue; ?></b></td>
            <?php endif; ?>
            <?php if ($hasDiscount): ?>
                <td align="center"><b>Скидка</b></td>
            <?php endif; ?>


            <?php if ($isOsn): ?>
                <td align="center"><b>Сумма налога, &nbsp;<?= $currencyWithoutValue; ?></b></td>
                <td align="center"><b>Сумма с учётом налога,&nbsp;<?= $currencyWithoutValue; ?></b></td>
            <?php else: ?>
                <td align="center"><b>Сумма,&nbsp;<?= $currencyWithoutValue; ?></b></td>
            <?php endif; ?>
        <?php else: ?>
            <td align="center"><b>Сумма,&nbsp;<?= $currencyWithoutValue; ?></b></td>
        <?php endif; ?>
    </tr>

    <?php foreach ($document->lines as $position => $line): ?>
        <tr>
            <td align="right"><?= ($position + 1); ?></td>
            <td><?= $line['item']; ?></td>
            <?php if (!$isCurrentStatement) : ?>
                <td align="center"><?= Utils::mround($line['amount'], 4, 4); ?></td>
                <td align="center">
                    <?php
                    if (isset($line['okei_name']))
                        echo $line['okei_name'];
                    else {
                        if ($line['type'] == 'service')
                            echo '-';
                        else
                            echo 'шт.';
                    }
                    ?>
                </td>
                <?php if ($isOsn): ?>
                    <td align="center"><?= Utils::round(round($line['sum_without_tax'] / $line['amount'], 2), 2); ?></td>
                    <td align="center"><?= Utils::round($line['sum_without_tax'], 2); ?></td>
                <?php else: ?>
                    <td align="center"><?= Utils::round($line['price'], 2); ?></td>
                <?php endif; ?>


                <?php if ($hasDiscount): ?>
                    <td align="center"><?= Utils::round($line['discount_auto'] + $line['discount_set'], 2); ?></td>
                <?php endif; ?>

                <?php if ($isOsn): ?>
                    <td align="center"><?= ($document->bill->clientAccount->getTaxRate() ? Utils::round($line['sum_tax'], 2) : 'без НДС'); ?></td>
                    <td align="center"><?= Utils::round($line['sum'], 2); ?></td>
                <?php else: ?>
                    <td align="center"><?= Utils::round($line['sum'], 2); ?></td>
                <?php endif; ?>
            <?php else: ?>
                <td align="center"><?= Utils::round($line['sum'], 2); ?></td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>

    <tr>

        <td colspan="<?= $isCurrentStatement ? '2' : ($hasDiscount ? '6' : '5') ?>" align="right">
            <div style="padding-top: 3px; height: 15px;">
                <b>Итого:</b>
            </div>
        </td>

        <?php if (!$isCurrentStatement) : ?>
            <?php if ($isOsn): ?>
                <td align="center"><?= Utils::round($document->sum_without_tax, 2); ?></td>
                <td align="center"><?= Utils::round($document->sum_with_tax, 2); ?></td>
                <td align="center"><?= Utils::round($document->sum, 2); ?></td>
            <?php else: ?>
                <td align="center"><?= Utils::round($document->sum, 2); ?></td>
            <?php endif; ?>
        <?php else: ?>
            <td align="center"><?= Utils::round($document->sum, 2); ?></td>
        <?php endif; ?>
    </tr>

    <?php if (!$isCurrentStatement && !$isOsn): ?>
        <tr>
            <td colspan="<?= $hasDiscount ? '6' : '5' ?>" align="right">
                <div style="padding-top: 3px; height: 15px;">
                    <b>Без налога (НДС)*</b>
                </div>
            </td>
            <td align="center">-</td>
        </tr>
        <tr>
            <td colspan="<?= $hasDiscount ? '6' : '5' ?>" align="right">
                <div style="padding-top: 3px; height: 15px;">
                    <b>Всего к оплате:</b>
                </div>
            </td>
            <td align="center"><?= Utils::round($document->sum, 2); ?></td>
        </tr>
    <?php endif; ?>

    </tbody>
</table>
<br/>

<p>
    <i>
        Сумма прописью: <?= Wordifier::Make($document->sum, $document->getCurrency()); ?>
    </i>
</p>

<?php if (false && !$isOsn): ?>
    <p align="center">
        <b>
            *НДС не облагается: Упрощенная система налогообложения, ст. 346.11 НК РФ.
        </b>
    </p>
<?php endif; ?>

<?php if ($isCompleted) : ?>
    <table border="0" align=center cellspacing="1" cellpadding="0" style="padding-left: 100px; width: 600px;">
        <tbody>
        <tr>
            <td nowrap><?= $director->post_nominative; ?></td>
            <?php if ($document->sendEmail): ?>
                <td>
                    <?php if (MediaFileHelper::checkExists('SIGNATURE_DIR', $director->signature_file_name)):
                        $image_options = [
                            'width' => 140,
                            'border' => 0,
                            'align' => 'top',
                        ];

                        if ($inline_img):
                            echo Html::inlineImg(MediaFileHelper::getFile('SIGNATURE_DIR', $director->signature_file_name), $image_options);
                        else:
                            array_walk($image_options, function (&$item, $key) {
                                $item = $key . '="' . $item . '"';
                            });
                            ?>
                            <img src="<?= MediaFileHelper::getFile('SIGNATURE_DIR', $director->signature_file_name); ?>"<?= implode(' ', $image_options); ?> />
                        <?php endif; ?>
                    <?php else: ?>
                        _________________________________
                    <?php endif; ?>
                </td>
            <?php else: ?>
                <td>
                    <br/><br/>_________________________________<br/><br/>
                </td>
            <?php endif; ?>
            <td>/ <?= $director->name_nominative; ?> /</td>
        </tr>
        <tr>
            <td nowrap>Главный бухгалтер</td>
            <?php if ($document->sendEmail) : ?>
                <td>
                    <?php if (MediaFileHelper::checkExists('SIGNATURE_DIR', $accountant->signature_file_name)):
                        $image_options = [
                            'width' => 140,
                            'border' => 0,
                            'align' => 'top',
                        ];

                        if ($inline_img):
                            echo Html::inlineImg(MediaFileHelper::getFile('SIGNATURE_DIR', $accountant->signature_file_name), $image_options);
                        else:
                            array_walk($image_options, function (&$item, $key) {
                                $item = $key . '="' . $item . '"';
                            });
                            ?>
                            <img src="<?= MediaFileHelper::getFile('SIGNATURE_DIR', $accountant->signature_file_name); ?>"<?= implode(' ', $image_options); ?> />
                        <?php endif; ?>
                    <?php else: ?>
                        _________________________________
                    <?php endif; ?>
                </td>
            <?php else: ?>
                <td>
                    <br/><br/>_________________________________<br/><br/>
                </td>
            <?php endif; ?>
            <td>
                / <?= $accountant->name_nominative; ?> /
                <?php if ($document->sendEmail): ?>
                    <?php if (MediaFileHelper::checkExists('STAMP_DIR', $organization->stamp_file_name)):
                        $image_options = [
                            'width' => 200,
                            'border' => 0,
                            'style' => 'position:relative; left:-165; top:10px; z-index:-10; margin-bottom:-170px;',
                        ];

                        if ($inline_img):
                            echo Html::inlineImg(MediaFileHelper::getFile('STAMP_DIR', $organization->stamp_file_name), $image_options);
                        else:
                            array_walk($image_options, function (&$item, $key) {
                                $item = $key . '="' . $item . '"';
                            });
                            ?>
                            <img src="<?= MediaFileHelper::getFile('STAMP_DIR', $organization->stamp_file_name); ?>"<?= implode(' ', $image_options); ?> />
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
        </tbody>
    </table>

    <?php if (!in_array($document->bill->clientAccount->firma, ['ooocmc', 'ooomcn', 'all4net', 'ab.service_marcomnet'])): ?>
        <small>
            Примечание:
            При отсутствии оплаты счета до конца текущего месяца услуги по договору будут приостановлены до полного
            погашения задолженности.
        </small>
    <?php endif; ?>

    <?php if ($isNewPayAcc && $document->bill->clientAccount->firma == 'mcn_telekom' && $document->bill->bill_date >= '2025-11-01' && $document->bill->bill_date < '2026-01-01'): ?>
        <div style="text-align: center; padding-top: 50px; font-size: 9pt; color: #c40000;">
            Обратите внимание, с 01.11.2025г. у нас новые платёжные реквизиты!<br>
            Для того чтобы платежи были зачислены своевременно, просьба осуществлять оплату на реквизиты ООО "МСН Телеком" в АО "Т-Банк"!
        </div>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>
