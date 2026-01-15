<?php

namespace app\classes\behaviors;

use app\classes\Assert;
use app\models\BillDocument;
use app\models\EventQueue;
use app\models\Invoice;
use yii\base\Behavior;
use yii\db\ActiveRecord;
use yii\db\AfterSaveEvent;


class InvoiceGeneratePdf extends Behavior
{
    /**
     * @return array
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_INSERT => "doCheck",
            ActiveRecord::EVENT_BEFORE_UPDATE => "doCheck",
        ];
    }

    /**
     * @param $event
     * @throws \app\exceptions\ModelValidationException
     * @throws \yii\base\Exception
     */
    public function doCheck($event)
    {
        /** @var Invoice $invoice */
        $invoice = $event->sender;

        $isNewRecord = $event instanceof AfterSaveEvent;

        // новая с номером
        // или номер установлен при обновлении
        if (
            ($isNewRecord && $invoice->number)
            || (!$isNewRecord && $invoice->number && !$invoice->getOldAttribute('number'))
        ) {
            if ($invoice->is_invoice) {
                EventQueue::go(EventQueue::INVOICE_GENERATE_PDF, ['id' => $invoice->id, 'document' => BillDocument::TYPE_INVOICE]);
            }

            if ($invoice->is_upd2) {
                EventQueue::go(EventQueue::INVOICE_GENERATE_PDF, ['id' => $invoice->id, 'document' => BillDocument::TYPE_UPD2]);
            }

            if ($invoice->is_act) {
                EventQueue::go(EventQueue::INVOICE_GENERATE_PDF, ['id' => $invoice->id, 'document' => BillDocument::TYPE_ACT]);
            }
        }
    }

    /**
     * @param int $invoiceId
     * @param string $document
     */
    public static function generate($invoiceId, $document)
    {
        $invoice = Invoice::findOne(['id' => $invoiceId]);

        Assert::isObject($invoice);

        $invoice->generatePdfFile($document);
    }
}