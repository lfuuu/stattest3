<?php
/** @var PartnerRewardsNewFilter $filterModel */
/** @var array $documentData */

use app\models\filter\PartnerRewardsNewFilter;

$documentData = $documentData ?? $filterModel->getDocumentData();
$summary = $documentData['summary'];
?>
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<style>
    body {
        font-family: "Times New Roman", serif;
        font-size: 12px;
        color: #000;
    }
    .partner-reward-document {
        width: 100%;
    }
    .partner-reward-document__meta {
        margin-bottom: 18px;
        font-size: 13px;
    }
    .partner-reward-document__meta table {
        width: 100%;
        border-collapse: collapse;
    }
    .partner-reward-document__meta td {
        padding: 0 0 6px 0;
        vertical-align: top;
    }
    .partner-reward-document__table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }
    .partner-reward-document__table th,
    .partner-reward-document__table td {
        border: 1px solid #000;
        padding: 6px 8px;
    }
    .partner-reward-document__table th {
        background: #d9eaf7;
        font-weight: 700;
        text-align: center;
    }
    .partner-reward-document__summary td {
        background: #f5f0da;
        font-weight: 700;
        text-align: center;
    }
    .partner-reward-document__number {
        text-align: right;
        white-space: nowrap;
    }
    .partner-reward-document__center {
        text-align: center;
    }
    .partner-reward-document__total {
        margin-top: 18px;
        font-weight: 700;
        text-align: right;
        font-size: 13px;
    }
    .partner-reward-document__signatures {
        width: 100%;
        margin-top: 48px;
        border-collapse: collapse;
    }
    .partner-reward-document__signatures td {
        width: 50%;
        padding-top: 18px;
        text-align: center;
        vertical-align: top;
    }
    .partner-reward-document__signatures-title {
        margin-bottom: 48px;
    }
</style>
</head>
<body>

<div class="partner-reward-document">
    <div class="partner-reward-document__meta">
        <table>
            <tr>
                <td><strong>Агент:</strong> <?= $documentData['partnerName'] ?></td>
                <td style="text-align: right;"><strong>Расчетный период:</strong> <?= $documentData['periodText'] ?></td>
            </tr>
        </table>
    </div>

    <table class="partner-reward-document__table">
        <thead>
            <tr>
                <th style="width: 29%;">Наименование клиента</th>
                <th style="width: 17%;">Дата регистрации клиента</th>
                <th style="width: 22%;">Сумма оплаченных услуг, за которые начислено вознаграждение</th>
                <th style="width: 17%;">Сумма оплаченных счетов</th>
                <th style="width: 15%;">Сумма вознаграждения</th>
            </tr>
        </thead>
        <tbody>
            <tr class="partner-reward-document__summary">
                <td colspan="2">Итого</td>
                <td class="partner-reward-document__number"><?= PartnerRewardsNewFilter::getNumberFormat($summary['paid_summary_reward']); ?></td>
                <td class="partner-reward-document__number"><?= PartnerRewardsNewFilter::getNumberFormat($summary['paid_summary']); ?></td>
                <td class="partner-reward-document__number"><?= PartnerRewardsNewFilter::getNumberFormat($summary['sum']); ?></td>
            </tr>
            <?php foreach ($documentData['rows'] as $model) : ?>
                <tr>
                    <td><?= $model['contragent_name']; ?></td>
                    <td class="partner-reward-document__center"><?= $model['client_created']; ?></td>
                    <td class="partner-reward-document__number"><?= PartnerRewardsNewFilter::getNumberFormat($model['paid_summary_reward']); ?></td>
                    <td class="partner-reward-document__number"><?= PartnerRewardsNewFilter::getNumberFormat($model['paid_summary']); ?></td>
                    <td class="partner-reward-document__number"><?= PartnerRewardsNewFilter::getNumberFormat($model['sum']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="partner-reward-document__total">
        Итого сумма вознаграждения <?= PartnerRewardsNewFilter::getNumberFormat($summary['sum']); ?> руб.
    </div>

    <table class="partner-reward-document__signatures">
        <tr>
            <td>
                <div class="partner-reward-document__signatures-title">
                    <?= trim($documentData['operatorDirectorPost'] . ' ' . $documentData['operatorOrganizationName']) ?>
                </div>
                <div><?= $documentData['operatorDirectorName'] ?></div>
            </td>
            <td>
                <div class="partner-reward-document__signatures-title">
                    <?= $documentData['partnerName'] ?>
                </div>
                <div>____________________</div>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
