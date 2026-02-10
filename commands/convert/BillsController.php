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

    /**
     * Перепривязка API-платежей с авансовых счетов на существующие обычные счета
     */
    public function actionRelinkApiPayments()
    {
        $db = Bill::getDb();
        $dateFrom = '2024-01-01';
        $relinked = 0;
        $skipped = 0;
        $deletedBills = 0;

        // Выборка API-платежей за последние 6 месяцев, привязанных к авансовым счетам
        $payments = Payment::find()
            ->alias('p')
            ->innerJoin(['b' => Bill::tableName()], 'b.bill_no = p.bill_no')
            ->where([
                'p.type' => Payment::TYPE_API,
                'b.is_user_prepay' => 1,
//                'b.client_id' => 139550
            ])
            ->andWhere(['>=', 'p.payment_date', $dateFrom])
            ->all();

        echo 'Найдено платежей: ' . count($payments) . PHP_EOL;

        /** @var Payment $payment */
        foreach ($payments as $payment) {
            $prepayBillNo = $payment->bill_no;

            // Проверяем имя канала платежа — должен содержать "_tinkoff_" или "_тинькофф_"
            $channelName = $payment->apiChannel ? $payment->apiChannel->name : '';
            if (mb_stripos($channelName, '_tinkoff_') === false && mb_stripos($channelName, '_тинькофф_') === false) {
                echo "  [SKIP] Payment #{$payment->id}: канал '{$channelName}' не tinkoff" . PHP_EOL;
                $skipped++;
                continue;
            }

            // Проверяем, есть ли у авансового счёта инвойс
            $hasInvoice = Invoice::find()
                ->where(['bill_no' => $prepayBillNo])
                ->exists();

            if ($hasInvoice) {
                echo "  [SKIP] Payment #{$payment->id}: у авансового счёта {$prepayBillNo} есть инвойс" . PHP_EOL;
                $skipped++;
                continue;
            }

            // Загружаем авансовый счёт для получения client_id и currency
            $prepayBill = Bill::findOne(['bill_no' => $prepayBillNo]);
            if (!$prepayBill) {
                echo "  [SKIP] Payment #{$payment->id}: авансовый счёт {$prepayBillNo} не найден" . PHP_EOL;
                $skipped++;
                continue;
            }

            // Проверяем, что в авансовом счёте ровно одна строка и она типа zadatok
            $billLines = $prepayBill->lines;
            if (count($billLines) !== 1 || $billLines[0]->type !== BillLine::LINE_TYPE_ZADATOK) {
                echo "  [SKIP] Payment #{$payment->id}: счёт {$prepayBillNo} не содержит единственную строку zadatok" . PHP_EOL;
                $skipped++;
                continue;
            }

            // Ищем ранний обычный счёт для того же client_id, currency, с bill_date <= payment_date
            $targetBill = Bill::find()
                ->where([
                    'client_id' => $prepayBill->client_id,
                    'currency' => $prepayBill->currency,
                ])
                ->andWhere(['!=', 'is_user_prepay', 1])
                ->andWhere(['<=', 'bill_date', $payment->payment_date])
                ->orderBy(['bill_date' => SORT_DESC])
                ->one();

            if (!$targetBill) {
                echo "  [SKIP] Payment #{$payment->id}: не найден подходящий обычный счёт для client_id={$prepayBill->client_id}" . PHP_EOL;
                $skipped++;
                continue;
            }

            // Перепривязка платежа в транзакции
            $transaction = $db->beginTransaction();
            try {
                $oldBillNo = $payment->bill_no;
                $payment->bill_no = $targetBill->bill_no;
                $payment->bill_vis_no = $targetBill->bill_no;

                // Отключаем behaviors чтобы не триггерить побочные эффекты
                $payment->detachBehaviors();

                if (!$payment->save(false)) {
                    throw new ModelValidationException($payment);
                }

                echo "  [OK] Payment #{$payment->id}: {$oldBillNo} -> {$targetBill->bill_no}" . PHP_EOL;
                $relinked++;

                // Проверяем, остались ли платежи на авансовом счёте
                $remainingPayments = Payment::find()
                    ->where(['bill_no' => $oldBillNo])
                    ->exists();

                if (!$remainingPayments) {
                    // Удаляем авансовый счёт и его строки
                    $prepayBill->isSkipCheckCorrection = true;
                    $prepayBill->detachBehaviors();
                    $prepayBill->delete();
                    echo "  [DEL] Авансовый счёт {$oldBillNo} удалён" . PHP_EOL;
                    $deletedBills++;
                }

                $transaction->commit();
            } catch (\Exception $e) {
                $transaction->rollBack();
                echo "  [ERR] Payment #{$payment->id}: " . $e->getMessage() . PHP_EOL;
                $skipped++;
            }
        }

        echo PHP_EOL . "Итого: перепривязано={$relinked}, пропущено={$skipped}, удалено счетов={$deletedBills}" . PHP_EOL;
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
