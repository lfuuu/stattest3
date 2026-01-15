<?php

namespace app\modules\sbisTenzor\models;

use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\important_events\ImportantEvents;
use app\models\important_events\ImportantEventsNames;
use app\models\important_events\ImportantEventsSources;
use app\models\Invoice;
use app\classes\model\ActiveRecord;
use app\modules\sbisTenzor\classes\ContractorInfo;
use app\modules\sbisTenzor\classes\SBISDocumentManager;
use app\modules\sbisTenzor\classes\SBISGeneratedDraftStatus;
use app\modules\sbisTenzor\exceptions\SBISTensorException;
use app\modules\sbisTenzor\helpers\SBISDataProvider;
use app\modules\sbisTenzor\helpers\SBISInfo;
use DateTime;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
use yii\helpers\Url;

/**
 * Сгенерированный черновик пакета документов для отправки в СБИС
 *
 * @property integer $id
 * @property integer $state
 * @property integer $invoice_id
 * @property integer $sbis_document_id
 * @property string $warnings
 * @property string $errors
 * @property string $created_at
 * @property string $updated_at
 *
 * @property-read SBISDocument $document
 * @property-read Invoice $invoice
 */
class SBISGeneratedDraft extends ActiveRecord
{
    protected static $eventsDelayed = [];

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'sbis_generated_draft';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['invoice_id', 'state'], 'required'],
            [['invoice_id', 'sbis_document_id', 'state'], 'integer'],
            [['created_at', 'updated_at'], 'safe'],
            [['warnings', 'errors'], 'string'],
            [['invoice_id'], 'unique'],
            [['invoice_id'], 'exist', 'skipOnError' => true, 'targetClass' => Invoice::class, 'targetAttribute' => ['invoice_id' => 'id']],
            [['sbis_document_id'], 'exist', 'skipOnError' => true, 'targetClass' => SBISDocument::class, 'targetAttribute' => ['sbis_document_id' => 'id']],
        ];
    }

    /**
     * @inheritdoc
     */
    public static function getDb()
    {
        return Yii::$app->db;
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'state' => 'Статус',
            'invoice_id' => 'Закрывающий документ',
            'sbis_document_id' => 'Пакет документов',
            'warnings' => 'Предупреждения',
            'errors' => 'Ошибки',
            'created_at' => 'Добавлен',
            'updated_at' => 'Обновлён',
        ];
    }

    /**
     * @return array
     */
    public function behaviors()
    {
        return [
            [
                // Установить "когда создал" и "когда обновил"
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => 'updated_at',
                'value' => new Expression("UTC_TIMESTAMP()"), // "NOW() AT TIME ZONE 'utc'" (PostgreSQL) или 'UTC_TIMESTAMP()' (MySQL)
            ],
        ];
    }

    /**
     * Добавить ошибку в лог
     *
     * @param $errorText
     */
    public function addErrorText($errorText)
    {
        Yii::error(
            sprintf('SBISGeneratedDraft #%s: %s', $this->id, $errorText),
            SBISDocument::LOG_CATEGORY
        );

        $now = new DateTime('now');
        $this->errors .=
            ($this->errors ? PHP_EOL : '') .
            sprintf('%s: %s', $now->format(DateTimeZoneHelper::DATETIME_FORMAT), $errorText);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getDocument()
    {
        return $this->hasOne(SBISDocument::class, ['id' => 'sbis_document_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getInvoice()
    {
        return $this->hasOne(Invoice::class, ['id' => 'invoice_id']);
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return Url::to(['/sbisTenzor/draft/view', 'id' => $this->id]);
    }

    /**
     * @param string $insert
     * @param array $changedAttributes
     * @throws ModelValidationException
     * @throws \yii\db\Exception
     */
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        $this->raiseEventsDelayed();
    }

    /**
     * @return SBISDocument
     * @throws ModelValidationException
     * @throws SBISTensorException
     * @throws \Exception
     */
    public function generateDocument()
    {
        if ($this->state != SBISGeneratedDraftStatus::PROCESSING) {
            throw new SBISTensorException('Черновик в неверном статусе: ' . SBISGeneratedDraftStatus::getById($this->state), 500);
        }

        $client = $this->invoice->bill->clientAccount;
        $documentManager = new SBISDocumentManager($client, $this->invoice);
        $documentManager->saveDocument();
        $document = $documentManager->getDocument();

        $this->sbis_document_id = $document->id;
        $this->state = SBISGeneratedDraftStatus::DONE;
        if (!$this->save()) {
            throw new ModelValidationException($this);
        }

        return $document;
    }

    /**
     * Параметры для события
     *
     * @return array
     */
    public function getInfo()
    {
        $client = $this->invoice->bill->clientAccount;
        $organization = $this->invoice->organization;

        $sbisOrganization = SBISDataProvider::getSBISOrganizationByClient($client, $organization);
        $draftFrom = $sbisOrganization ? strval($sbisOrganization->organization->name) : '?';

        $invoiceHas = [];
        if ($this->invoice->is_act) {
            $invoiceHas[] = 'Акт';
        }
        if ($this->invoice->is_invoice) {
            $invoiceHas[] = 'С/ф';
        }
        if ($this->invoice->is_upd2) {
            $invoiceHas[] = 'УПД';
        }

        $toGenerate = array_map(
            function ($value) {
                $form = SBISExchangeForm::findOne(['id' => $value]);
                return $form->name;
            },
            SBISInfo::getExchangeGroupsByClient($client, $sbisOrganization)
        );

        return [
            'client_id' => $client->id,
            'draft_id' => $this->id,
            'draft_from' => $draftFrom,
            'organization_name' => strval($organization->name),
            'invoice_id' => $this->id,
            'invoice_name' => sprintf('№%s от %s на сумму %s', $this->invoice->number, $this->invoice->date, $this->invoice->sum),
            'invoice_has' => implode(', ', $invoiceHas),
            'to generate' => implode(', ', $toGenerate),
        ];
    }

    /**
     * Создать событие создания
     */
    public function addCreateEvent()
    {
        self::$eventsDelayed[] = ImportantEventsNames::SBIS_DRAFT_CREATED;
    }

    /**
     * Отложенно создаём события
     *
     * @throws \yii\db\Exception
     */
    protected function raiseEventsDelayed()
    {
        foreach (self::$eventsDelayed as $event) {
            ImportantEvents::create(
                $event,
                ImportantEventsSources::SOURCE_STAT,
                $this->getInfo()
            );
        }

        self::$eventsDelayed = [];
    }

    /**
     * Проверка на возможные ошибки
     *
     * @param bool $withSaving
     * @throws ModelValidationException
     */
    public function checkForWarnings($withSaving = false)
    {
        if ($this->state != SBISGeneratedDraftStatus::DRAFT) {
            return;
        }

        $invoice = $this->invoice;
        $client = $invoice->bill->clientAccount;

        $contractorInfo = ContractorInfo::get($client, $invoice->organization);
        $this->warnings = $contractorInfo->getFullErrorText();

        if ($withSaving) {
            if (!$this->save()) {
                throw new ModelValidationException($this);
            }
        }
    }
}