<?php

use app\classes\Encrypt;
use app\classes\Html2Pdf;
use app\models\Bill;
use app\classes\documents\DocumentReportFactory;
use app\classes\documents\DocumentReport;
use app\models\ClientAccount;
use app\models\Country;
use app\models\Invoice;
use app\modules\uu\models_light\InvoiceLight;
use yii\web\Response;
use app\models\document\PaymentTemplateType;

define("PATH_TO_ROOT", '../stat/');
include PATH_TO_ROOT . "conf_yii.php";

$isPassGet = false;
if (isset($_GET['tpl1'])) {
    $isPassGet = true;
}

$billStr = get_param_raw('bill');
if (!($R = Encrypt::decodeToArray($billStr))) {
    if (!$isPassGet) {
        return;
    }
}

$bill = null;

if (!isset($R['tpl1']) && (!isset($R["object"]) || $R["object"] != "receipt-2-RUB")) {
    if ($R['client'] ?? false && $R['bill'] ?? false) {

        $bill = Bill::findOne(['bill_no' => $R['bill'], 'client_id' => $R['client']]);
        if ($bill) {
            $bill->setViewed();
        }
    }
}

if ($R) {
    $_GET = $R;
} elseif($isPassGet){
    $R = $_GET;
} else {
    exit();
}


if (isset($R['renderMode'])) {
    $isPdf = ($R['renderMode'] === 'pdf');
} elseif (isset($R['is_pdf'])) {
    $isPdf = ((int)$R['is_pdf'] === 1);
} else {
    $isPdf = false;
}
$isPdf = (bool)$isPdf;
$isEmailed = get_param_raw('emailed', 1);
$isLandscape = (bool)($R['is_portrait'] ?? false);
$isIncludeSignatureStamp = isset($R['include_signature_stamp']) && (bool)$R['include_signature_stamp'] ? true : false;

header('Content-Type: ' . ($isPdf ? 'application/pdf' : 'text/html; charset=utf-8'));

if (
    isset($R['invoice_id'])
    && ($invoice = Invoice::findOne(['id' => $R['invoice_id']]))
    && ($invoice->bill->clientAccountModel->getUuCountryId() == Country::RUSSIA)
) {
    $_GET = [
        'module' => 'newaccounts',
        'action' => 'bill_print',
        'bill' => $invoice->bill_no,
        'to_print' => 'true',
        'invoice_id' => $invoice->id,
        'is_pdf' => $isPdf
    ];

    if ($R['is_act'] ?? false) {
        $_GET['object'] = 'akt-' . $invoice->type_id;
    } else {
        $_GET['object'] = 'invoice2';
    }

    global $design;
    $design->assign('emailed', true);

    if ($isIncludeSignatureStamp) {
        $design->assign('include_signature_stamp', true);
    }

    \app\classes\StatModule::newaccounts()->newaccounts_bill_print('');

    $design->Process();
    exit;
}

if (isset($R['tpl1']) && $R['tpl1'] == 1) {

    if (!isset($R['invoice_id']) || !isset($R['client'])) {
        return;
    }

    $invoice = Invoice::findOne(['id' => $R['invoice_id']]);

    if (
        !$invoice
        || !($bill = $invoice->bill)
        || !($clientAccount = $bill->clientAccount)
        || $bill->client_id != $R['client']
    ) {
        return;
    }

    $clientAccount = $invoice->bill->clientAccount;
    $invoiceDocument = (new InvoiceLight($clientAccount));
    $invoiceDocument->setInvoice($invoice);
    $invoiceDocument->setBill($bill);
    $invoiceDocument->setLanguage(Country::findOne(['code' => $clientAccount->getUuCountryId() ?: Country::RUSSIA])->lang);

    $attachmentName = $clientAccount->id . '-' . $invoice->number . '.pdf';

    Yii::$app->response->format = Response::FORMAT_RAW;
    Yii::$app->response->content = $invoiceDocument->render(true, null, true /*$isIncludeSignatureStamp*/);;
    Yii::$app->response->setDownloadHeaders($attachmentName, 'application/pdf', true);

    \Yii::$app->end();
}

