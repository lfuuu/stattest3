<?php

namespace app\models;

use app\classes\behaviors\ClientChangeNotifier;
use app\classes\behaviors\ClientContractComments;
use app\classes\behaviors\ContractContragent;
use app\classes\behaviors\EffectiveVATRate;
use app\classes\behaviors\LkWizardClean;
use app\classes\behaviors\ModelLifeRecorder;
use app\classes\behaviors\SetClientContractOfferDate;
use app\classes\behaviors\SetOldStatus;
use app\classes\behaviors\SetTaxVoip;
use app\classes\media\ClientMedia;
use app\classes\model\HistoryActiveRecord;
use app\dao\ClientContractDao;
use app\exceptions\ModelValidationException;
use app\helpers\SetFieldTypeHelper;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\Expression;

/**
 * Class ClientContract
 *
 * @property int $id
 * @property int $super_id
 * @property int $contragent_id
 * @property string $number
 * @property int $organization_id
 * @property string $manager
 * @property string $account_manager
 * @property int $business_id
 * @property int $business_process_id
 * @property int $business_process_status_id
 * @property int $contract_type_id
 * @property string $state
 * @property string $financial_type
 * @property string $federal_district
 * @property string $is_external
 * @property int $is_lk_access
 * @property int $is_voip_with_tax
 * @property int $is_partner_login_allow - флаг, разрешающий партнёру-родителю вход в ЛК текущего клиента
 * @property int $partner_contract_id
 * @property string $offer_date
 *
 * @property-read ClientContragent $contragent
 * @property-read ClientContragent clientContragent - напрямую
 * @property-read ClientAccount[] $accounts
 * @property-read ClientAccount[] $clientAccountModels
 * @property-read Organization $organization
 * @property-read ClientMedia $mediaManager
 * @property-read ContractType $contractType
 * @property-read User $accountManagerUser
 * @property-read Business $business
 * @property-read BusinessProcess $businessProcess
 * @property-read BusinessProcessStatus $businessProcessStatus
 * @property-read ClientDocument $contractInfo
 * @property-read ClientSuper $super
 * @property-read ClientContact $partnerContract
 * @property-read LkWizardState $lkWizardState
 *
 * @property-read string $managerName
 * @property-read string $accountManagerName
 * @property-read string $created_at
 * @property-read string $updated_at
 */
class ClientContract extends HistoryActiveRecord
{
    const STATE_UNCHECKED = 'unchecked';
    const STATE_OFFER = 'offer';
    const STATE_CHECKED_ORIGINAL = 'checked_original';
    const STATE_CHECKED_COPY = 'checked_copy';

    const FINANCIAL_TYPE_EMPTY = '';
    const FINANCIAL_TYPE_PROFITABLE = 'profitable';
    const FINANCIAL_TYPE_CONSUMABLES = 'consumables';
    const FINANCIAL_TYPE_YIELD_CONSUMABLE = 'yield-consumable';

    const IS_EXTERNAL = 'external';
    const IS_INTERNAL = 'internal';

    const IS_LK_ACCESS_YES = 1;
    const IS_LK_ACCESS_NO = 0;

    const TRIGGER_RESET_TAX_VOIP = 'trigger_reset_tax_voip';

    const ID_DANYCOM_TRASH = 100000;

    public $newClient = null;

    public $isSetVoipWithTax = null;

    public static $states = [
        self::STATE_UNCHECKED => 'Не проверено',
        self::STATE_OFFER => 'Оферта',
        self::STATE_CHECKED_ORIGINAL => 'Оригинал',
        self::STATE_CHECKED_COPY => 'Копия',
    ];

    public static $districts = [
        'cfd' => 'ЦФО',
        'sfd' => 'ЮФО',
        'nwfd' => 'СЗФО',
        'dfo' => 'ДФО',
        'sfo' => 'СФО',
        'ufo' => 'УФО',
        'pfo' => 'ПФО',
    ];

