<?php

namespace app\dao;

use app\classes\ActOfReconciliation;
use app\classes\Assert;
use app\classes\Singleton;
use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\Bill;
use app\models\BillDocument;
use app\models\BillLine;
use app\models\EventQueue;
use app\models\Invoice;

/**
 * @method static InvoiceDao me($args = null)
 */
class InvoiceDao extends Singleton
{
    const ACTION_DRAFT = 0;
    const ACTION_EDIT = 1;
    const ACTION_DELETE = 2;
    const ACTION_REGISTER = 3;
    const ACTION_STORNO = 4;

    static $names = [
        self::ACTION_DRAFT => 'Создать драфт',
        self::ACTION_EDIT => 'Редактировать с/ф',
        self::ACTION_DELETE => 'Удалить с/ф',
        self::ACTION_REGISTER => 'Зарегистрировать с/ф',
        self::ACTION_STORNO => 'Сторнировать с/ф',
    ];

    /** @var Invoice */
    private $invoice = null;

    public function checkAction(Bill $bill, $typeId, $action)
    {
        if (!$this->hasAction($bill, $typeId, $action)) {
            throw new \LogicException('Нельзя "' . self::$names[$action] . '"');
        }

        return true;
    }

    public function hasAction(Bill $bill, $typeId, $action)
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::find()
            ->where([
                'bill_no' => $bill->bill_no,
                'type_id' => $typeId
            ])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        $this->invoice = $invoice;

        switch ($action) {
            case self::ACTION_DRAFT:
                return !$invoice || $invoice->is_reversal;

            case self::ACTION_EDIT:
            case self::ACTION_DELETE:
                return $invoice && !$invoice->number;

            case self::ACTION_REGISTER:
                return $invoice && !$invoice->number && $invoice->sum >= 0;

            case self::ACTION_STORNO:
                return $invoice && $invoice->number && !$invoice->is_reversal;

            default:
                throw new \InvalidArgumentException('Неизвестное действие');
        }
    }

    public function actionDraft(Bill $bill, $typeId)
    {
        Assert::isObject($bill);

        $this->checkAction($bill, $typeId, self::ACTION_DRAFT);

        if ($bill->isCorrectionType()) {
            throw new \InvalidArgumentException('Этот документ - корректировка');
        }

        $lines = $bill->getLinesByTypeId($typeId);


        if (!$lines) {
            // не данных по этому типу документов
            throw new \LogicException('Счет пустой');
        }

        $sumData = BillLine::getSumsLines($lines);
        $invoiceDate = Invoice::getDate($bill, $typeId);

        if (!$invoiceDate) {
            if ($bill->is1C()) {
                throw new \LogicException('Для создания с/ф требуется что бы была произведена отгрузка');
            } else {
                throw new \LogicException('Для создания авансовой с/ф требуется что бы по счету была оплата');
            }
        }

        $invoice = new Invoice();
        $invoice->isSetDraft = true;

        $invoice->bill_no = $bill->bill_no;
        $invoice->type_id = $typeId;

        $invoice->date = $invoiceDate->format(DateTimeZoneHelper::DATE_FORMAT);
        $invoice->is_reversal = 0;

        $invoice->original_sum = $invoice->sum = $sumData['sum'];
        $invoice->original_sum_tax = $invoice->sum_tax = $sumData['sum_tax'];
        $invoice->sum_without_tax = $sumData['sum_without_tax'];

        if (!$invoice->save()) {
            throw new ModelValidationException($invoice);
        }

        return $invoice;
    }

    public function actionDelete(Bill $bill, $typeId)
    {
        Assert::isObject($bill);

        $this->checkAction($bill, $typeId, self::ACTION_DELETE);

        $invoice = $this->invoice;

        if (!$invoice) {
            throw new \InvalidArgumentException('Invoice not found');
        }

        if (!$invoice->delete()) {
            throw new ModelValidationException($invoice);
        }
    }

    public function actionRegister(Bill $bill, $typeId)
    {
        Assert::isObject($bill);

        $this->checkAction($bill, $typeId, self::ACTION_REGISTER);

        $invoice = $this->invoice;

        if (!$invoice) {
            throw new \InvalidArgumentException('Invoice not found');
        }

        $invoice->isSetDraft = false; // already set false

        if (!$invoice->save()) {
            throw new ModelValidationException($invoice);
        }
    }

    public function actionStorno(Bill $bill, $typeId)
    {
        Assert::isObject($bill);

        $this->checkAction($bill, $typeId, self::ACTION_STORNO);

        $invoice = $this->invoice;

        if (!$invoice) {
            throw new \InvalidArgumentException('Invoice not found');
        }

        $this->stornoInvoice($invoice);
    }

    /**
     * Создание сторнирующей с/ф для переданного инвойса.
     *
     * @param Invoice $invoice
     */
    public function stornoInvoice(Invoice $invoice)
    {
        $revertInvoice = new Invoice();
        $revertInvoice->setAttributes($invoice->getAttributes(null, ['id', 'add_date', 'number', 'idx', 'reversal_date']), false);
        $revertInvoice->setReversal(true);
    }

    public function massGenerate(EventQueue $event)
    {
        $query = Bill::find()
            ->alias('b');

        $now = (new \DateTimeImmutable())
            ->setTime(0, 0, 0);

        $from = $now->modify('first day of previous month');
        $to = $now->modify('last day of this month');

        $query->andWhere([
            'between',
            'b.bill_date',
            $from->format(DateTimeZoneHelper::DATE_FORMAT),
            $to->format(DateTimeZoneHelper::DATE_FORMAT)
        ])
            ->andWhere(['>', 'sum', 0]);

        $event->log_error = 'Count all: ' . $query->count(). PHP_EOL;
        $event->save();


        /** @var Bill $bill */
        $count = 0;
        foreach ($query->each() as $bill) {
            if ($count++ % 10 == 0) {
                $event->log_error .= 'count: ' . $count . PHP_EOL;
                $event->save();
            }
            try {
                $bill->generateInvoices();
            } catch (\Exception $e) {
                $event->log_error .= 'Error';
                $event->save();

                echo PHP_EOL . $e->getMessage();
                echo PHP_EOL;
            }
        }

        ActOfReconciliation::me()->saveBalances();

        $event->log_error .= 'Done';
        $event->save();
    }

    public function getDocumentUrlData($printDocId, $bill_no, $isStamp, $isPdf, $invoiceId = null)
    {
        if ($printDocId == 'upd2-1') {
            $invoiceTypeId = Invoice::TYPE_1;
        } elseif ($printDocId == 'upd2-2') {
            $invoiceTypeId = Invoice::TYPE_2;
        } elseif ($printDocId == 'upd2-3') {
            $invoiceTypeId = Invoice::TYPE_GOOD;
        } else {
            return false;
        }

        /** @var Invoice $invoiceObject */
        $invoiceObject = null;
        if ($invoiceId) {
            $invoiceObject = Invoice::findOne(['id' => $invoiceId]);

            if (
                !$invoiceObject
                || $invoiceObject->bill_no !== $bill_no
                || (int)$invoiceObject->type_id !== (int)$invoiceTypeId
            ) {
                $invoiceObject = null;
            }
        }

        if (!$invoiceObject) {
            $invoiceObject = Invoice::find()
                ->where(['bill_no' => $bill_no, 'type_id' => $invoiceTypeId])
                ->orderBy(['id' => SORT_DESC])
                ->one();
        }

        if (!$invoiceObject) {
            return false;
        }

        $printObject = $invoiceObject->getDocumentLinkData(BillDocument::TYPE_UPD2, $isStamp, $isPdf);

        return $printObject;

    }
}