if (isset($R['tpl']) && $R['tpl'] == 'b') {

    if (!isset($R['a']) || !($clientAccount = ClientAccount::findOne(['id' => $R['a']]))) {
        return;
    }

    if (
        isset($R['b'])
        && (
            !($bill = Bill::findOne(['bill_no' => $R['b']]))
            || $bill->client_id != $clientAccount->id
        )) {
        return;
    }

    if (
        isset($R['act'])
        && (
            !($act = Invoice::findOne(['id' => $R['act']]))
            || $act->bill->client_id != $clientAccount->id
        )) {
        return;
    }

    if (
        isset($R['i'])
        && (
            !($invoice = Invoice::findOne(['id' => $R['i']]))
            || $invoice->bill->client_id != $clientAccount->id
        )) {
        return;
    }

    if (
        isset($R['storno'])
        && (
            !($storno = Invoice::findOne(['id' => $R['storno']]))
            || $storno->bill->client_id != $clientAccount->id
        )) {
        return;
    }


    $invoiceDocument = (new InvoiceLight($clientAccount));

    if (isset($R['cur_st'])) {

        $billCs = \app\modules\uu\models\Bill::findOne(['client_account_id' => $R['a'], 'is_converted' => 0]);
        if (!$billCs) {
            $billCs = new Bill();
            $billCs->client_id = $clientAccount->id;
            $billCs->bill_date = date(\app\helpers\DateTimeZoneHelper::DATE_FORMAT);
        }

        $invoiceDocument
            ->setBill($billCs)
            ->setTemplateType(InvoiceLight::TYPE_CURRENT_STATEMENT);
    }

    if (isset($bill)) {
        $invoiceDocument
            ->setBill($bill)
            ->setTemplateType(InvoiceLight::TYPE_BILL);
    }

    if (isset($invoice)) {
        $invoiceDocument
            ->setInvoice($invoice)
            ->setBill($invoice->bill)
            ->setTemplateType(InvoiceLight::TYPE_INVOICE);
    }

    if (isset($act)) {
        $invoiceDocument
            ->setInvoice($act)
            ->setBill($act->bill)
            ->setTemplateType(InvoiceLight::TYPE_ACT);
    }

    if (isset($storno)) {
        $invoiceDocument
            ->setInvoice($storno)
            ->setBill($storno->bill)
            ->setTemplateType(InvoiceLight::TYPE_INVOICE_STORNO);
    }


    $country = Country::findOne(['code' => $R['co'] ?? ($clientAccount->getUuCountryId() ?: Country::RUSSIA)]);
    $invoiceDocument->setCountry($country->code);
    $invoiceDocument->setLanguage($country->lang);
    $attachmentName = $clientAccount->id . '-' . (isset($invoice) ? $invoice->number : (isset($act) ? $act->number : (isset($bill) ? $bill->bill_no : ''))) . '.pdf';

    Yii::$app->response->content = $invoiceDocument->render($isPdf, $invoiceDocument->isLandscape(), $isIncludeSignatureStamp);;

    if ($isPdf) {
        Yii::$app->response->format = Response::FORMAT_RAW;
        Yii::$app->response->setDownloadHeaders($attachmentName, 'application/pdf', true);
    }

    \Yii::$app->end();
}


// 'tpl1' => 3,
if (
    isset($R['tpl1']) && $R['tpl1'] == 3
    && isset($R['account_id'])
    && isset($R['document_number'])
    && isset($R['template_type_id'])
    && isset($R['country_code'])
) {

    $clientAccount = null;

    $templateType = PaymentTemplateType::findOne(['id' => $R['template_type_id']]);

    if (!$templateType || !$templateType->data_source) {
        return;
    }

    $templateTypeId = $templateType->id;

    $isLandscape = (bool)$templateType->is_portrait ? false : true;
    $isBill = $templateType->data_source == PaymentTemplateType::DATA_SOURCE_BILL;
    $isInvoice = $templateType->data_source == PaymentTemplateType::DATA_SOURCE_INVOICE;
    $isUpd = $templateType->data_source == PaymentTemplateType::DATA_SOURCE_UPD;

    if ($isBill) {
        $bill = Bill::findOne(['bill_no' => $R['document_number'], 'client_id' => $R['account_id']]);

        if (!$bill) {
            return;
        }

        $clientAccount = $bill->clientAccount;

        $invoiceDocument = (new InvoiceLight($clientAccount));
        $invoiceDocument->setInvoiceProformaBill($bill);

    } else if ($isInvoice || $isUpd) {
        $invoice = Invoice::findOne(['number' => $R['document_number']]);
        $bill = $invoice->bill;

        if (!$invoice || !$bill) {
            return;
        }

        $clientAccount = $invoice->bill->clientAccount;

        $invoiceDocument = (new InvoiceLight($clientAccount));
        $invoiceDocument->setInvoice($invoice);
    }

    if (
        !$clientAccount
        || !$bill
        || $bill->client_id != $R['account_id']
    ) {
        return;
    }

    $invoiceDocument->setBill($bill);
    $invoiceDocument->setCountry($R['country_code']);
    $invoiceDocument->setTemplateType($templateTypeId);
    $invoiceDocument->setQrDocType($R['document_type'] ?? null);

    $content = $invoiceDocument->render($isPdf, $isLandscape, $isIncludeSignatureStamp);

    $attachmentName = $clientAccount->id . '-' . $R['document_number'] . '.pdf';

    Yii::$app->response->content = $content;

    if ($isPdf) {
        Yii::$app->response->format = Response::FORMAT_RAW;
        Yii::$app->response->setDownloadHeaders($attachmentName, 'application/pdf', true);
    } else {
        Yii::$app->response->format = Response::FORMAT_HTML;
        Yii::$app->response->headers->set('Content-Type', 'text/html; charset=utf-8');
    }

    \Yii::$app->end();
}


// 'tpl1' => 2,
if (
    isset($R['doc_type'])
    || (
        isset($R['object'])
        && strpos($R['object'], 'bill') === 0
    )
) {
    if (isset($R['doc_type']) && $R['doc_type'] == DocumentReport::DOC_TYPE_CURRENT_STATEMENT) {
        $mainDocument = ClientAccount::findOne(['id' => $R['client']]);
    } else {
        $mainDocument = $bill ?: Bill::findOne(['bill_no' => $R['bill']]);
    }

    $report = DocumentReportFactory::me()->getReport(
        $mainDocument,
        (!isset($R['doc_type']) ? DocumentReport::DOC_TYPE_BILL : $R['doc_type']),
        $isEmailed
    );

    echo $isPdf ? $report->renderAsPDF() : $report->render();
} else {
    global $design;

    $design->assign('dbg', isset($_REQUEST['dbg']) && (bool)$_REQUEST['dbg']);
    $design->assign('emailed', $isEmailed);

    if ($isIncludeSignatureStamp) {
        $design->assign('include_signature_stamp', true);
    }

    \app\classes\StatModule::newaccounts()->newaccounts_bill_print('');

    $design->Process();
}