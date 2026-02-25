<?php

namespace app\modules\sbisTenzor\services;

use app\exceptions\ModelValidationException;
use app\models\Invoice;
use app\modules\sbisTenzor\classes\SBISDocumentStatus;
use app\modules\sbisTenzor\classes\SBISGeneratedDraftStatus;
use app\modules\sbisTenzor\forms\document\ViewForm;
use app\modules\sbisTenzor\models\SBISDocument;
use app\modules\sbisTenzor\models\SBISGeneratedDraft;

class DocumentRecreateService
{
    /**
     * ReCreate document
     *
     * @param int $id
     * @return int
     * @throws ModelValidationException
     * @throws \Exception
     */
    public static function recreate($id)
    {
        $originalDocument = SBISDocument::findOne(['id' => $id]);
        if (!$originalDocument) {
            throw new \InvalidArgumentException('Документ не найден');
        }

        if (!ViewForm::getShowReCreateButton_st($originalDocument)) {
            throw new \LogicException('Документ не может быть пересоздан');
        }

        $transaction = SBISDocument::getDb()->beginTransaction();
        try {
            $draft = SBISGeneratedDraft::findOne(['sbis_document_id' => $id]);
            if (!$draft) {
                throw new \LogicException('Черновик для пакета не найден');
            }

            $draftInvoice = $draft->invoice;
            if (!$draftInvoice) {
                throw new \LogicException('Связанный с черновиком закрывающий документ не найден');
            }

            // При пересоздании берем актуальный (последний) инвойс, если менеджер создал новый.
            $actualInvoice = Invoice::find()
                ->where([
                    'bill_no' => $draftInvoice->bill_no,
                    'type_id' => $draftInvoice->type_id,
                    'is_reversal' => 0,
                ])
                ->orderBy(['id' => SORT_DESC])
                ->one();
            if (!$actualInvoice) {
                throw new \LogicException('Актуальный закрывающий документ не найден');
            }

            if ($actualInvoice->id !== $draftInvoice->id) {
                // Если для нового инвойса уже есть свой draft, используем его.
                $actualDraft = SBISGeneratedDraft::findOne(['invoice_id' => $actualInvoice->id]);
                if ($actualDraft) {
                    $draft = $actualDraft;
                } else {
                    // Иначе переносим текущий draft на актуальный инвойс.
                    $draft->invoice_id = $actualInvoice->id;
                    $draft->populateRelation('invoice', $actualInvoice);
                }
            }

            $draft->sbis_document_id = null;
            $draft->state = SBISGeneratedDraftStatus::PROCESSING;
            if (!$draft->save()) {
                throw new ModelValidationException($draft);
            }

            $originalDocument->setState(SBISDocumentStatus::CANCELLED);
            if (!$originalDocument->save()) {
                throw new ModelValidationException($originalDocument);
            }

            $document = $draft->generateDocument();

            $transaction->commit();

            return $document->id;
        } catch (\Exception $e) {
            $transaction->rollBack();

            \Yii::$app->session->addFlash('error', $e->getTraceAsString());
            throw $e;
        }
    }
}
