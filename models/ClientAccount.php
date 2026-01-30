<?php

namespace app\models;

use app\classes\behaviors\AccountPriceIncludeVat;
use app\classes\behaviors\ActualizeClientVoip;
use app\classes\behaviors\ClientAccountComments;
use app\classes\behaviors\ClientAccountSyncEvent;
use app\classes\behaviors\ClientChangeNotifier;
use app\classes\behaviors\EffectiveVATRate;
use app\classes\behaviors\EventQueueAddEvent;
use app\classes\behaviors\ModelLifeRecorder;
use app\classes\behaviors\SetOldStatus;
use app\classes\behaviors\SetTaxVoip;
use app\classes\BillContract;
use app\classes\DateTimeWithUserTimezone;
use app\classes\Html;
use app\classes\model\HistoryActiveRecord;
use app\classes\Utils;
use app\dao\ClientAccountDao;
use app\models\billing\Locks;
use app\models\dictionary\TrustLevel;
use app\models\voip\StatisticDay;
use app\modules\sbisTenzor\models\SBISExchangeGroup;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\ServiceType;
use app\modules\uu\models\TariffStatus;
use app\queries\ClientAccountQuery;
use DateTimeImmutable;
use DateTimeZone;
use yii\base\Exception;
use yii\db\ActiveQuery;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;

/**
 * Class ClientAccount
 *
 * @property int $id
 * @property string $client
 * @property int $super_id
 * @property int $contract_id
 * @property int $country_id
 * @property string $status
 * @property string $currency
 * @property string $nal
 * @property int $balance
 * @property int $credit
 * @property int $voip_credit_limit_day
 * @property int $voip_limit_mn_day
 * @property int $voip_disabled
 * @property int $business_id
 * @property int $price_include_vat
 * @property int $is_active
 * @property int $is_blocked
 * @property int $region
 * @property string $address_post
 * @property string $site_name
 * @property string $timezone_name
 * @property string $sale_channel
 * @property string $password
 * @property string $lk_balance_view_mode
 * @property int $account_version
 * @property int $is_postpaid
 * @property bool $type_of_bill
 * @property string $pay_acc
 * @property string $bik
 * @property string $bank_name
 * @property string $bank_city
 * @property string $bank_properties
 * @property int $admin_contact_id
 * @property int $stamp
 * @property string $corr_acc
 * @property string $address_connect
 * @property int $is_with_consignee
 * @property string $consignee
 * @property int $effective_vat_rate
 * @property int $pay_bill_until_days
 * @property int $is_bill_pay_overdue
 * @property int $is_voip_with_tax
 * @property int $price_type
 * @property int $price_level
 * @property int $uu_tariff_status_id
 * @property int $show_in_lk
 * @property int $exchange_group_id
 * @property int $exchange_status
 * @property string $bill_rename1
 * @property string $head_company_address_jur
 *
 * @property-read Currency $currencyModel
 * @property-read ClientSuper $superClient
 * @property-read ClientContractComment $lastComment
 * @property-read Country $country
 * @property-read Region $accountRegion
 * @property-read DateTimeZone $timezone
 * @property-read LkClientSettings $lkClientSettings
 * @property-read LkNoticeSetting $lkNoticeSetting
 * @property-read ClientFlag $flag
 * @property-read ClientContact[] $contacts
 * @property-read Bill[] $bills
 * @property-read Payment[] $payments
 * @property-read CounterInteropTrunk[] $counterInteropTrunks
 * @property-read ClientContract $contract  из версии
 * @property-read ClientContract $clientContractModel - напрямую
 * @property-read ClientContragent $contragent
 * @property-read Organization $organization
 * @property-read User $userAccountManager
 * @property-read LkWizardState $lkWizardState
 * @property-read ClientCounter $billingCounters
 * @property-read ClientCounter $billingCountersFastMass
 * @property-read ClientCounter $clientCounter
 * @property-read string $company_full
 * @property-read string $address_jur
 * @property-read ClientContact[] $allContacts
 * @property-read integer $businessId
 * @property-read City $city
 * @property-read AccountTariff[] $accountTariffs
 * @property-read UsageVoip[] $usageVoips
 * @property-read UsageTechCpe[] $usageTechCpes
 * @property-read UsageWelltime[] $usageWelltimes
 * @property-read UsageExtra[] $usageExtras
 * @property-read UsageVirtpbx[] $usageVirtpbxs
 * @property-read UsageSms[] $usageSmses
 * @property-read UsageIpPorts[] $usageIpPorts
 * @property-read UsageTrunk[] $usageTrunks
 * @property-read UsageCallChat[] $usageCallChats
 * @property-read TariffStatus $tariffStatus
 * @property-read SBISExchangeGroup $exchangeGroup
 * @property-read ClientAccountComment[] $comments
 * @property-read ClientAccountComment $lastAccountComment
 * @property-read integer $is_show_in_lk
 * @property-read ClientAccountOptions $options
 * @property-read EquipmentUser $equipmentUsers
 * @property-read string $address
 *
 * @method static ClientAccount findOne($condition)
 * @method static ClientAccount[] findAll($condition)
 */
class ClientAccount extends HistoryActiveRecord
{
    // Определяет getList (список для selectbox)
    use \app\classes\traits\GetListTrait {
        getList as getListTrait;
    }

    const STATUS_INCOME = 'income';
    const STATUS_NEGOTIATIONS = 'negotiations';
    const STATUS_TESTING = 'testing';
    const STATUS_CONNECTING = 'connecting';
    const STATUS_WORK = 'work';
    const STATUS_CLOSED = 'closed';
    const STATUS_TECH_DENY = 'tech_deny';
    const STATUS_TELEMARKETING = 'telemarketing';
    const STATUS_DENY = 'deny';
    const STATUS_DEBT = 'debt';
    const STATUS_DOUBLE = 'double';
    const STATUS_TRASH = 'trash';
    const STATUS_MOVE = 'move';
    const STATUS_ALREADY = 'already';
    const STATUS_DENIAL = 'denial';
    const STATUS_ONCE = 'once';
    const STATUS_RESERVED = 'reserved';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_OPERATOR = 'operator';
    const STATUS_DISTR = 'distr';
    const STATUS_BLOCKED = 'blocked';