    public static $financialTypes = [
        self::FINANCIAL_TYPE_EMPTY => 'Не задано',
        self::FINANCIAL_TYPE_PROFITABLE => 'Доходный',
        self::FINANCIAL_TYPE_CONSUMABLES => 'Расходный',
        self::FINANCIAL_TYPE_YIELD_CONSUMABLE => 'Доходно-расходный',
    ];

    public static $externalType = [
        self::IS_EXTERNAL => 'Внешний',
        self::IS_INTERNAL => 'Внутренний',
    ];

    public static $lkAccess = [
        self::IS_LK_ACCESS_YES => 'Да',
        self::IS_LK_ACCESS_NO => 'Нет',
    ];

    public static $neutralBPSids = [
        16, 33, 94, 108, 125, 140, 161, 152 // Действующий // заказ магазина // Разовый // Формальные
    ];

    public
        $attributesAllowedForVersioning = [
        'contragent_id',
        'organization_id',
        'business_id',
        'business_process_id',
        'business_process_status_id',
    ];

    /**
     * @return string
     */
    public static function tableName()
    {
        return 'client_contract';
    }

    /**
     * @return array
     */
    public function attributeLabels()
    {
        return [
            'number' => '№ договора',
            'organization_id' => 'Организация',
            'manager' => 'Менеджер',
            'account_manager' => 'Аккаунт менеджер',
            'business_process_id' => 'Бизнес процесс',
            'business_process_status_id' => 'Статус бизнес процесса',
            'business_id' => 'Подразделение',
            'contract_type_id' => 'Тип договора',
            'state' => 'Статус договора',
            'financial_type' => 'Финансовый тип договора',
            'federal_district' => 'Федеральный округ (ФО)',
            'contragent_id' => 'Контрагент',
            'is_external' => 'Внешний договор',
            'is_lk_access' => 'Доступ к ЛК',
            'is_partner_login_allow' => 'Доступ партнеру в ЛК',
            'is_voip_with_tax' => 'Тарифы телефонии с НДС',
            'partner_contract_id' => 'Партнер',
        ];
    }

    /**
     * @return array
     */
    public function behaviors()
    {
        return [
            'ContractContragent' => ContractContragent::class,
            'LkWizardClean' => LkWizardClean::class,
            'SetOldStatus' => SetOldStatus::class,
            'ClientContractComments' => ClientContractComments::class,
            'EffectiveVATRate' => EffectiveVATRate::class,
            'SetTaxVoip' => SetTaxVoip::class,
            'SetClientContractOfferDate' => SetClientContractOfferDate::class,
            'ImportantEvents' => \app\classes\behaviors\important_events\ClientContract::class,
            'HistoryChanges' => \app\classes\behaviors\HistoryChanges::class,
            'ClientChangeNotifier' => ClientChangeNotifier::class,
            'TimestampBehavior' => [
                // Установить "когда создал" и "когда обновил"
                'class' => TimestampBehavior::class,
                'value' => new Expression('UTC_TIMESTAMP()'), // "NOW() AT TIME ZONE 'utc'" (PostgreSQL) или 'UTC_TIMESTAMP()' (MySQL)
            ],
            'ModelLifeRec' => [
                'class' => ModelLifeRecorder::class,
                'modelName' => 'contract',
            ]
        ];
    }

    /**
     * @return \app\dao\ClientContractDao
     */
    public static function dao()
    {
        return ClientContractDao::me();
    }

    /**
     * Получаем ID бпстатусов, считающихся закрытыми
     *
     * @return array
     */
    public static function getOffBpsIds()
    {
        return BusinessProcessStatus::find()
            ->select('id')
            ->where(['is_off_stage' => 1])
            ->column();
    }


    /**
     * @param bool $runValidation
     * @param string[] $attributeNames
     * @return bool
     */
    public function save($runValidation = true, $attributeNames = null)
    {
        if (is_array($this->federal_district)) {
            $this->federal_district = SetFieldTypeHelper::generateFieldValue($this, 'federal_district',
                $this->federal_district, false);
        }

        return parent::save($runValidation, $attributeNames);
    }

    public function beforeSave($isInsert)
    {
        if ($isInsert) {
            $this->id = ClientAccount::getMaxId() + 1;
        }

        return parent::beforeSave($isInsert);
    }

