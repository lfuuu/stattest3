<?php

namespace app\commands\convert;

use app\classes\payments\PaymentParser;
use app\dao\ClientAccountDao;
use app\exceptions\ModelValidationException;
use app\forms\client\ClientAccountOptionsForm;
use app\helpers\DateTimeZoneHelper;
use app\models\ClientAccount;
use app\models\ClientAccountOptions;
use app\models\EventQueue;
use app\models\Payment;
use yii\console\Controller;

class PaymentController extends Controller
{
    public function actionA($file)
    {
        $this->go($file);
    }

    private function go($file)
    {
        static $c = [];

        if (!isset($c[$file])) {
            [$type, $payAccs, $payments] = PaymentParser::Parse($file);

            $c[$file] = $payments;
        } else {
            $payments = $c[$file];
        }

        foreach ($payments as $pay) {

            $sum = $pay['sum'];
            if (isset($payAccs[$pay['account']])) {
                $sum = -$pay['sum'];
            }

            $payment = Payment::find()->where([
                'payment_no' => $pay['pp'],
                'oper_date' => \DateTime::createFromFormat(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED, $pay['oper_date_out'] ?: $pay['oper_date'] ?: $pay['date_dot'])->format(DateTimeZoneHelper::DATE_FORMAT),
                'sum' => $sum,
            ])->one();


            if ($payment) {
                try {
                    $this->_c($payment, $pay);
                } catch (\Exception $e) {
                    echo PHP_EOL;
                    print_r($e->getMessage());
                }
            } else {
//                echo ' $payment not found';
            }
        }
    }

    private function _c($payment, $pay)
    {
        $info = \app\models\PaymentInfo::find()->where(['payment_id' => $payment->id])->one();

        if (!$info) {
            $info = new \app\models\PaymentInfo();
            $info->payment_id = $payment->id;

            echo " +";
        }

        $info->payer = $pay['payer'];
        $info->payer_inn = $pay['inn'];
        $info->payer_bik = $pay['bik'];
        $info->payer_bank = $pay['a2'];
        $info->payer_account = $pay['account'];

        $info->getter = $pay['geter'];
        $info->getter_inn = $pay['geter_inn'];
        $info->getter_bik = $pay['geter_bik'];
        $info->getter_bank = $pay['geter_bank'];
        $info->getter_account = $pay['geter_acc'];
        $info->comment = $pay['comment'];

        print_r(var_export($info->getDirtyAttributes(), true));

        if (!$info->save()) {
            throw new ModelValidationException($info);
        }
    }

    /**
     * Установка платежного сальдо для всех активных клиентов.
     * Дата = 1 января года, следующего за (дата первой с/ф + 3 месяца).
     */
    public function actionSetSaldoDate()
    {
        $query = ClientAccount::find()
            ->alias('c')
            ->where(['c.is_active' => 1])
            ->andWhere([
                'not in',
                'c.id',
                ClientAccountOptions::find()
                    ->select('client_account_id')
                    ->where(['option' => ClientAccountOptions::OPTION_PAYMENT_SALDO_DATE])
            ])
            ->orderBy(['c.id' => SORT_ASC]);

        $total = $query->count();
        $i = 0;
        $skipped = 0;

        /** @var ClientAccount $client */
        foreach ($query->each() as $client) {
            $i++;

            $firstInvoiceDate = (new \yii\db\Query())
                ->select('MIN(i.date)')
                ->from('invoice i')
                ->innerJoin('newbills b', 'b.bill_no = i.bill_no')
                ->where(['b.client_id' => $client->id])
                ->scalar();

            if (!$firstInvoiceDate) {
                $skipped++;
                continue;
            }

            $date = new \DateTimeImmutable($firstInvoiceDate);
            $date = $date->modify('+3 months');
            $year = (int)$date->format('Y');
            if ($date->format('m-d') > '01-01') {
                $year++;
            }
            $saldoDate = "$year-01-01";

            echo "\r$i/$total #{$client->id} $saldoDate";

            try {
                (new ClientAccountOptionsForm())
                    ->setClientAccountId($client->id)
                    ->setOption(ClientAccountOptions::OPTION_PAYMENT_SALDO_DATE)
                    ->setValue($saldoDate)
                    ->save();

                ClientAccountDao::me()->updateInvoicePayments($client->id);
                EventQueue::go(EventQueue::UPDATE_BALANCE, $client->id);
                echo " +";
            } catch (\Exception $e) {
                echo " ERR: " . $e->getMessage();
            }
        }

        echo "\nSkipped (no invoices): $skipped\n";
    }

    public function actionAddOrganizationId()
    {
        $query = Payment::find()->where(['organization_id' => null])->orderBy(['id' => SORT_DESC]);

        /** @var Payment $payment */
        foreach ($query->each() as $payment) {
            echo PHP_EOL . $payment->id;
            $orgId = $payment->bill->clientAccount->contract->organization_id;
            if (!$orgId) {
                echo " - ???";
                continue;
            }

            $payment->detachBehaviors();
            $payment->organization_id = $orgId;
            echo ' + ' . $orgId;

            if (!$payment->save()) {
                throw new ModelValidationException($payment);
            }
        }
    }
}
