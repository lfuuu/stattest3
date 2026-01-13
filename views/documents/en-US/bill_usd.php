<?php

/** @var DocumentReport $document */

use app\classes\documents\DocumentReport;
use app\classes\Html;
use app\helpers\MediaFileHelper;
use app\models\Currency;
use app\models\Language;

$organization = $document->organization;
$bill = $document->bill;
$organizationSwift = $organization->getSettlementAccount(\app\models\OrganizationSettlementAccount::SETTLEMENT_ACCOUNT_TYPE_SWIFT);
$organizationIban = $organization->getSettlementAccount(\app\models\OrganizationSettlementAccount::SETTLEMENT_ACCOUNT_TYPE_IBAN);
$contragent = $bill->clientAccount->contragent;

!isset($currency) && $currency = $bill->currency;

$hDate = function ($dateStr) {
    return (new DateTime($dateStr))->format('d.m.Y');
};

$isOperatorBill = $document->getDocType() == DocumentReport::DOC_TYPE_BILL_OPERATOR;

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN">

<head>
    <title><?= $isCurrentStatement ? \Yii::t('biller', 'Current statement', [], $document->getLanguage()) : 'Invoice No' . $document->bill->bill_no; ?></title>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <?php if ($inline_img) : ?>
        <style type="text/css">
            <?= file_get_contents(Yii::$app->basePath .'/web/css/documents/bill_usd.css') ?>
        </style>
    <?php else : ?>
        <link href="/css/documents/bill_usd.css" rel="stylesheet"/>
    <?php endif; ?>
</head>

<body bgcolor="#FFFFFF" style="background:#FFFFFF">
<table width="100%">
    <tr>
        <td width="50%">
            <?php if (!$isCurrentStatement): ?>
                <table>
                    <tr>
                        <td>Ship To:</td>
                        <td><?= $organization->setLanguage(Language::LANGUAGE_ENGLISH)->name ?></td>
                    </tr>
                    <tr>
                        <td colspan="2"><?= $organization->legal_address ?></td>
                    </tr>
                    <tr>
                        <td>Beneficiary Account Number:</td>
                        <td><?php
                            $ibanProperties = $organizationIban->getProperties()->asArray()->all();

                            $data = [];

                            foreach ([Currency::EUR, Currency::USD] as $_currency) {
                                if (
                                    isset($ibanProperties['bank_account_' . $_currency])
                                    && isset($ibanProperties['bank_account_' . $_currency]['value'])
                                    && trim($ibanProperties['bank_account_' . $_currency]['value'])
                                ) {
                                    $data[] = $ibanProperties['bank_account_' . $_currency]['value'] . ' (' . $_currency . ')';
                                }
                            }

                            echo implode("</br>", $data);

                            ?></td>
                    </tr>
                    <tr>
                        <td>SWIFT:</td>
                        <td><?= $organizationSwift->bank_bik ?></td>
                    </tr>
                    <tr>
                        <td>VAT Number:</td>
                        <td><?= $organization->tax_registration_id ?></td>
                    </tr>
                    <tr>
                        <td>Beneficiary’s Bank:</td>
                        <td><?= $organizationSwift->bank_name ?></td>
                    </tr>

                    <tr>
                        <td>Beneficiary’s Bank Address::</td>
                        <td><?= $organizationSwift->bank_address ?></td>
                    </tr>

                </table>
            <?php endif; ?>
        </td>
        <td align="right" width="50%">
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
                        <td>Client</td>
                        <td><?= $document->bill->clientAccount->id; ?></td>
                    </tr>
                    <tr>
                        <td colspan="2" align="center">
                            <?php
                            if (!$isCurrentStatement):
                                if ($inline_img):
                                    echo Html::inlineImg(Yii::$app->request->hostInfo . '/utils/qr-code/get?data=' . $document->getQrCode(), [], 'image/gif');
                                else: ?>
                                    <img src="/utils/qr-code/get?data=<?= $document->getQrCode(); ?>" border="0"/>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            <?php endif; ?>
        </td>
    </tr>
</table>

