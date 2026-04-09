<?php

namespace app\classes\behaviors\payment;

use app\dao\ClientAccountDao;
use app\models\Invoice;
use app\models\InvoicePaymentLink;
use app\models\Payment;
use Yii;
use yii\base\Behavior;
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

        if ((int)$invoice->type_id === Invoice::TYPE_PREPAID) {
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
        if (!$link->save(false)) {
            Yii::warning('Failed to save InvoicePaymentLink for invoice #' . $invoice->id);
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
            ->leftJoin(
                InvoicePaymentLink::tableName() . ' l',
                'l.payment_id = p.id'
            )
            ->where(['p.client_id' => $clientId])
            ->andWhere(['between', 'COALESCE(p.oper_date, p.payment_date)', $dateFrom, $dateTo])
            ->andWhere(['between', 'p.sum', $invoice->sum - 0.009, $invoice->sum + 0.009])
            ->andWhere(['l.id' => null])
            ->orderBy([
                new \yii\db\Expression('(p.bill_no = :billNo OR p.bill_vis_no = :billNo) DESC', [':billNo' => $invoice->bill_no]),
                'COALESCE(p.oper_date, p.payment_date)' => SORT_ASC,
                'p.id' => SORT_ASC,
            ])
            ->limit(1)
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
