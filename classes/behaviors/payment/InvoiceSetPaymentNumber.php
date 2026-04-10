<?php

namespace app\classes\behaviors\payment;

use app\dao\ClientAccountDao;
use app\exceptions\ModelValidationException;
use app\models\Invoice;
use app\models\InvoicePaymentLink;
use app\models\Payment;
use yii\base\Behavior;
use yii\db\Expression;
use yii\db\ActiveRecord;

class InvoiceSetPaymentNumber extends Behavior
{
    private const PREPAID_EXACT_MATCH_DAYS = 7;

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

        if ($invoice->type_id == Invoice::TYPE_PREPAID) {
            $this->attachExactPaymentToPrepaidInvoice($invoice);
            return;
        }

        $this->refreshInvoicePayments($invoice);
    }

    private function attachExactPaymentToPrepaidInvoice(Invoice $invoice): void
    {
        $payment = $this->findExactPaymentForPrepaidInvoice($invoice);

        if (!$payment) {
            $this->refreshInvoicePaymentsNumber($invoice);
            return;
        }

        $link = new InvoicePaymentLink();
        $link->invoice_id = $invoice->id;
        $link->payment_id = $payment->id;
        $link->client_account_id = $invoice->bill->client_id;
        $link->is_matched = 1;
        $link->sum = $invoice->sum;
        if (!$link->save()) {
            throw new ModelValidationException($link);
        }

        $this->refreshInvoicePaymentsNumber($invoice);
    }

    private function findExactPaymentForPrepaidInvoice(Invoice $invoice): ?Payment
    {
        $clientId = $invoice->bill->client_id;
        $invoiceDate = new \DateTimeImmutable($invoice->date);
        $dateFrom = $invoiceDate->modify('-' . self::PREPAID_EXACT_MATCH_DAYS . ' days')->format('Y-m-d');
        $dateTo = $invoiceDate->modify('+' . self::PREPAID_EXACT_MATCH_DAYS . ' days')->format('Y-m-d');

        return Payment::find()
            ->alias('p')
            ->where(['p.client_id' => $clientId])
            ->andWhere(['between', 'p.oper_date', $dateFrom, $dateTo])
            ->andWhere(['between', 'p.sum', $invoice->sum - 0.009, $invoice->sum + 0.009])
            ->orderBy([
                new Expression('ABS(DATEDIFF(p.oper_date, :invoiceDate)) ASC', [':invoiceDate' => $invoice->date]),
                'p.id' => SORT_ASC,
            ])
            ->one();
    }

    private function refreshInvoicePayments(Invoice $invoice): void
    {
        ClientAccountDao::me()->updateInvoicePayments($invoice->bill->client_id);
        $this->refreshInvoicePaymentsNumber($invoice);
    }

    private function refreshInvoicePaymentsNumber(Invoice $invoice): void
    {
        $invoice->refresh();
        $headerShorts = array_map(
            fn($payment) => $payment->headerShort,
            $invoice->getMatchedPayments()
        );
        $invoice->updateAttributes(['upd_payment_number' => implode(', ', $headerShorts)]);
    }
}