<h1><?= $isCurrentStatement ? \Yii::t('biller', 'Current statement', [], $document->getLanguage()) : 'Invoice No' . $bill->bill_no ?></h1>
<h2>Date: <?= $hDate($bill->bill_date) ?></h2>
<?php if (!$isCurrentStatement): ?>
    <h2>Invoice Time Zone: +00:00</h2>
<?php endif; ?>
<br>
<br>
<br>
<b>Bill To:</b> <?= $contragent->name_full ?></br>
Address: <?= $contragent->address_jur ?></br>
VAT Number: <?= $contragent->inn_euro ?>
<br>
<br>
<br>

<table width="100%" class="lines_table">
    <thead>
    <th>No</th>
    <th>Description</th>
    <?php if (!$isOperatorBill) { ?>
        <th>Volume, min</th><?php } ?>
    <th>Amount, <?= $currency ?></th>
    <?php if (!$isCurrentStatement): ?>
        <th>VAT rate, %</th>
        <th>VAT amount, <?= $currency ?></th>
    <?php endif; ?>
    <th>Amount inc. VAT, <?= $currency ?></th>
    </thead>
    <tbody>
    <?php
    $total = ['amount' => 0, 'sum' => 0];
    foreach ($document->lines as $idx => $line) : ?>
        <tr>
            <td align="center"><?= ($idx + 1); ?></td>
            <td align="center"><?= $line['item'] ?></td>
            <?php if (!$isOperatorBill) { ?>
                <td align="center"><?= $line['amount'] ?></td><?php } ?>
            <td align="center"><?= $line['price'] ?></td>
            <?php if (!$isCurrentStatement): ?>
                <td align="center"><?= $line['tax_rate'] ?></td>
                <td align="center"><?= $line['sum_tax'] ?></td>
            <?php endif; ?>
            <td align="center"><?= $line['sum'] ?></td>
        </tr>
        <?php

        $total['amount'] += $line['sum'] - $line['sum_tax'];
        $total['tax'] += $line['sum_tax'];
        $total['sum'] += $line['sum'];
    endforeach; ?>
    <tr>
        <td colspan="<?= $isOperatorBill ? 2 : 3 ?>" align="right"><b>Total Amount Due:</b></td>
        <td align="center"><?= number_format($total['amount'], 2, '.', '') ?></td>
        <?php if (!$isCurrentStatement): ?>
            <td align="center">&nbsp;</td>
            <td align="center"><?= number_format($total['tax'], 2, '.', '') ?></td>
        <?php endif; ?>
        <td align="center"><?= number_format($total['sum'], 2, '.', '') ?></td>
    </tr>
    </tbody>
</table>
<?php if (!$isCurrentStatement) : ?>
    <br>
    <br>
    <br>
    <br>

    <?php $director = $organization->director->setLanguage(Language::LANGUAGE_ENGLISH); ?>


    <p>Managing <?= $director->post_nominative ?>: <?= $director->name_nominative ?></p>
    <br>
    <br>
    <table border="0">
        <tr>
            <td valign="bottom">Signature</td>
            <?php if ($document->sendEmail) : ?>
                <td>
                    <?php if (MediaFileHelper::checkExists('SIGNATURE_DIR', $director->signature_file_name)):
                        $image_options = [
                            'width' => 140,
                            'border' => 0,
                            'align' => 'top',
                            'style' => 'position: relative; left: -160px; top: -40px;'
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
                        <div style="float: left">_________________________________</div>
                    <?php endif; ?>
                </td>
            <?php else: ?>
                <td>
                    _________________________________
                </td>
            <?php endif; ?>
        </tr>
    </table>

    <?php if ($contragent->tax_registration_reason): ?>
        <div align="right">
            <?= $contragent->tax_registration_reason ?>
        </div>
    <?php endif ?>

    <?php if ($document->sendEmail && MediaFileHelper::checkExists('STAMP_DIR', $organization->stamp_file_name)):
        $image_options = [
            'width' => 200,
            'border' => 0,
            'style' => 'position:relative; left:160; top:-200; z-index:-10; ',
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

</body>
</html>