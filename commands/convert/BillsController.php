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
        $dateFrom = '2023-01-01';
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
            ])
            ->andWhere(['not', ['b.client_id' => 132778]])
            ->andWhere(['>=', 'p.payment_date', $dateFrom])
            ->orderBy(['b.client_id' => SORT_ASC, 'p.id' => SORT_ASC])
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

    /**
     * Поиск не сторнированных с/ф в автоматических февральских счетах с январскими проводками,
     * которые также присутствуют в январском автоматическом счете того же клиента.
     * По умолчанию — анализ. С доп. параметром ($mode != null) — выполнение сторнирования.
     *
     * @param string|null $mode любое значение запускает сторнирование
     */
    public function actionFixFebInvoicesWithJanLines($mode = null)
    {
        $isReal  = $mode !== null;
        $febDate = '2026-02-01';
        $janDate = '2026-01-01';
        $janFrom = '2026-01-01';
        $janTo   = '2026-01-31';

        echo PHP_EOL . ($isReal ? '*** ИСПРАВЛЕНИЕ ***' : '--- АНАЛИЗ ---') . PHP_EOL;

        // Не сторнированные с/ф автоматических февральских счетов
        /** @var Invoice[] $febInvoices */
        $febInvoices = Invoice::find()
            ->alias('i')
            ->innerJoinWith(['bill b'])
            ->where(['i.is_reversal' => 0, 'b.bill_date' => $febDate])
            ->andWhere(['b.client_id' => [
                47853, 48123, 48248, 48706, 49305, 49960, 50033, 50291, 50741, 51489,
                51766, 52509, 52881, 55789, 55985, 56011, 56949, 56997, 57329, 57568,
                57769, 57907, 58026, 58130, 58268, 58307, 58356, 58440, 58444, 58739,
                58763, 58860, 59199, 59268, 59274, 60010, 60117, 60302, 60442, 60856,
                61007, 61334, 61355, 63103, 65430, 66057, 66099, 66309, 66683, 66776,
                67138, 67987, 69116, 70352, 70856, 70894, 75533, 95452, 95538, 95554,
                97459, 99603, 101414, 103312, 107993, 108834, 108855, 108936, 109131, 109729,
                110917, 111140, 111194, 111778, 112389, 112680, 113255, 114248, 114494, 115288,
                115665, 115957, 117585, 117973, 118310, 118547, 118836, 119379, 120452, 120859,
                121168, 121189, 121507, 121661, 121840, 121874, 121891, 122032, 122410, 122502,
                122543, 122586, 122588, 123187, 123285, 123294, 123698, 123699, 123963, 124042,
                124488, 124630, 125113, 125240, 125364, 126505, 127432, 127723, 128772, 128790,
                129641, 131893, 132208, 132731, 133521, 133656, 133715, 133743, 133744, 133745,
                133748, 133749, 133923, 133926, 134107, 134357, 134602, 134603, 134604, 134605,
                134877, 134911, 134960, 135004, 135259, 135265, 135270, 135271, 135474, 135580,
                135581, 135582, 135583, 135759, 136007, 136082, 136120, 136271, 136298, 136369,
                136658, 136681, 136712, 136721, 136864, 136891, 136892, 136893, 136896, 136979,
                137063, 137267, 137340, 137685, 137922, 137934, 138095, 138494, 138650, 138864,
                138878, 139258, 139476, 139649, 139655, 139735, 139788, 139905, 139921, 140009,
                140156, 140332, 140461, 140541, 140630, 140701, 140711, 140776, 140795, 140797,
                140916, 141055, 141122, 141458, 141562, 141755, 141780, 141826, 141944, 142031,
                142081, 142145, 142369, 142508, 142559, 142598, 142664, 142761, 142833, 142839,
                142871, 142898, 143050, 143121, 143123, 143140, 143185, 143196, 143236, 143239,
                143255, 143256, 143259, 143302, 143309, 143330, 143331, 143354, 143387, 143388,
                143390, 143394, 143425, 143426, 143433, 143537,
            ]])
            ->andWhere(['NOT', ['b.uu_bill_id' => null]])
            ->with('lines')
            ->orderBy(['i.id' => SORT_ASC])
            ->all();

        echo sprintf(
            '%-10s %-10s %-22s %-8s %-14s %-22s %-8s %-12s %s',
            'client_id', 'invoice_id', 'feb_bill_no', 'type_id', 'sum', 'jan_bill_no', 'reversal', 'contragent_id', 'contragent'
        ) . PHP_EOL . str_repeat('-', 145) . PHP_EOL;

        $toFix = [];

        foreach ($febInvoices as $febInvoice) {
            // Строки февральской с/ф за январь, только с заданным line_id
            $febJanLineIds = [];
            foreach ($febInvoice->lines as $l) {
                if ($l->line_id && $l->date_from >= $janFrom && $l->date_from <= $janTo) {
                    $febJanLineIds[$l->line_id] = true;
                }
            }

            if (!$febJanLineIds) {
                continue;
            }

            $clientId = $febInvoice->bill->client_id;

            // Автоматический январский счёт того же ЛС
            $janBill = Bill::find()
                ->where(['client_id' => $clientId, 'bill_date' => $janDate])
                ->andWhere(['NOT', ['uu_bill_id' => null]])
                ->one();

            if (!$janBill) {
                continue;
            }

            // Все с/ф в январском счёте
            $janInvoices = Invoice::find()
                ->where(['bill_no' => $janBill->bill_no])
                ->with('lines')
                ->all();

            // Ищем совпадение хотя бы одной строки по line_id
            $matchFound = false;
            foreach ($janInvoices as $janInvoice) {
                foreach ($janInvoice->lines as $janLine) {
                    if ($janLine->line_id && isset($febJanLineIds[$janLine->line_id])) {
                        $matchFound = true;
                        break 2;
                    }
                }
            }

            if (!$matchFound) {
                continue;
            }

            $hasReversal = Invoice::find()
                ->where(['bill_no' => $febInvoice->bill_no, 'type_id' => $febInvoice->type_id, 'is_reversal' => 1])
                ->exists();

            $contragent = $febInvoice->bill->clientAccountModel->contragent;

            echo sprintf(
                '%-10s %-10s %-22s %-8s %-14s %-22s %-8s %-12s %s',
                $clientId,
                $febInvoice->id,
                $febInvoice->bill_no,
                $febInvoice->type_id,
                $febInvoice->sum,
                $janBill->bill_no,
                $hasReversal ? 'есть' : 'НЕТ',
                $contragent->id ?? '',
                $contragent->name ?? ''
            );

            if (!$hasReversal) {
                $toFix[] = $febInvoice->id;
                echo ' <-- сторнировать';
            }

            echo PHP_EOL;
        }

        echo PHP_EOL . 'Нужно сторнировать: ' . count($toFix) . PHP_EOL;

        if ($isReal && $toFix) {
            foreach ($toFix as $invoiceId) {
                $inv = Invoice::findOne($invoiceId);
                Invoice::dao()->stornoInvoice($inv);
                echo 'invoice_id=' . $invoiceId . ' сторнирован' . PHP_EOL;
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
