<?php
namespace app\controllers\utils;

use app\classes\Encrypt;
use app\models\ClientAccount;
use app\models\ClientContragent;
use app\models\Organization;
use Yii;
use yii\web\Response;
use app\classes\BaseController;
use app\classes\QRcode\QRcode;

class QrCodeController extends BaseController
{
    const QR_RECEIPT_HEADER = 'ST00011'; // prefix: 'ST'; format ver 0001; encode: 1-CP151, 2-UTF8
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        array_unshift(
            $behaviors['access']['rules'],
            [
                'allow' => true,
                'actions' => ['get', 'receipt'],
            ]
        );
        return $behaviors;
    }

    public function actionGet($data)
    {
        $response = Yii::$app->getResponse();
        $response->headers->set('Content-Type', 'image/gif');
        $response->format = Response::FORMAT_RAW;

        QRcode::gif(trim($data), false, 'H', 4, 2);
        //\PHPQRCode\QRcode::png(trim($data), false, 'H', 4, 2);
    }

    /**
     * Генерация QR-кода для оплаты квитанцией
     *
     * @param int $accountId ClientAccount.id
     * @param int $sum Сумма оплаты
     * @param string $data закодированный массив с данными
     */
    public function actionReceipt($accountId = 0, $sum = 1000, $data = '')
    {
        if ($data) {
            $data = Encrypt::decodeToArray($data);
        }

        if ($data) {
            if(isset($data['accountId'])) {
                $accountId = (int)$data['accountId'];
            }
            if(isset($data['sum'])) {
                $sum = $data['sum'];
            }
        }

        $sum = (float)$sum;
        if ($sum <= 0 || $sum > 100000) {
            $sum = 1000;
        }

        $qrString = self::QR_RECEIPT_HEADER;
        $qrData = [];

        $account = ClientAccount::findOne(['id' => $accountId]);
        if ($account) {
            $organization = $account->contract->organization;
            $qrData['Name'] = $organization->name;
            $qrData['PersonalAcc'] = $organization->settlementAccount->bank_account;
            $qrData['BankName'] = $organization->settlementAccount->bank_name;
            $qrData['BIC'] = $organization->settlementAccount->bank_bik;
            $qrData['CorrespAcc'] = $organization->settlementAccount->bank_correspondent_account;
            $qrData['PayeeINN'] = $organization->tax_registration_id;
            $qrData['KPP'] = $organization->tax_registration_reason;

            $contragent = $account->contract->contragent;
            if ($contragent->legal_type == ClientContragent::PERSON_TYPE) {
                if ($contragent->person) {
                    $qrData['FirstName'] = $contragent->person->first_name;
                    $qrData['LastName'] = $contragent->person->last_name;

                    if ($contragent->person->middle_name) {
                        $qrData['MiddleName'] = $contragent->person->middle_name;
                    }
                }
            } else {
                if ($contragent->inn) {
                    $qrData['PayerINN'] = $contragent->inn;
                }
            }
            if ($contragent->address) {
                $qrData['PayerAddress'] = $contragent->address;
            }
            $qrData['Purpose'] = 'Предоплата по лицевому счету ' . $accountId . ' за телекоммуникационные услуги';
        }

        $qrData['Sum'] = abs($sum) * 100;

        foreach ($qrData as $key => $value) {
            $qrString .= '|' . $key . '=' . iconv('utf-8', 'cp1251//ignore', $value);
        }

        //echo $qrString;

        $response = Yii::$app->getResponse();
        $response->headers->set('Content-Type', 'image/gif');
        $response->format = Response::FORMAT_RAW;

        QRcode::gif(trim($qrString), false, 'M', 3, 1);
    }
}
