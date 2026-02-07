<?php

use app\models\Bill;
use app\models\ClientAccount;
use app\classes\documents\DocumentReportFactory;
use app\classes\documents\DocumentReport;

//для просмотра клиентами того, что было отправлено через модуль mail

header('Content-Type: text/html; charset=utf-8');
define("PATH_TO_ROOT", '../stat/');
include PATH_TO_ROOT . "conf_yii.php";
$o = MailJob::GetObjectP();


if (isset($o["object_type"]) && $o["object_type"] && in_array($o["object_type"], [
        "bill",
        "assignment",
        "order",
        "notice",
        "invoice",
        "akt",
        "lading",
        "new_director_info",
        "upd",
        "upd2",
        "notice_mcm_telekom",
        "sogl_mcm_telekom",
        "sogl_mcn_service",
        "sogl_mcn_telekom",
        "sogl_mcn_telekom_to_service",
        "sogl_mcn_service_to_abonservice"
    ])
) {
    $db->Query('update mail_object set view_count=view_count+1, view_ts = IF(view_ts=0,NOW(),view_ts) where object_id=' . $db->escape($o['object_id']));

    if ($o["object_type"] == "assignment" && $o["source"] == 2) {
        $o["source"] = 4;
    }
    $R = [];

    $R['bill'] = $o['object_param'];
    $R['obj'] = $o["object_type"];
    $R['source'] = $o["source"];

    if ($R['obj'] === 'bill') {
        $bill = Bill::findOne(['bill_no' => $R['bill']]);

        $report = DocumentReportFactory::me()->getReport($bill, DocumentReport::DOC_TYPE_BILL, $sendEmail = 1);

        if (isset($o['is_pdf']) && $o['is_pdf']) {
            header('Content-Type: application/pdf');
            echo $report->renderAsPDF();
            exit();
        }

        echo $report->render();
    } else {
        if (
            (isset($R['obect_type']) && in_array($R['object_type'], ['sogl_mcn_service', 'sogl_mcn_telekom_to_service', 'sogl_mcn_service_to_abonservice']))
            || (isset($R['obj']) && in_array($R['obj'], ['notice_mcm_telekom', 'sogl_mcm_telekom', 'sogl_mcn_telekom', 'sogl_mcn_service', 'sogl_mcn_telekom_to_service', 'sogl_mcn_service_to_abonservice']))
        ) {
            $bill = Bill::find()->where(['client_id' => $R['bill']])->orderBy(['bill_date' => SORT_DESC])->limit(1)->one();
            $report = DocumentReportFactory::me()->getReport($bill, $R['obj']);
            header('Content-Type: application/pdf');
            echo $report->renderAsPDF();
        } else {
            /** @var Bill $bill */
            $bill = Bill::find()->where(['bill_no' => $R['bill']])->orderBy(['bill_date' => SORT_DESC])->limit(1)->one();


            $addWhere = [];
            if ($bill->clientAccount->organization->country_id == \app\models\Country::RUSSIA) {
                if ($R['obj'] == 'invoice') {
                    $addWhere = ['is_invoice' => 1];
                } elseif ($R['obj'] == 'akt') {
                    $addWhere = ['is_act' => 1];
                }
            }
//            if ($bill->clientAccount->organization->country_id != \app\models\Country::RUSSIA) {
            /** @var \app\models\Invoice $invoice */
            $invoice = \app\models\Invoice::find()->where(['bill_no' => $bill->bill_no, 'type_id' => $R['source']])->andWhere($addWhere)->one();
            $documentStr = $bill->clientAccount->organization->country_id != \app\models\Country::RUSSIA ? 'invoice' : ($R['obj'] == 'akt' ? 'act' : $R['obj']);

            if ($documentStr === 'upd2') {
                $content = $invoice->downloadFile($documentStr);
                $path = $invoice->getFilePath($documentStr);
                $info = pathinfo($path);
                header('Content-Type: application/pdf');
                header('Content-disposition: inline; filename="' . $info['basename'] . '"');
                echo $content;
                exit;
            }

            $path = $invoice->getFilePath($documentStr);
            $info = pathinfo($path);
            header('Content-Type: application/pdf');
            header('Content-disposition: inline; filename="' . $info['basename'] . '"');

            if (file_exists($path)) {
                echo file_get_contents($path);
            } else {
                if ($invoice->generatePdfFile($documentStr)) {
                    if (file_exists($path)) {
                        echo file_get_contents($path);
                    }
                }
            }
            exit();
//            } else {
//                $design->assign('emailed', 1);
//                $_GET = $R;
//                \app\classes\StatModule::newaccounts()->newaccounts_bill_print('', ['is_pdf' => $o['is_pdf']]);
//                $design->Process();
//            }
        }
    }
}
