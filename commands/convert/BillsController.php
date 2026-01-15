<?php

namespace app\commands\convert;

use app\exceptions\ModelValidationException;
use app\models\Bill;
use app\models\BillDocument;
use app\models\BillLine;
use app\models\EventQueue;
use app\models\Invoice;
use app\models\InvoiceLine;
use app\models\Payment;
use app\models\PaymentOrder;
use yii\console\Controller;

class BillsController extends Controller
{
    public function actionCleanCommentContacts()
    {
        $time0 = microtime(true);;
        $query = \app\models\ClientContact::find()->where(['!=', 'comment', ''])->createCommand();
        foreach ($query->query() as $contact) {
            $comment = $contact['comment'];
            $newComment = \yii\helpers\HtmlPurifier::process($comment);

            if ($newComment != $comment) {
                echo PHP_EOL . $comment . ' /// ' . $newComment;
                \app\models\ClientContact::updateAll(['comment' => $comment], ['id' => $contact['id']]);
            }
        }
        echo PHP_EOL . 'work length: ' . round(time() - $time0, 2) . ' sec';
    }

    /**
     * Сборка данных для колонки `payment_date` модели Bill
     */
    public function actionRebuildPaymentDateColumn()
    {
        $db = Bill::getDb();
        $billTableName = Bill::tableName();
        $newpaymentsTableName = Payment::tableName();
        $newpaymentsOrdersTableName = PaymentOrder::tableName();
        $transaction = $db->beginTransaction();
        try {
            $db->createCommand("
                DROP TEMPORARY TABLE IF EXISTS temporary_newpayments;
                CREATE TEMPORARY TABLE temporary_newpayments (INDEX(bill_no)) AS (
                  SELECT bill_no, MAX(payment_date) payment_date
                  FROM {$newpaymentsTableName} newpayments
                  WHERE sum > 0
                  GROUP BY bill_no
                );
                DROP TEMPORARY TABLE IF EXISTS temporary_payments_orders;
                CREATE TEMPORARY TABLE temporary_payments_orders (INDEX(bill_no)) AS (
                  SELECT
                    payments_orders_groupped.bill_no,
                    newpayments.payment_date
                  FROM (
                    SELECT bill_no, MAX(payment_id) payment_id
                    FROM {$newpaymentsOrdersTableName}
                    WHERE sum > 0
                    GROUP BY bill_no
                  ) payments_orders_groupped
                    INNER JOIN {$newpaymentsTableName} newpayments ON payments_orders_groupped.payment_id = newpayments.id
                );
                UPDATE {$billTableName} newbills
                  INNER JOIN (
                    SELECT
                     newbills.id bills_id,
                     CASE WHEN newbills.bill_date > COALESCE(temporary_newpayments.payment_date, temporary_payments_orders.payment_date, null) THEN
                       newbills.bill_date
                     ELSE
                       COALESCE(temporary_newpayments.payment_date, temporary_payments_orders.payment_date, null)
                     END payment_date
                    FROM
                     {$billTableName} newbills
                     LEFT JOIN temporary_newpayments
                       ON temporary_newpayments.bill_no = newbills.bill_no
                     LEFT JOIN temporary_payments_orders
                       ON temporary_payments_orders.bill_no = newbills.bill_no
                    WHERE
                     newbills.is_payed = 1 AND newbills.sum > 0
                    ) temporal ON newbills.id = temporal.bills_id
                SET newbills.payment_date = temporal.payment_date
                WHERE newbills.is_payed = 1 AND newbills.sum > 0;
                DROP TEMPORARY TABLE IF EXISTS temporary_newpayments;
                DROP TEMPORARY TABLE IF EXISTS temporary_payments_orders;
            ")->execute();
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            echo $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * Удаление данных из колонки `payment_date` модели Bill
     */
    public function actionClearPaymentDateColumn()
    {
        $db = Bill::getDb();
        $transaction = $db->beginTransaction();
        try {
            Bill::updateAll(['payment_date' => null,]);
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            echo $e->getMessage() . PHP_EOL;
        }

    }

    public function actionInvoiceFullSums()
    {
        $time = time();
        $query = Invoice::find()->where(['is_reversal' => 0])->with('bill');

        /** @var Invoice $invoice */
        foreach ($query->each() as $invoice) {
            echo " .";
            try {
                /** @var BIll $bill */
                $bill = $invoice->bill;

                if (!$bill) {
                    echo PHP_EOL . '??' . $invoice->bill_no;
                    continue;
                }

                $lines = $bill->getLinesByTypeId($invoice->type_id);

                if ($invoice->type_id == Invoice::TYPE_PREPAID) {
                    $lines = BillLine::refactLinesWithFourOrderFacture($bill, $lines);
                }

                if (!$lines) {
                    continue;
                }

                $sumData = BillLine::getSumsLines($lines);

                $invoice->sum = $sumData['sum'];
                $invoice->sum_tax = $sumData['sum_tax'];
                $invoice->sum_without_tax = $sumData['sum_without_tax'];

                if (!$invoice->save()) {
                    throw new ModelValidationException($invoice);
                }
            } catch (\Exception $e) {
                echo PHP_EOL . '!!' . $e->getMessage();
            }
        }

        echo PHP_EOL . (time() - $time);
    }

    /**
     * Заполняем флаги с/ф и акт в закрывающих документах
     * @throws ModelValidationException
     */
    public function actionClosingDocumentsSetFlags()
    {
        $invoiceQuery = Invoice::find()//->where(['is_invoice' => 0, 'is_act' => 0])
        ->with('bill', 'lines');
        $invoiceQuery->where(['like', 'bill_no', '202601-%', false]);

//        echo $invoiceQuery->createCommand()->rawSql;
//        exit();

        /** @var Invoice $invoice */
        foreach ($invoiceQuery->each() as $invoice) {
            echo ' .';
            $invoiceDate = new \DateTimeImmutable($invoice->date);
            $invoice->is_invoice = (int)(bool)BillDocument::dao()->me()->_isSF($invoice->bill->client_id, BillDocument::TYPE_INVOICE, $invoiceDate->getTimestamp(), $invoice->type_id);
            $invoice->is_act = (int)(bool)BillDocument::dao()->me()->_isSF($invoice->bill->client_id, BillDocument::TYPE_AKT, $invoiceDate->getTimestamp());
            $invoice->is_upd2 = (bool)BillDocument::dao()->me()->_isSF($invoice->bill->client_id, BillDocument::TYPE_UPD2, $invoiceDate->getTimestamp());

            // no actions on save
            $invoice->detachBehaviors();

            if (!$invoice->save()) {
                throw new ModelValidationException($invoice);
            }

//            if ($invoice->is_invoice) {
//                EventQueue::go(EventQueue::INVOICE_GENERATE_PDF, ['id' => $invoice->id, 'document' => BillDocument::TYPE_INVOICE]);
//            }
//
//            if ($invoice->is_act) {
//                EventQueue::go(EventQueue::INVOICE_GENERATE_PDF, ['id' => $invoice->id, 'document' => BillDocument::TYPE_ACT]);
//            }

            if ($invoice->is_upd2) {
                EventQueue::go(EventQueue::INVOICE_GENERATE_PDF, ['id' => $invoice->id, 'document' => BillDocument::TYPE_UPD2]);
            }

        }
    }

    public function actionFillLinkInvoceLine()
    {
        $query = InvoiceLine::find()->andWhere(['line_id' => null])->orderBy(['pk' => SORT_ASC])->with('invoice');

        /** @var InvoiceLine $iLine */
        foreach ($query->each() as $iLine) {
            echo ' .';
            /** @var BillLine $line */
            $linePk = BillLine::find()->where([
                'bill_no' => $iLine->invoice->bill_no,
                'item' => $iLine->item,
                'price' => $iLine->price
            ])->select('pk')->scalar();

            if (!$linePk) {
                continue;
            }
            echo '+';

            $iLine->line_id = $linePk;
            if (!$iLine->save()) {
                throw new ModelValidationException($iLine);
            }
        }
    }
}