    /**
     * @param bool $insert
     * @param array $changedAttributes
     * @throws \yii\base\Exception
     */
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);
        if ($insert) {
            $contragent = ClientContragent::findOne($this->contragent_id);
            $client = new ClientAccount();
            $client->id = $this->id;
            $client->contract_id = $this->id;
            $client->super_id = $this->super_id;
            $client->country_id = $contragent->country_id;
            $client->currency = Currency::defaultCurrencyByCountryId($contragent->country_id);
            $client->is_active = 0;
            $client->client = '';
            $client->sale_channel = 0;
            $client->consignee = '';
            $client->client = 'id' . $client->id;
            if (!$client->save()) {
                throw new ModelValidationException($client);
            }

            $this->newClient = $client;
            $this->number = (string)$client->id;
            if (!$this->save()) {
                throw new ModelValidationException($this);
            }
        }

        foreach ($this->getAccounts() as $account) {
            $account->sync1C();
        }
    }

    /**
     * Документ, на основании которого действуем. Используется для выставления счетов и документов
     *
     * @param \DateTime|null $date
     * @return null|ClientDocument
     * @throws \yii\base\Exception
     * @throws \Exception
     */
    public function getContractInfo(\DateTime $date = null)
    {
        return ClientContractDao::me()->getContractInfo($this, $date);
    }

    /**
     * @return string
     */
    public function getManagerName()
    {
        $m = User::findByUsername($this->manager);
        return ($m) ? $m->name : $this->manager;
    }

    /**
     * @return string
     */
    public function getAccountManagerName()
    {
        $m = $this->accountManagerUser;
        return ($m) ? $m->name : $this->account_manager;
    }

    /**
     * @return string
     */
    public function getManagerColor()
    {
        $m = User::findByUsername($this->manager);
        return ($m) ? $m->color : '';
    }

    /**
     * @return string
     */
    public function getAccountManagerColor()
    {
        $m = User::findByUsername($this->account_manager);
        return ($m) ? $m->color : '';
    }

    /**
     * @return ActiveQuery
     */
    public function getBusiness()
    {
        return $this->hasOne(Business::class, ['id' => 'business_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountManagerUser()
    {
        return $this->hasOne(User::class, ['user' => 'account_manager']);
    }

    /**
     * @return ActiveQuery
     */
    public function getBusinessProcess()
    {
        return $this->hasOne(BusinessProcess::class, ['id' => 'business_process_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getBusinessProcessStatus()
    {
        return $this->hasOne(BusinessProcessStatus::class, ['id' => 'business_process_status_id']);
    }

    /**
     * @param string $date
     * @return Organization
     */
    public function getOrganization($date = '')
    {
        $date = $date ?: ($this->getHistoryVersionRequestedDate() ?: null);
        /** @var Organization $organization */
        $organization = Organization::find()->byId($this->organization_id)->actual($date)->one();
        return $organization;
    }

    /**
     * @return ActiveQuery
     */
    public function getComments()
    {
        return $this->hasMany(ClientContractComment::class, ['contract_id' => 'id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getSuper()
    {
        return $this->hasOne(ClientSuper::class, ['id' => 'super_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getLkWizardState()
    {
        return $this->hasOne(LkWizardState::class, ['contract_id' => 'id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getPartnerContract()
    {
        return $this->hasOne(ClientContact::class, ['id' => 'partner_contract_id']);
    }

    /**
     * @return null|ActiveQuery
     */
    public function getContractType()
    {
        if ($this->business_id != Business::OPERATOR && $this->business_id != Business::PARTNER) {
            return null;
        }

        return $this->hasOne(ContractType::class, ['id' => 'contract_type_id']);
    }

    /**
     * @param string $date
     * @return ClientContragent
     */
    public function getContragent($date = '')
    {
        return $this->getCachedHistoryModel(ClientContragent::class, $this->contragent_id, $date, $this);
    }

    /**
     * @return ActiveQuery
     */
    public function getClientContragent()
    {
        return $this->hasOne(ClientContragent::class, ['id' => 'contragent_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getClientAccountModels()
    {
        return $this->hasMany(ClientAccount::class, ['contract_id' => 'id']);
    }

    /**
     * @param bool $isFromHistory
     * @return ClientAccount[]|array
     */
    public function getAccounts($isFromHistory = true)
    {
        return $this->getCachedHistoryModels(
            ClientAccount::class,
            'contract_id',
            $this->id,
            ($isFromHistory ? $this->getHistoryVersionRequestedDate() : null),
            $this
        );
    }

    /**
     * @return ClientMedia
     */
    public function getMediaManager()
    {
        return new ClientMedia($this);
    }

    /**
     * @return ClientDocument[]
     */
    public function getAllDocuments()
    {
        return ClientDocument::find()
            ->andWhere(['contract_id' => $this->id, 'type' => [ClientDocument::DOCUMENT_AGREEMENT_TYPE, ClientDocument::DOCUMENT_CONTRACT_TYPE]])
            ->all();
    }

    /**
     * @return ClientDocument
     */
    public function getDocument()
    {
        /** @var ClientDocument $clientDocument */
        $clientDocument = ClientDocument::find()
            ->contractId($this->id)
            ->active()
            ->contract()
            ->orderBy(['id' => SORT_DESC])
            ->one();
        return $clientDocument;
    }

    /**
     * @param string $usageType
     * @return ClientContractReward[]
     */
    public function getRewards($usageType = null)
    {
        $link = $this->hasMany(ClientContractReward::class, ['contract_id' => 'id']);

        if (!is_null($usageType)) {
            $link->andWhere(['usage_type' => $usageType]);
        }

        $link->orderBy(['id' => SORT_DESC]);

        return $link->all();
    }

    /**
     * @return array
     */
    public function getFederalDistrictAsArray()
    {
        return SetFieldTypeHelper::getFieldValue($this, 'federal_district');
    }

    /**
     * @return array
     */
    public function statusesForChange()
    {
        if (!$this->state || $this->state == self::STATE_UNCHECKED || \Yii::$app->user->can('clients.changeback_contract_state')) {
            return self::$states;
        }

        if ($this->state == self::STATE_CHECKED_ORIGINAL) {
            return [self::STATE_CHECKED_ORIGINAL => self::$states[self::STATE_CHECKED_ORIGINAL]];
        }

        if ($this->state == self::STATE_CHECKED_COPY) {
            return [
                self::STATE_CHECKED_COPY => self::$states[self::STATE_CHECKED_COPY],
                self::STATE_CHECKED_ORIGINAL => self::$states[self::STATE_CHECKED_ORIGINAL],
            ];
        }

        if ($this->state == self::STATE_OFFER) {
            return [
                self::STATE_OFFER => self::$states[self::STATE_OFFER],
                self::STATE_CHECKED_COPY => self::$states[self::STATE_CHECKED_COPY],
                self::STATE_CHECKED_ORIGINAL => self::$states[self::STATE_CHECKED_ORIGINAL],
            ];
        }

        return [];
    }

    /**
     * @return bool
     */
    public function isPartner()
    {
        return $this->business_id == Business::PARTNER;
    }

    /**
     * @return int
     */
    public function isPartnerAgent()
    {
        return $this->partner_contract_id;
    }

    /**
     * Рассчитывает эффективную ставку НДС для данного договора
     *
     * @param bool $isWithTrace
     * @return array
     */
    public function resetEffectiveVATRate($isWithTrace = true)
    {
        return self::dao()->resetEffectiveVATRate($this, $isWithTrace);
    }

    /**
     * Рассчитывает необходимость использования тарифов с НДС или без НДС
     *
     * @param ClientContragent $contragent
     */
    public function resetTaxVoip(ClientContragent $contragent)
    {
        self::dao()->resetTaxVoip($this, $contragent);
    }

    public static function prepareHistoryValue($field, $value)
    {
        switch ($field) {
            case 'manager':
            case 'account_manager':
                return User::find()->where(['user' => $value])->select('name')->scalar();

            default:
                return parent::prepareHistoryValue($field, $value);
        }
    }
}