    const VERSION_BILLER_USAGE = 4;
    const VERSION_BILLER_UNIVERSAL = 5;

    const DEFAULT_REGION = Region::MOSCOW;

    const DEFAULT_VOIP_CREDIT_LIMIT_DAY = 2000; // BIL-2081: http://rd.welltime.ru/confluence/pages/viewpage.action?pageId=14221857
    const DEFAULT_VOIP_MN_LIMIT_DAY = 1000;
    const MAX_DAY_LIMIT = 100000;

    const DEFAULT_VOIP_IS_DAY_CALC = 1;
    const DEFAULT_VOIP_IS_MN_DAY_CALC = 1;
    const DEFAULT_CREDIT = 0;
    const DEFAULT_PRICE_LEVEL = 1;
    const PRICE_LEVEL2 = 2;
    const DEFAULT_ACCOUNT_VERSION = self::VERSION_BILLER_UNIVERSAL;

    const TYPE_OF_BILL_SIMPLE = false;
    const TYPE_OF_BILL_DETAILED = true;

    const WARNING_UNAVAILABLE_BILLING = 'unavailable.billing'; // Сервер статистики недоступен. Данные о балансе и счетчиках могут быть неверными
    const WARNING_UNAVAILABLE_LOCKS = 'unavailable.locks'; // Сервер статистики недоступен. Данные о блокировках недоступны
    const WARNING_SYNC_ERROR = 'balance.sync_error'; // Ошибка синхронизации баланса
    const WARNING_FINANCE = 'lock.is_finance_block'; // Финансовая блокировка
    const WARNING_FINANCE_LAG = 'lock.is_finance_block_lag'; // Финансовая блокировка должна быть снята, но не снята. Какая то задержка
    const WARNING_OVERRAN = 'lock.is_overran'; // Превышение лимитов низкоуровневого биллинга. Возможно, взломали
    const WARNING_MN_OVERRAN = 'lock.is_mn_overran'; // Превышение лимитов низкоуровневого биллинга. Возможно, взломали (МН)
    const WARNING_LIMIT_DAY = 'lock.limit_day'; // Превышен дневной лимит
    const WARNING_CREDIT = 'lock.credit'; // Превышен лимит кредита
    const WARNING_BILL_PAY_OVERDUE = 'lock.bill_pay_overdue'; // Просрочка оплаты счета
    const WARNING_LAST_DT = 'locks_log.dt'; // Дата последней блокировки

    const SHOW_IN_LK_NEVER = 0;
    const SHOW_IN_LK_ALWAYS = 1;
    const SHOW_IN_LK_ACTIVE_ONLY = 2;

    const PAY_BILL_UNTIL_DAYS = 30;

    const UNIVERSAL_BILL_RENAME1_DATE = '2019-04-01';

    const ID_PORTED = 99999;

    const PAYMENT_TYPE_PREPAID = 0;
    const PAYMENT_TYPE_POSTPAID = 1;
    const PAYMENT_TYPE_PREPAID_2 = 2;

    const PRICE_LEVEL_B2C = [2, 3];

    public static $paymentTypes = [
        self::PAYMENT_TYPE_PREPAID => 'Prepaid',
        self::PAYMENT_TYPE_POSTPAID => 'Postpaid',
        self::PAYMENT_TYPE_PREPAID_2 => 'Prepaid 2.0',
    ];

    public static $statuses = [
        'negotiations' => ['name' => 'в стадии переговоров', 'color' => '#C4DF9B'],
        'testing' => ['name' => 'тестируемый', 'color' => '#6DCFF6'],
        'connecting' => ['name' => 'подключаемый', 'color' => '#F49AC1'],
        'work' => ['name' => 'включенный', 'color' => ''],
        'closed' => ['name' => 'отключенный', 'color' => '#FFFFCC'],
        'tech_deny' => ['name' => 'тех. отказ', 'color' => '#996666'],
        'telemarketing' => ['name' => 'телемаркетинг', 'color' => '#A0FFA0'],
        'income' => ['name' => 'входящие', 'color' => '#CCFFFF'],
        'deny' => ['name' => 'отказ', 'color' => '#A0A0A0'],
        'debt' => ['name' => 'отключен за долги', 'color' => '#C00000'],
        'double' => ['name' => 'дубликат', 'color' => '#60a0e0'],
        'trash' => ['name' => 'мусор', 'color' => '#a5e934'],
        'move' => ['name' => 'переезд', 'color' => '#f590f3'],
        'suspended' => ['name' => 'приостановленные', 'color' => '#C4a3C0'],
        'denial' => ['name' => 'отказ/задаток', 'color' => '#00C0C0'],
        'once' => ['name' => 'Интернет Магазин', 'color' => 'silver'],
        'reserved' => ['name' => 'резервирование канала', 'color' => 'silver'],
        'blocked' => ['name' => 'временно заблокирован', 'color' => 'silver'],
        'distr' => ['name' => 'Поставщик', 'color' => 'yellow'],
        'operator' => ['name' => 'Оператор', 'color' => 'lightblue']
    ];
    public static $formTypes = [
        'manual' => 'ручное',
        'bill' => 'при выставлении счета',
        'payment' => 'при внесении платежа',
    ];
    public static $nalTypes = [
        'beznal' => 'безнал',
        'nal' => 'нал',
        'prov' => 'пров'
    ];
    public static $balanceViewMode = [
        'old' => 'Старый',
        'new' => 'Новый',
    ];
    public static $versions = [
        self::VERSION_BILLER_UNIVERSAL => 'УЛС',
        self::VERSION_BILLER_USAGE => 'ЛС',
    ];

    public static $shopIds = [14050, 18042];
    public $client_orig = '';
    /**
     * Virtual variables
     */
    public $payment_info;
    public
        $attributesAllowedForVersioning = [
        'address_post',
        'address_post_real',
        'head_company',
        'head_company_address_jur',
        'consignee',
        'bik',
        'bank_properties',
        'corr_acc',
        'pay_acc',
        'bank_name',
        'bank_city',
        'bill_rename1',
    ];

    // Свойства модели которые должны обновляться версионно
    /**
     * Virtual variables
     */

