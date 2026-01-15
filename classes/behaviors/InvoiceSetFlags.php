<?php

namespace app\classes\behaviors;

use app\helpers\DateTimeZoneHelper;
use app\models\BillDocument;
use app\models\Country;
use app\models\Invoice;
use yii\base\Behavior;
use yii\base\ModelEvent;
use yii\db\ActiveRecord;


class InvoiceSetFlags extends Behavior
{
    /**
     * @return array
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_INSERT => "setFlags",
        ];
    }

    public function setFlags(ModelEvent $event)
    {
        /** @var Invoice $invoice */
        $invoice = $event->sender;

        $invoiceDate = new \DateTimeImmutable($invoice->date);

        $invoice->is_invoice = (bool)BillDocument::dao()->me()->_isSF($invoice->bill->client_id, BillDocument::TYPE_INVOICE, $invoiceDate->getTimestamp(), $invoice->type_id);
        $invoice->is_act = (bool)BillDocument::dao()->me()->_isSF($invoice->bill->client_id, BillDocument::TYPE_AKT, $invoiceDate->getTimestamp());
        $invoice->is_upd2 = (bool)BillDocument::dao()->me()->_isSF($invoice->bill->client_id, BillDocument::TYPE_UPD2, $invoiceDate->getTimestamp());

        // за пределами России - только инвойсы
        if ($invoice->bill->clientAccount->contragent->country_id != Country::RUSSIA || \Yii::$app->isEu()) {
            $invoice->is_invoice = $invoice->is_invoice || $invoice->is_act;
            $invoice->is_act = $invoice->is_upd2 = false;
        }

        if (!$invoice->pay_bill_until) {
            // для "не россйских" с/ф и при наличии зарегистрированных, дата вычисляется
            if (
                Invoice::isHaveRegistredInvoices($invoice->bill, $invoice->type_id, false)
                && Invoice::isHaveRegistredInvoices($invoice->bill, $invoice->type_id, true)
                && $invoice->bill->clientAccount->contragent->country_id != Country::RUSSIA
            ) {
                $invoice->pay_bill_until = (new \DateTimeImmutable($invoice->date))->modify('+30 day')->format(DateTimeZoneHelper::DATE_FORMAT);
            }
        }
    }
}
