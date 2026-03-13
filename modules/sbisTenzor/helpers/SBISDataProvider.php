<?php

namespace app\modules\sbisTenzor\helpers;

use app\exceptions\ModelValidationException;
use app\models\ClientAccount;
use app\models\ClientContragent;
use app\models\Invoice;
use app\models\Organization;
use app\modules\sbisTenzor\classes\SBISGeneratedDraftStatus;
use app\modules\sbisTenzor\models\SBISContractor;
use app\modules\sbisTenzor\models\SBISDocument;
use app\modules\sbisTenzor\models\SBISGeneratedDraft;
use app\modules\sbisTenzor\models\SBISOrganization;
use Yii;

class SBISDataProvider
{
    protected static $sbisOrganizations = [];

    /**
     * @param ClientAccount $client
     * @param Organization|null $organization
     * @return SBISOrganization|null
     */
    public static function getSBISOrganizationByClient(ClientAccount $client, Organization $organization = null)
    {
        $organizationId = $organization ?
            $organization->organization_id :
            $client->organization->organization_id;

        if ( !array_key_exists($organizationId, self::$sbisOrganizations) ) {
            self::$sbisOrganizations[$organizationId] = SBISOrganization::findOne([
                'organization_id' => $organizationId,
                'is_active' => true,
            ]);
        }

        return self::$sbisOrganizations[$organizationId];
    }

    /**
     * Проверить закрывающий документ на ЭДО
     *
     * @param int $invoiceId
     * @throws \Exception
     */
    public static function checkInvoiceForExchange($invoiceId)
    {
        $invoice = Invoice::findOne(['id' => $invoiceId]);
        if (!$invoice) {
            throw new \Exception(sprintf('Закрывающий документ не найден! Invoice id: %s', $invoiceId));
        }

        $client = $invoice->bill->clientAccountModel;
        if (!$client->exchange_group_id) {
            // без интеграции со СБИС
            return;
        }
        if ($invoice->is_reversal) {
            // сторнирующие не отправляем
            return;
        }

        self::createDraftForInvoice($invoice);
    }

    /**
     * Создать черновик для закрывающего документа
     *
     * @param Invoice $invoice
     * @throws ModelValidationException
     */
    protected static function createDraftForInvoice(Invoice $invoice)
    {
        $checkDraft = SBISGeneratedDraft::findOne(['invoice_id' => $invoice->id]);
        if ($checkDraft) {
            Yii::error(
                sprintf('Черновик для закрывающего документа №%s уже создан! Invoice id: %s', $invoice->number, $invoice->id),
                SBISDocument::LOG_CATEGORY
            );

            return;
        }

        $draft = new SBISGeneratedDraft();

        $draft->invoice_id = $invoice->id;
        $draft->populateRelation('invoice', $invoice);
        $draft->state = SBISGeneratedDraftStatus::DRAFT;

        $draft->checkForWarnings();
        $draft->addCreateEvent();
        if (!$draft->save()) {
            throw new ModelValidationException($draft);
        }
    }

    /**
     * Получить запись о контрагенте
     *
     * @param ClientAccount $client
     * @return SBISContractor|null
     */
    public static function getSBISContractor(ClientAccount $client)
    {
        $query =
            SBISContractor::find()
                ->limit(1);

        switch ($client->contragent->legal_type) {
            case ClientContragent::LEGAL_TYPE:
                $query
                    ->where([
                        'tin' => $client->getInn(),
                        'iec' => $client->getKpp(),
                        'branch_code' => $client->getBranchCode(),
                    ]);
                break;

            case ClientContragent::IP_TYPE:
                $query
                    ->where([
                        'itn' => $client->getInn(),
                        'is_private' => '0',
                    ]);
                break;

            case ClientContragent::PERSON_TYPE:
                $person = $client->contragent->person;
                $query
                    ->where([
                        'itn' => $person ? $person->inn : '',
                        'is_private' => '1',
                    ]);
                break;
        }

        /** @var SBISContractor $result */
        $result = $query->one();

        return $result;
    }
}