    private $_lastComment = false;

    /** @var int */
    private $_accountVersionOld = null;

    /**
     * Название таблицы
     *
     * @return string
     */
    public static function tableName()
    {
        return 'clients';
    }

    /**
     * @return ClientAccountQuery
     */
    public static function find()
    {
        return new ClientAccountQuery(get_called_class());
    }

    /**
     * Правила
     *
     * @return array
     */
    public function rules()
    {
        $rules = [
            ['country_id', 'required'],
            [['country_id', 'uu_tariff_status_id', 'exchange_group_id', 'exchange_status'], 'integer'],
            ['voip_credit_limit_day', 'default', 'value' => self::DEFAULT_VOIP_CREDIT_LIMIT_DAY],
            ['voip_limit_mn_day', 'default', 'value' => self::DEFAULT_VOIP_MN_LIMIT_DAY],
            ['voip_is_day_calc', 'default', 'value' => self::DEFAULT_VOIP_IS_DAY_CALC],
            ['voip_is_mn_day_calc', 'default', 'value' => self::DEFAULT_VOIP_IS_MN_DAY_CALC],
            ['region', 'default', 'value' => self::DEFAULT_REGION],
            ['credit', 'default', 'value' => self::DEFAULT_CREDIT],
            ['account_version', 'default', 'value' => self::DEFAULT_ACCOUNT_VERSION],
            ['account_version', 'validatorAccountVersion'],
            ['pay_bill_until_days', 'default', 'value' => self::PAY_BILL_UNTIL_DAYS],
            ['price_type', 'default', 'value' => GoodPriceType::DEFAULT_PRICE_LIST],
            ['type_of_bill', 'default', 'value' => ClientAccount::TYPE_OF_BILL_DETAILED],
        ];

        return $rules;
    }

    /**
     * Поведение
     *
     * @return array
     */
    public function behaviors()
    {
        return [
            'AccountPriceIncludeVat' => AccountPriceIncludeVat::class,
            'SetOldStatus' => SetOldStatus::class,
            'ActualizeClientVoip' => ActualizeClientVoip::class,
            'ClientAccountComments' => ClientAccountComments::class,
            'ClientAccountEvent' => \app\classes\behaviors\important_events\ClientAccount::class,
            'ClientAccountSyncEvent' => ClientAccountSyncEvent::class,
            'EventQueueAddEvent' => [
                'class' => EventQueueAddEvent::class,
                'insertEvent' => EventQueue::ADD_ACCOUNT
            ],
            'EffectiveVATRate' => EffectiveVATRate::class,
            'SetTaxVoip' => SetTaxVoip::class,
            'HistoryChanges' => \app\classes\behaviors\HistoryChanges::class, // Логирование изменений всегда в конце
            'ClientChangeNotifier' => ClientChangeNotifier::class,
            'ModelLifeRec' => [
                'class' => ModelLifeRecorder::class,
                'modelName' => 'account',
            ]
        ];
    }

    /**
     * Навзание полей
     *
     * @return array
     */
    public function attributeLabels()
    {
        return [
            'comment' => 'Комментарий',
            'usd_rate_percent' => 'USD уровень в процентах',
            'address_post' => 'Почтовый адрес',
            'address_post_real' => 'Действительный почтовый адрес',
            'bik' => 'БИК',
            'bank_properties' => 'Банковские реквизиты',
            'currency' => 'Валюта',
            'stamp' => 'Печатать штамп',
            'nal' => 'Предполагаемый метод платежа',
            'sale_channel' => 'Канал продаж',
            'user_impersonate' => 'Наследовать права пользователя',
            'address_connect' => 'Предполагаемый адрес подключения',
            'phone_connect' => 'Предполагаемый телефон подключения',
            'id_all4net' => 'ID в All4Net',
            'dealer_comment' => 'Комментарий для дилера',
            'form_type' => 'Формирование с/ф',
            'credit' => 'Разрешить кредит',
            'credit_size' => 'Размер кредита',
            'corr_acc' => 'К/С',
            'pay_acc' => 'Р/С',
            'bank_name' => 'Название банка',
            'bank_city' => 'Город банка',
            'price_type' => 'Тип цены для интернет-магазина',
            'voip_disabled' => 'Выключить телефонию (МГ, МН, Местные мобильные)',
            'voip_credit_limit_day' => 'Телефония, лимит использования (день)',
            'voip_limit_mn_day' => 'Телефония (МН), лимит использования (день)',
            'balance' => 'Баланс',
            'voip_is_day_calc' => 'Пересчет дневного лимита',
            'voip_is_mn_day_calc' => 'Пересчет дневного (МН) лимита',
            'region' => 'Регион',
            'mail_who' => '"Кому" письмо',
            'head_company' => 'Головная компания',
            'head_company_address_jur' => 'Юр. адрес головной компании',
            'bill_rename1' => 'Номенклатура',
            'is_agent' => 'Агент',
            'is_with_consignee' => 'Использовать грузополучателя',
            'consignee' => 'Грузополучатель',
            'is_upd_without_sign' => 'Печать УПД без подписей',
            'is_blocked' => 'Блокировка',
            'timezone_name' => 'Часовой пояс',
            'manager' => 'Менеджер',
            'account_manager' => 'Ак. менеджер',
            'custom_properties' => 'Ввести данные вручную',
            'lk_balance_view_mode' => 'Тип отображения баланса в ЛК',
            'account_version' => 'Версия ЛС',
            'anti_fraud_disabled' => 'Отключен анти-фрод',
            'is_postpaid' => 'Тип оплаты',
            'type_of_bill' => 'Закрывающий документ (Полный)',
            'status' => 'Статус',
            'is_active' => 'Вкл.',
            'previous_reincarnation' => 'Предыдущий аккаунт',
            'password_type' => 'Тип пароля',
            'currency_bill' => 'Валюта счета',
            'balance_usd' => 'Баланс в $',
            'last_account_date' => 'Дата актуальности баланса',
            'created' => 'Дата создания',
            'mail_print' => 'Печатать письма',
            'nds_calc_method' => 'Тип расчета НДС',
            'effective_vat_rate' => 'Эффективная ставка НДС',
            'pay_bill_until_days' => 'Срок оплаты счетов (в днях)',
            'is_bill_pay_overdue' => 'Блокировка по неоплате счета',
            'price_level' => 'Уровень цен',
            'uu_tariff_status_id' => 'УУ-пакет',
            'settings_advance_invoice' => 'Настройки выставления авансовых счетов',
            'upload_to_sales_book' => 'Выгружать с/ф ЛС в книгу продаж',
            'show_in_lk' => 'Показывать ЛС в ЛК',
            'exchange_group_id' => 'Группа документов для отправки в системе СБИС',
            'exchange_status' => 'Статус интеграции с системой СБИС',
            'trust_level_id' => 'Уровень доверия',
        ];
    }

