<?php

namespace app\classes\behaviors\payment;

use app\dao\ClientAccountDao;
use app\models\Invoice;
use yii\base\Behavior;
use yii\db\ActiveRecord;

class InvoiceSetPaymentNumber extends Behavior
{
    /**
     * @return array
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_INSERT => 'doSet',
        ];
    }

    public function doSet($event)
    {
        /** @var Invoice $invoice */
        $invoice = $event->sender;

        ClientAccountDao::me()->updateInvoicePayments($invoice->bill->client_id);
        $invoice->refresh();

        $headerShorts = array_map(
            fn($payment) => $payment->headerShort,
            $invoice->getMatchedPayments()
        );
        $invoice->updateAttributes(['upd_payment_number' => implode(', ', $headerShorts)]);
    }
}
