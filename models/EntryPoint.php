<?php

namespace app\models;

use app\classes\model\ActiveRecord;
use app\classes\traits\GetListTrait;
use app\classes\validators\FormFieldValidator;
use app\helpers\DateTimeZoneHelper;
use app\models\dictionary\PublicSite;
use http\Url;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $name_prefix
 * @property int $country_id
 * @property int $organization_id
 * @property int $client_contract_business_id
 * @property int $client_contract_business_process_id
 * @property int $client_contract_business_process_status_id
 * @property string $currency_id
 * @property string $timezone_name
 * @property int $is_postpaid
 * @property int $account_version
 * @property int $credit
 * @property int $voip_credit_limit_day
 * @property int $voip_limit_mn_day
 * @property int $is_default
 * @property int $region_id
 * @property int $site_id
 * @property int $connect_trouble_user_id
 * @property int $price_level
 * @property int $partner_id
 * @property string $legal_type
 * @property string $lk_shopfront_id
 *
 * @property string $wizard_type
 * @property Country $country
 * @property User $connectTroubleUser
 * @property PublicSite $site
 * @property Business $business
 * @property BusinessProcess $businessProcess
 * @property BusinessProcessStatus $businessProcessStatus
 */
class EntryPoint extends ActiveRecord
{
    use GetListTrait;

    const RU1 = 'RU1';
    const RU5 = 'RU5';

    const RF_CRM = 'RF_CRM';
    const MNP_RU_DANYCOM = 'MNP_RU_DANYCOM';
    const ID_MNP_RU_DANYCOM = 17;

    const ID_TRANSFER_ALLOWED_LC = 11;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

        //default values
        $this->id = 0;
        $this->credit = 0;
        $this->voip_credit_limit_day = 0;
        $this->voip_limit_mn_day = 0;

        $this->account_version = ClientAccount::VERSION_BILLER_UNIVERSAL;
        $this->currency_id = Currency::RUB;
        $this->organization_id = Organization::MCN_TELECOM;
        $this->country_id = Country::RUSSIA;
        $this->timezone_name = DateTimeZoneHelper::TIMEZONE_MOSCOW;
        $this->is_postpaid = 1;
        $this->price_level = ClientAccount::DEFAULT_PRICE_LEVEL;

        $this->client_contract_business_id = Business::TELEKOM;
        $this->client_contract_business_process_id = BusinessProcess::TELECOM_MAINTENANCE;
        $this->client_contract_business_process_status_id = BusinessProcessStatus::TELEKOM_MAINTENANCE_ORDER_OF_SERVICES;
    }

    /**
     * @return string
     */
    public static function tableName()
    {
        return 'entry_point';
    }

    /**
     * @return array
     */
    public static function primaryKey()
    {
        return ['id', 'code'];
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            [
                [
                    'code',
                    'name',
                    'country_id',
                    'organization_id',
                    'currency_id',
                    'timezone_name',
                    'is_postpaid',
                    'account_version',
                    'credit',
                    'voip_credit_limit_day',
                    'voip_limit_mn_day',
                    'client_contract_business_id',
                    'client_contract_business_process_id',
                    'client_contract_business_process_status_id',
                    'region_id',
                    'connect_trouble_user_id',
                    'wizard_type',
                    'site_id',
                ],
                'required'
            ],

            ['country_id', 'in', 'range' => array_keys(Country::getList())],
            ['site_id', 'in', 'range' => array_keys(PublicSite::getList())],
            ['region_id', 'in', 'range' => array_keys(Region::getList())],
            ['organization_id', 'in', 'range' => array_keys(Organization::dao()->getList())],
            ['currency_id', 'in', 'range' => array_keys(Currency::getList())],
            ['timezone_name', 'in', 'range' => Region::getTimezoneList()],
            ['wizard_type', 'in', 'range' => array_keys(LkWizardState::$name)],
            ['legal_type', 'in', 'range' => array_keys(ClientContragent::$names + ['' => 'Empty'])],
            [['is_default'], 'boolean'],
            ['is_postpaid', 'in', 'range' => array_keys(ClientAccount::$paymentTypes)],
            ['account_version', 'in', 'range' => array_keys(ClientAccount::$versions)],
            [['credit', 'voip_credit_limit_day', 'voip_limit_mn_day'], 'integer', 'min' => 0],
            [
                [
                    'client_contract_business_id',
                    'client_contract_business_process_id',
                    'client_contract_business_process_status_id',
                    'price_level',
                    'partner_id',
                ],
                'integer'
            ],
            [['name_prefix', 'lk_shopfront_id'], 'string'],
            [['name_prefix', 'lk_shopfront_id'], FormFieldValidator::class],
        ];
    }

    /**
     * @return array
     */
    public function attributeLabels()
    {
        return [
            'code' => 'ID (code)',
            'name' => 'Название',
            'name_prefix' => 'Префикс к названию (супер)клиента и контрагента',
            'country_id' => 'Страна',
            'organization_id' => 'Организация',
            'client_contract_business_id' => 'Подразделение',
            'client_contract_business_process_id' => 'Бизнес-процесс',
            'client_contract_business_process_status_id' => 'Статус БП',
            'currency_id' => 'Валюта',
            'timezone_name' => 'Часовой пояс',
            'is_postpaid' => 'Тип оплаты',
            'account_version' => 'Версия ЛС	',
            'credit' => 'Кредит',
            'voip_credit_limit_day' => 'Лимит телефонии',
            'voip_limit_mn_day' => 'Лимит телефонии МН',
            'is_default' => 'По умолчанию',
            'region_id' => 'Регион (точка подключения)',
            'connect_trouble_user_id' => 'Пользовтель, для создания траблы на подключение',
            'wizard_type' => "Тип Wizard'а",
            'site_id' => "Сайт для обслуживания",
            'price_level' => "Уровень цен",
            'legal_type' => "Тип юр. лица",
            'partner_id' => "Партнер ID",
            'lk_shopfront_id' => "ID витрины ЛК",
        ];
    }

    /**
     * Только одна точка входа должна быть по умолчанию
     *
     * @param bool $insert
     * @return bool
     */
    public function beforeSave($insert)
    {
        if ($this->is_default) {
            EntryPoint::updateAll(['is_default' => 0]);
        }

        return parent::beforeSave($insert);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getCountry()
    {
        return $this->hasOne(Country::class, ['code' => 'country_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getConnectTroubleUser()
    {
        return $this->hasOne(User::class, ['id' => 'connect_trouble_user_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getSite()
    {
        return $this->hasOne(PublicSite::class, ['id' => 'site_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getBusiness()
    {
        return $this->hasOne(Business::class, ['id' => 'client_contract_business_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getBusinessProcess()
    {
        return $this->hasOne(BusinessProcess::class, ['id' => 'client_contract_business_process_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getBusinessProcessStatus()
    {
        return $this->hasOne(BusinessProcessStatus::class, ['id' => 'client_contract_business_process_status_id']);
    }


    /**
     * Вовращает точку входа по коду, или по умолчанию, если такая не найдена
     *
     * @param string $code
     * @return EntryPoint
     */
    public static function getByIdOrDefault($code)
    {
        $entryPoint = null;

        if ($code) {
            $entryPoint = static::findOne(['code' => $code]);
        }

        if (!$entryPoint) {
            $entryPoint = static::findOne(['is_default' => 1]);
        }

        return $entryPoint;
    }

    public static function getUrlById($id)
    {
        return \yii\helpers\Url::toRoute(['/dictionary/entry-point/edit', 'id' => $id]);
    }
}