    /**
     * @return ActiveQuery
     */
    public function getSuperClient()
    {
        return $this->hasOne(ClientSuper::class, ['id' => 'super_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getBills()
    {
        return $this->hasMany(Bill::class, ['client_id' => 'id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getPayments()
    {
        return $this->hasMany(Payment::class, ['client_id' => 'id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getCurrencyModel()
    {
        return $this->hasOne(Currency::class, ['id' => 'currency']);
    }

    /**
     * @return string
     */
    public function getType()
    {
        return ($this->contract->contragent->legal_type != 'person') ? 'org' : 'person';
    }

    /**
     * @return int
     */
    public function getFirma()
    {
        return $this->contract->organization->firma;
    }

    /**
     * @return string
     */
    public function getManager()
    {
        return $this->contract->manager;
    }

    /**
     * @return mixed
     */
    public function getManager_name()
    {
        return User::find()->where(['user' => $this->contract->manager])->one()->name;
    }

    /**
     * @return string
     */
    public function getNumber()
    {
        return $this->contract->number;
    }

    /**
     * @return int
     */
    public function getBusiness_process_id()
    {
        return $this->contract->business_process_id;
    }

    /**
     * @return int
     */
    public function getBusiness_process_status_id()
    {
        return $this->contract->business_process_status_id;
    }

    /**
     * @return int
     */
    public function getBusinessId()
    {
        return $this->contract->business_id;
    }

    /**
     * @return string
     */
    public function getAccount_manager()
    {
        return $this->contract->account_manager;
    }

    /**
     * @return string
     */
    public function getCompany()
    {
        return $this->contract->contragent->name;
    }

    /**
     * @return string
     */
    public function getCompany_full()
    {
        return $this->contract->contragent->name_full;
    }

    /**
     * @return string
     */
    public function getAddress_jur()
    {
        return $this->contract->contragent->address_jur;
    }

    /**
     * @return string
     */
    public function getAddress()
    {
        if ($this->head_company_address_jur) {
            return $this->head_company_address_jur;
        }

        $contragent = $this->contragent;

        if ($contragent->post_address_filial) {
            return $contragent->post_address_filial;
        }

        return $contragent->address;
    }

    /**
     * @return string
     */
    public function getInn()
    {
        return $this->contract->contragent->inn;
    }

    /**
     * @return string
     */
    public function getKpp()
    {
        return $this->contract->contragent->kpp;
    }

    /**
     * @return string
     */
    public function getBranchCode()
    {
        return $this->contract->contragent->branch_code ? : null;
    }

    /**
     * @return string
     */
    public function getSigner_position()
    {
        return $this->contract->contragent->signer_position;
    }

    /**
     * @return string
     */
    public function getSigner_name()
    {
        return $this->contract->contragent->signer_fio;
    }

    /**
     * @return string
     */
    public function getSigner_positionV()
    {
        return $this->contract->contragent->positionV;
    }

    /**
     * @return string
     */
    public function getSigner_nameV()
    {
        return $this->contract->contragent->fioV;
    }

    /**
     * @return string
     */
    public function getOgrn()
    {
        return $this->contract->contragent->ogrn;
    }

    /**
     * @return string
     */
    public function getOkpo()
    {
        return $this->contract->contragent->okpo;
    }

    /**
     * @return string
     */
    public function getOpf()
    {
        return $this->contract->contragent->opf_id;
    }

    /**
     * @return string
     */
    public function getOkvd()
    {
        return $this->contract->contragent->okvd;
    }

    /**
     * @return string
     */
    public function getChannelName()
    {
        return $this->sale_channel ? SaleChannelOld::getList()[$this->sale_channel] : '';
    }

    /**
     * @return ActiveQuery
     */
    public function getBusiness()
    {
        return $this->hasOne(Business::class, ['id' => 'business_id']);
    }

    /**
     * @return int|string
     */
    public function getRegionName()
    {
        return $this->accountRegion ? $this->accountRegion->name : $this->region;
    }

    /**
     * @return ActiveQuery
     */
    public function getCountry()
    {
        return $this->hasOne(Country::class, ['code' => 'country_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountRegion()
    {
        return $this->hasOne(Region::class, ['id' => 'region']);
    }

    /**
     * @return User
     */
    public function getUserManager()
    {
        return User::findOne(['user' => $this->contract->manager]);
    }

    /**
     * @return ActiveQuery
     */
    public function getComments()
    {
        return $this->hasMany(ClientAccountComment::class, ['account_id' => 'id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getLastAccountComment()
    {
        return $this->hasOne(ClientAccountComment::class, ['account_id' => 'id'])->orderBy(['created_at' => SORT_DESC])->limit(1);
    }

    /**
     * @return City
     */
    public function getCity()
    {
        $region = $this->accountRegion;
        if (!$region) {
            return null;
        }

        $cities = $region->cities;
        return reset($cities);
    }

    /**
     * @param null $date
     * @return array|false
     */
    public function getLastContract($date = null)
    {
        return BillContract::getLastContract($this->contract_id, $date);
    }

    /**
     * @return User
     */
    public function getUserAccountManager()
    {
        return User::findOne(['user' => $this->contract->account_manager]);
    }

    /**
     * @return LkWizardState
     */
    public function getLkWizardState()
    {
        return LkWizardState::findOne(["contract_id" => $this->contract->id, "is_on" => 1]);
    }

    /**
     * @return ClientContragent
     */
    public function getContragent()
    {
        return $this->getContract()->getContragent();
    }

    /**
     * @param string $date
     * @return ClientContract
     */
    public function getContract($date = '')
    {
        return $this->getCachedHistoryModel(ClientContract::class, $this->contract_id, $date, $this);
    }

    /**
     * @return ActiveQuery
     */
    public function getClientContractModel()
    {
        return $this->hasOne(ClientContract::class, ['id' => 'contract_id']);
    }

    /**
     * @return string
     */
    public function getStatusName()
    {
        return isset(self::$statuses[$this->status]) ? self::$statuses[$this->status]['name'] : $this->status;
    }

    /**
     * @return string
     */
    public function getStatusColor()
    {
        return isset(self::$statuses[$this->status]) ? self::$statuses[$this->status]['color'] : '';
    }

    /**
     * @return bool|ClientContractComment
     */
    public function getLastComment()
    {
        if ($this->_lastComment === false) {
            $this->_lastComment
                = ClientContractComment::find()
                ->andWhere(['contract_id' => $this->contract_id])
                ->andWhere(['is_publish' => 1])
                ->orderBy('ts desc')
                ->all();
        }

        return $this->_lastComment;
    }

    /**
     * @return DateTimeZone
     */
    public function getTimezone()
    {
        return new DateTimeZone($this->timezone_name);
    }

    /**
     * @return ActiveQuery
     */
    public function getAllContacts()
    {
        return $this->hasMany(ClientContact::class, ['client_id' => 'id'])
            ->indexBy('id')
            ->orderBy([
                'type' => SORT_ASC,
                'id' => SORT_ASC,
            ]);
    }

    /**
     * @return array
     */
    public function getOfficialContact()
    {
        $contacts = ClientContact::find()
            ->select(['type', 'data'])
            ->andWhere(['client_id' => $this->id, 'is_official' => 1])
            ->groupBy(['type', 'data'])
            ->orderBy('id')
            ->asArray()
            ->all();

        $result = ['fax' => [], 'phone' => [], 'email' => []];
        foreach ($contacts as $contact) {
            $result[$contact['type']][] = $contact['data'];
        }

        return $result;
    }

    /**
     * Получение email среди ЛС контрагентов
     *
     * @return false|string
     * @throws \yii\db\Exception
     */
    public function getContactEmail()
    {
        $contactTable = ClientContact::tableName();
        $accountTable = ClientAccount::tableName();
        $contractTable = ClientContract::tableName();
        $sql = <<<SQL
WITH cl AS (
    SELECT c.id
    FROM {$contractTable} cc
             JOIN {$accountTable} c ON c.contract_id = cc.id
    WHERE contragent_id IN (
        SELECT contragent_id
        FROM {$accountTable} c
                 JOIN {$contractTable} cc ON cc.id = c.contract_id
        WHERE c.id = {$this->id}
    )
)

SELECT c.data
FROM {$contactTable} c
         INNER JOIN cl a ON c.client_id = a.id
WHERE c.type = 'email'
ORDER BY CASE
             WHEN c.client_id = {$this->id} AND c.is_official = 1 THEN 1
             WHEN c.client_id = {$this->id} AND c.is_official = 0 THEN 2
             WHEN c.client_id != {$this->id} AND c.is_official = 1 THEN 3
             ELSE 4
             END,
         c.id ASC
LIMIT 1;
SQL;
        return ClientContact::getDb()->createCommand($sql)->queryScalar();

    }

    /**
     * @return ActiveQuery
     */
    public function getContacts()
    {
        return $this->hasMany(ClientContact::class, ['client_id' => 'id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountTariffs()
    {
        return $this->hasMany(AccountTariff::class, ['client_account_id' => 'id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getUsageVoips()
    {
        return $this->hasMany(UsageVoip::class, ['client' => 'client']);
    }

    /**
     * @return ActiveQuery
     */
    public function getUsageTechCpes()
    {
        return $this->hasMany(UsageTechCpe::class, ['client' => 'client']);
    }

    /**
     * @return ActiveQuery
     */
    public function getUsageWelltimes()
    {
        return $this->hasMany(UsageWelltime::class, ['client' => 'client']);
    }

    /**
     * @return ActiveQuery
     */
    public function getUsageExtras()
    {
        return $this->hasMany(UsageExtra::class, ['client' => 'client']);
    }

    /**
     * @return ActiveQuery
     */
    public function getUsageVirtpbxs()
    {
        return $this->hasMany(UsageVirtpbx::class, ['client' => 'client']);
    }

    /**
     * @return ActiveQuery
     */
    public function getUsageSmses()
    {
        return $this->hasMany(UsageSms::class, ['client' => 'client']);
    }

    /**
     * @return ActiveQuery
     */
    public function getUsageIpPorts()
    {
        return $this->hasMany(UsageIpPorts::class, ['client' => 'client']);
    }

    /**
     * @return ActiveQuery
     */
    public function getUsageTrunks()
    {
        return $this->hasMany(UsageTrunk::class, ['client_account_id' => 'id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getUsageCallChats()
    {
        return $this->hasMany(UsageCallChat::class, ['client' => 'client']);
    }

    /**
     * @param bool $isActive
     * @return array|\app\classes\model\ActiveRecord[]
     */
    public function getAdditionalInn($isActive = true)
    {
        return $this->hasMany(ClientInn::class, ['client_id' => 'id'])
            ->andWhere(['is_active' => (int)$isActive])
            ->all();
    }

    /**
     * @return ActiveQuery
     */
    public function getAdditionalPayAcc()
    {
        return $this->hasMany(ClientPayAcc::class, ['client_id' => 'id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getLkClientSettings()
    {
        return $this->hasOne(LkClientSettings::class, ['client_id' => 'id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getTariffStatus()
    {
        return $this->hasOne(TariffStatus::class, ['id' => 'uu_tariff_status_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getExchangeGroup()
    {
        return $this->hasOne(SBISExchangeGroup::class, ['id' => 'exchange_group_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getLkNoticeSetting()
    {
        return $this->hasMany(LkNoticeSetting::class, ['client_id' => 'id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getEquipmentUsers()
    {
        return $this->hasMany(EquipmentUser::class, ['client_account_id' => 'id']);
    }

    /**
     * @param string $name
     * @return array|bool
     */
    public function getOption($name)
    {
        $option = $this->getOptions()->where(['option' => $name])->all();
        return $option !== null ? ArrayHelper::getColumn($option, 'value') : false;
    }

    /**
     * Значение опции
     *
     * @param $name
     * @return mixed
     */
    public function getOptionValue($name)
    {
        $optionQuery = $this->getOptions()->where(['option' => $name])->select('value');
        return $optionQuery->exists() ? $optionQuery->scalar() : ClientAccountOptions::getDefaultValue($name);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getOptions()
    {
        return $this->hasMany(ClientAccountOptions::class, ['client_account_id' => 'id']);
    }

    /**
     * @param float $originalSum
     * @param null $taxRate
     * @param int $decimals
     * @return array
     */
    public function convertSum($originalSum, $taxRate = null, $decimals = 2)
    {
        if ($taxRate === null) {
            $taxRate = $this->getTaxRate();
        }

        if ($this->price_include_vat) {
            $sumWithTax = round($originalSum, $decimals);
            $tax = round($taxRate / (100.0 + $taxRate) * $sumWithTax, $decimals);
            $sum = $sumWithTax - $tax;
        } else {
            $sum = round($originalSum, $decimals);
            $tax = round($sum * $taxRate / 100, $decimals);
            $sumWithTax = $sum + $tax;
        }

        return [$sumWithTax, $sum, $tax];
    }

    /**
     * @return int
     */
    public function getTaxRate()
    {
        if ($this->getHistoryVersionRequestedDate()) {
            return ClientContract::dao()->getEffectiveVATRate($this->contract, $this->getHistoryVersionRequestedDate());
        } else {
            return $this->effective_vat_rate;
        }
    }

    /**
     * Поучаем ставку на дату
     *
     * @return int
     */
    public function getTaxRateOnDate($date)
    {
        return ClientContract::dao()->getEffectiveVATRate($this->contract->loadVersionOnDate($date), $date);
    }

    /**
     * @param string $date
     * @return Organization
     */
    public function getOrganization($date = '')
    {
        $date = $date ?: ($this->getHistoryVersionRequestedDate() ?: null);
        return $this->getContract($date)->getOrganization($date);
    }

    /**
     * AfterSave
     *
     * @param bool $insert
     * @param array $changedAttributes
     */
    public function afterSave($insert, $changedAttributes)
    {
        $this->sync1C();
        parent::afterSave($insert, $changedAttributes);
    }

    /**
     * @throws Exception
     */
    public function sync1C()
    {
        if (!\Yii::$app->isRus()) {
            return;
        }

        if (!defined('PATH_TO_ROOT')) {
            define("PATH_TO_ROOT", \Yii::$app->basePath . '/stat/');
        }

        if (!defined("NO_WEB")) {
            define("NO_WEB", 1);
        }

        if (!defined('DESIGN_PATH')) {
            include_once PATH_TO_ROOT . 'conf.php';
        }

        if (!defined('SYNC1C_UT_SOAP_URL') || !SYNC1C_UT_SOAP_URL) {
            return;
        }

        EventQueue::go(EventQueue::SYNC_1C_CLIENT, ['client_id' => $this->id]);
    }

    /**
     * BeforeSave
     *
     * @param bool $insert
     * @return bool
     */
    public function beforeSave($insert)
    {
        if (!$this->password) {
            $this->password = Utils::password_gen();
        }

        return parent::beforeSave($insert);
    }

    /**
     * @return ClientCounter
     */
    public function getBillingCounters()
    {
        return ClientCounter::getCounters($this->id);
    }

    /**
     * @return ClientCounter
     */
    public function getBillingCountersFastMass()
    {
        return ClientCounter::getCountersFastMass($this->id);
    }

    /**
     * Возвращает список кодов предупреждений
     *
     * @return array
     */
    public function getVoipWarnings()
    {
        $warnings = [];

        if (defined("YII_ENV") && YII_ENV === 'test') {
            return $warnings;
        }

        try {
            $counters = $this->billingCounters;

            if ($counters->isLocal) {
                if ($counters->isSyncError) {
                    $warnings[self::WARNING_SYNC_ERROR] = 'Баланс не синхронизирован';
                } else {
                    $warnings[self::WARNING_UNAVAILABLE_BILLING] = 'Сервер статистики недоступен. Данные о балансе и счетчиках могут быть неверными';
                }
            }

            $lock = Locks::getLock($this->id);
            if ($lock) {
                if ($lock['b_is_finance_block']) {
                    $warnings[self::WARNING_FINANCE] = $lock['b_is_finance_block'];
                }

                if ($lock['b_is_overran']) {
                    $warnings[self::WARNING_OVERRAN] = $lock['b_is_overran'];
                }

                if ($lock['b_is_mn_overran']) {
                    $warnings[self::WARNING_MN_OVERRAN] = $lock['b_is_mn_overran'];
                }

                if ($lock['dt_last_dt'] && count($warnings)) {
                    // дата последней блокировки есть почти всегда. Но выводить ее надо только при блокировке
                    $warnings[self::WARNING_LAST_DT] = $lock['dt_last_dt'];
                }
            }
        } catch (\Exception $e) {
            $warnings[self::WARNING_UNAVAILABLE_LOCKS] = 'Сервер статистики недоступен. Данные о блокировках недоступны';
        }

        $need_lock_limit_day = ($this->voip_credit_limit_day != 0 && -$counters->daySummary > $this->voip_credit_limit_day);
        $need_lock_credit = ($this->credit >= 0 && $counters->realtimeBalance + $this->credit < 0);

        if ($need_lock_limit_day) {
            $warnings[self::WARNING_LIMIT_DAY] = 'Превышен дневной лимит: ' . (-$counters->daySummary) . ' > ' . $this->voip_credit_limit_day;
        }

        if ($need_lock_credit || array_key_exists(self::WARNING_FINANCE, $warnings)) {

            $financeLockDate = ClientAccount::dao()->getDateLastFinanceLock($this->id);

            $warnings[self::WARNING_CREDIT]
                = 'Превышен лимит кредита: ' .
                sprintf('%0.2f', $counters->realtimeBalance) . ' < -' . $this->credit .
                (
                isset($warnings[self::WARNING_FINANCE]) ?
                    ' (на уровне биллинга): ' . ($financeLockDate ? (new DateTimeWithUserTimezone($financeLockDate, $this->timezone))->format('H:i:s d.m.Y') :
                        ''
                    ) : ''
                );

            if ($counters->realtimeBalance > -$this->credit) {
                $warnings[self::WARNING_FINANCE_LAG] = 'Задержка снятия блокировки';
            }

            unset($warnings[ClientAccount::WARNING_LAST_DT]);
        }

        if ($this->is_bill_pay_overdue) {
            $warnings[self::WARNING_BILL_PAY_OVERDUE] = 'Блокировка по неоплате счета';
        }

        return $warnings;
    }

    /**
     * @return array|null
     */
    public function getVoipNumbers()
    {
        return self::dao()->getClientVoipNumbers($this);
    }

    /**
     * DAO
     *
     * @return ClientAccountDao
     */
    public static function dao()
    {
        return ClientAccountDao::me();
    }

    /**
     * @return bool
     */
    public function getHasVoip()
    {
        return UsageVoip::find()
                ->andWhere([
                    'client' => $this->client
                ])
                ->actual()
                ->exists()
            || AccountTariff::find()
                ->where([
                    'client_account_id' => $this->id,
                    'service_type_id' => ServiceType::ID_VOIP,
                ])
                ->andWhere(['IS NOT', 'tariff_period_id', null])
                ->exists();
    }

    /**
     * Это магазин
     *
     * @return bool
     */
    public function isMulty()
    {
        return in_array($this->id, self::$shopIds);
    }

    /**
     * Получение баланса ЛС
     *
     * @param bool $isWithExp
     * @return array
     */
    public function makeBalance($isWithExp = false)
    {
        $res = [
            'balance' => $this->billingCounters->realtimeBalance,
            'currency' => $this->currency,
        ];

        if ($isWithExp) {
            $res['id'] = $this->id;
            $res['credit'] = $this->credit;
            $res['expenditure'] = $this->billingCounters->getAttributes();
            $res['expenditure']['fixed_expenses_in_current_month'] = -\app\modules\uu\models\Bill::getUnconvertedAccountEntries($this->id)->andWhere(['<', 'type_id', 0])->sum('price_with_vat') ?: 0;
            $res['view_mode'] = $this->lk_balance_view_mode;
        }

        return $res;
    }

    /**
     * Это партнер
     *
     * @return bool
     */
    public function isPartner()
    {
        return $this->contract->isPartner();
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getLkSettings()
    {
        return $this->hasOne(LkClientSettings::class, ['client_id' => 'id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getFlag()
    {
        return $this->hasOne(ClientFlag::class, ['account_id' => 'id']);
    }

    /**
     * @return CounterInteropTrunk
     */
    public function getInteropCounter()
    {
        $counter = CounterInteropTrunk::findOne(['account_id' => $this->id]);
        if (!$counter) {
            $counter = new CounterInteropTrunk();
            $counter->account_id = $this->id;
        }

        return $counter;
    }

    /**
     * @return ActiveQuery
     */
    public function getCounterInteropTrunks()
    {
        return $this->hasMany(CounterInteropTrunk::class, ['account_id' => 'id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getClientCounter()
    {
        return $this->hasOne(ClientCounter::class, ['client_id' => 'id']);
    }

    /**
     * @return string
     */
    public function getLink()
    {
        return Html::a(
            Html::encode($this->getAccountTypeAndId()),
            $this->getUrl()
        );
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return Url::to(['/client/view', 'id' => $this->id]);
    }

    /**
     * @param string $time
     * @return DateTimeImmutable
     */
    public function getDatetimeWithTimezone($time = 'now')
    {
        $timezoneName = $this->timezone_name;
        $timezone = new DateTimeZone($timezoneName);
        return (new DateTimeImmutable($time))
            ->setTimezone($timezone);
    }

    /**
     * @param string $delimiter
     * @return string
     */
    public function getNameAndContacts($delimiter = ' / ')
    {
        $names = [];
        $names[] = $this->getName($delimiter);

        $allContacts = $this->allContacts;
        foreach ($allContacts as $contact) {
            if (!$contact->data) {
                continue;
            }

            $names[] = $contact->type . ': ' . $contact->data . ' ' . $contact->comment;
        }

        return implode($delimiter, $names);
    }

    /**
     * @param string $delimiter
     * @param bool $isHtml
     * @return string
     */
    public function getName($delimiter = ' / ', $isHtml = true)
    {
        return implode($delimiter, [
            $this->contract->contragent->name,
            'Договор № ' . $this->contract->number,
            $this->getAccountType() . ' № ' . ($isHtml ? "<b style=\"font-size:120%;\">{$this->id}</b>" : $this->id)
        ]);
    }

    /**
     * @return string "ЛС" или "УЛС"
     */
    public function getAccountType()
    {
        return ($this->account_version == ClientAccount::VERSION_BILLER_UNIVERSAL) ? 'УЛС' : 'ЛС';
    }

    /**
     * @return string "*ЛС № 12345"
     */
    public function getAccountTypeAndId()
    {
        return $this->getAccountType() . ' № ' . $this->id;
    }

    /**
     * Счетчики для дашборда в ЛК
     *
     * @return array
     */
    public function getDashboardCounters()
    {
        return StatisticDay::getCounters($this);
    }

    /**
     * Список уровней цен
     *
     * @return array
     */
    public static function getPriceLevels()
    {
        $priceLevels = [];
        for ($i = 1; $i <= 10; $i++) {
            if ($i <= 2) {
                $priceLevels[$i] = 'Клиент ' . $i;
            } else {
                $priceLevels[$i] = 'ОТТ ' . ($i - 2);
                $priceLevels[$i + 8] = 'ОТТ ' . ($i - 2) . 'z';
            }
        }

        for ($i = 0; $i < 3; $i++) {
            $priceLevels[($i * 2) + 19] = 'ОТТ ' . ($i + 9);
            $priceLevels[($i * 2) + 20] = 'ОТТ ' . ($i + 9) . 'z';
        }

        return $priceLevels;
    }

    /**
     * Список уровней просмотра ЛС в ЛК
     *
     * @return array
     */
    public static function getShowInLkList()
    {
        return [
            self::SHOW_IN_LK_ALWAYS => 'Показывать всегда',
            self::SHOW_IN_LK_NEVER => 'Не показывать',
            self::SHOW_IN_LK_ACTIVE_ONLY => 'Если есть активные услуги'
        ];
    }

    /**
     * Вернуть список всех доступных значений
     *
     * @param bool|string $isWithEmpty false - без пустого, true - с '----', string - с этим значением
     * @param bool $isWithNullAndNotNull
     * @return string[]
     */
    public static function getList(
        $isWithEmpty = false,
        $isWithNullAndNotNull = false
    )
    {
        return self::getListTrait(
            $isWithEmpty,
            $isWithNullAndNotNull,
            $indexBy = 'id',
            $select = 'client',
            $orderBy = ['id' => SORT_ASC],
            $where = []
        );
    }

    /**
     * Вернуть список всех доступных значений с именем контрагента
     * Обычный getList не получается использовать из-за join и PHP-логики getName()
     *
     * @param bool|string $isWithEmpty false - без пустого, true - с '----', string - с этим значением
     * @param bool $isWithNullAndNotNull
     * @param string $indexBy поле-ключ
     * @param array $orderBy
     * @param array $where
     * @return string[]
     */
    public static function getListWithContragent(
        $isWithEmpty = false,
        $isWithNullAndNotNull = false,
        $indexBy = 'id',
        $orderBy = ['id' => SORT_ASC],
        $where = []
    )
    {
        $models = self::find()
            ->where($where)
            ->orderBy($orderBy)
            ->all();

        $list = [];
        foreach ($models as $model) {
            $list[$model->$indexBy] = $model->getName($delimiter = ' / ', $isHtml = false);
        }

        return self::getEmptyList($isWithEmpty, $isWithNullAndNotNull) + $list;
    }

    /**
     * Запомнить исходное значение account_version
     */
    public function afterFind()
    {
        parent::afterFind();
        $this->_accountVersionOld = $this->account_version;
    }

    /**
     * Валидировать, что нельзя менять account_version при наличии услуг
     *
     * @param string $attribute
     * @param array $params
     */
    public function validatorAccountVersion($attribute, $params)
    {
        if (!$this->isNewRecord
            && $this->_accountVersionOld
            && $this->_accountVersionOld != $this->account_version
            && !$this->isAbleChangeAccountVersion()
        ) {
            $this->addError($attribute, 'Нельзя менять версию ЛС при наличии услуг');
            return;
        }
    }

    /**
     * Можно ли менять версию ЛС
     * Только если нет услуг
     *
     * @return bool
     */
    public function isAbleChangeAccountVersion()
    {
        if ($this->_accountVersionOld == self::VERSION_BILLER_UNIVERSAL) {
            return !$this->getAccountTariffs()->count();
        }

        return !$this->getUsageVoips()->count()
            && !$this->getUsageTechCpes()->count()
            && !$this->getUsageWelltimes()->count()
            && !$this->getUsageExtras()->count()
            && !$this->getUsageVirtpbxs()->count()
            && !$this->getUsageSmses()->count()
            && !$this->getUsageIpPorts()->count()
            && !$this->getUsageTrunks()->count()
            && !$this->getUsageCallChats()->count();
    }

    /**
     * Подготовка полей для исторических данных
     *
     * @param string $field
     * @param string $value
     * @return string
     */
    public static function prepareHistoryValue($field, $value)
    {
        switch ($field) {

            case 'country_id':
                if ($country = Country::findOne(['code' => $value])) {
                    return $country->getLink();
                }
                break;

            case 'region':
                if ($region = Region::findOne(['id' => $value])) {
                    return $region->getLink();
                }
                break;

            case 'price_level':
                $priceLevels = PriceLevel::getList();
                if (isset($priceLevels[$value])) {
                    return $priceLevels[$value];
                }
                break;

            case 'trust_level_id':
                $list = TrustLevel::getList();
                if (isset($list[$value])) {
                    return $list[$value];
                }
                return $value;

            case 'account_version':
                return self::$versions[$value];

            case 'price_include_vat':
            case 'type_of_bill':
            case 'upload_to_sales_book':
                return parent::prepareHistoryBoolValue($value);
                break;

        }

        return parent::prepareHistoryValue($field, $value);
    }

    /**
     * Какие поля не показывать в исторических данных
     *
     * @param string $action
     * @return string[]
     */
    public static function getHistoryHiddenFields($action)
    {
        return [
            'id',
        ];
    }

    /**
     * Показыввать ли ЛС в ЛК
     * (как свойство модели)
     *
     * @return bool
     */
    public function getIs_show_in_lk()
    {
        return self::isShowInLk($this->show_in_lk, $this->is_active);
    }

    /**
     * Показыввать ли ЛС в ЛК
     * (логика)
     *
     * @param integer $showLevel
     * @param integer $isActive
     * @return bool
     */
    public static function isShowInLk($showLevel, $isActive)
    {
        return $showLevel == self::SHOW_IN_LK_ACTIVE_ONLY ? (bool)$isActive : $showLevel == self::SHOW_IN_LK_ALWAYS;
    }

    /**
     * Вернуть страну для выбора тарифа УУ
     * @return int
     */
    public function getUuCountryId()
    {
        $superClient = $this->superClient;
        if ($superClient->entry_point_id) {
            // страна из точки входа суперклиента
            return $superClient->entryPoint->country_id;
        }

        // страна организации клиента
        $organization = $this->organization;
        if ($organization) {
            return $organization->country_id;
        }

        return $this->clientContractModel->clientContragent ->country_id;
    }

    /**
     * Включено переименование: Номенклатура: Оказанные услуги по Договору
     * @return bool
     */
    public function isBillRename1()
    {
        return $this->bill_rename1 == 'yes';
    }

    public static function getLinkToLk($accountId)
    {
        return 'https://' . \Yii::$app->params['BASE_SERVER'] . '/api/public/api/auth/login/support?accountId=' . $accountId . '&withRedirect=true';
    }
}
