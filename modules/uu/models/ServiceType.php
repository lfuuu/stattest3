<?php

namespace app\modules\uu\models;

use app\classes\model\ActiveRecord;
use yii\db\ActiveQuery;
use yii\helpers\Url;

/**
 * Тип услуги (ВАТС, телефония, интернет и пр.)
 *
 * @property int $id
 * @property string $name
 * @property int $parent_id
 * @property int $close_after_days
 *
 * @property-read ServiceType $parent
 * @property-read ResourceModel[] $resources
 *
 * @method static ServiceType findOne($condition)
 * @method static ServiceType[] findAll($condition)
 */
class ServiceType extends ActiveRecord
{
    const ID_VPBX = 1; // ВАТС
    const ID_VOIP = 2; // Телефония
    const ID_VOIP_PACKAGE_CALLS = 3; // Телефония. Пакет звонков
    const ID_VOIP_PACKAGE_INTERNET = 25; // Телефония. Пакет интернета
    const ID_VOIP_PACKAGE_SMS = 17; // Телефония. Пакет смс
    const ID_VOIP_PACKAGE_INTERNET_ROAMABILITY = 31; // Телефония. Пакет интернета. Roamability.

    const ID_INTERNET = 4; // Интернет
    const ID_VPN = 6; // VPN

    const ID_IT_PARK = 7; // IT Park
    const ID_DOMAIN = 8; // Регистрация доменов. domain
    const ID_MAILSERVER = 9; // Виртуальный почтовый сервер. mailserver
    const ID_ATS = 10; // Старый ВАТС. phone_ats
    const ID_SITE = 11; // Сайт. site
    const ID_USPD = 12; // Провайдер. uspd
    const ID_WELLSYSTEM = 13; // Wellsystem. wellsystem
    const ID_WELLTIME_PRODUCT = 14; // Welltime как продукт. welltime, ip
    const ID_EXTRA = 15; // Дополнительные услуги
    const ID_SMS_GATE = 16; // СМС. sms_gate

    const ID_WELLTIME_SAAS = 18; // Welltime как сервис. welltime

    const ID_CALL_CHAT = 19; // Звонок-чат

    const ID_VPS = 20; // VPS
    const ID_VPS_LICENCE = 28; // Лицензии VPS

    const ID_ONE_TIME = 21; // Разовая услуга

    const ID_TRUNK = 22; // транк
    const ID_TRUNK_PACKAGE_ORIG = 23; // пакет ориг-транк
    const ID_TRUNK_PACKAGE_TERM = 24; // пакет терм-транк

    const ID_INFRASTRUCTURE = 26; // Инфраструктура

    const ID_NNP = 27; // ННП

    const ID_CALLTRACKING = 29; //CallTracking
    const ID_SIPTRUNK = 30; //SIP-Trunk
    const ID_BILLING_API = 32; //Билинг API
    const ID_BILLING_API_MAIN_PACKAGE = 33; // Билинг API. Основной пакет.

    const ID_CHAT_BOT = 34;
    const ID_A2P = 35;
    const ID_A2P_PACKAGE = 36;

    const ID_VOICE_ROBOT = 37;

    const ID_CONTACT_CENTER_AI = 38;

    const ID_ESIM = 39;

    const ID_AI_AGENT = 40;

    const ID_VOIP_PACKAGE_MAV = 41; // Телефония. МАВ (Массовые и/или автоматические вызовы)

    // Типы услуг в прайслистах ннп (billing_uu.pricelist.service_type_id)
    const NNP_SERVICE_TYPE_VOICE   = 1; // Голос
    const NNP_SERVICE_TYPE_SMS_A2P = 2; // СМС-A2P
    const NNP_SERVICE_TYPE_DATA    = 3; // Дата (трафик)
    const NNP_SERVICE_TYPE_SMS_P2P = 4; // СМС-P2P
    const NNP_SERVICE_TYPE_MAV     = 41; // МАВ

    // Маппинг uu_service_type.id => billing_uu.pricelist.service_type_id
    public static $nnpServiceTypeMap = [
        self::ID_VOIP_PACKAGE_CALLS => self::NNP_SERVICE_TYPE_VOICE,
        self::ID_VOIP_PACKAGE_SMS   => self::NNP_SERVICE_TYPE_SMS_P2P,
        self::ID_VOIP_PACKAGE_MAV   => self::NNP_SERVICE_TYPE_MAV,
    ];

    const CLOSE_AFTER_DAYS = 60;

    public static $packages = [
        self::ID_VOIP_PACKAGE_CALLS => self::ID_VOIP,
        self::ID_VOIP_PACKAGE_INTERNET => self::ID_VOIP,
        self::ID_VOIP_PACKAGE_INTERNET_ROAMABILITY => self::ID_VOIP,
        self::ID_VOIP_PACKAGE_SMS => self::ID_VOIP,
        self::ID_TRUNK_PACKAGE_ORIG => self::ID_TRUNK,
        self::ID_TRUNK_PACKAGE_TERM => self::ID_TRUNK,
        self::ID_BILLING_API_MAIN_PACKAGE => self::ID_BILLING_API,
        self::ID_A2P_PACKAGE => self::ID_A2P,
        self::ID_VOIP_PACKAGE_MAV => self::ID_VOIP,
    ];

    public static $serviceToPackage = [
        self::ID_VOIP => self::ID_VOIP_PACKAGE_CALLS,
        self::ID_BILLING_API => self::ID_BILLING_API_MAIN_PACKAGE,
        self::ID_A2P => self::ID_A2P_PACKAGE,
    ];

    // Соответствие неуниверсальной услуги
    public static $idToUsageName = [
        self::ID_VPBX => 'usage_virtpbx', // ВАТС

        self::ID_VOIP => 'usage_voip', // Телефония
        self::ID_VOIP_PACKAGE_CALLS => 'usage_voip', // Телефония. Пакет звонков

        self::ID_CALL_CHAT => 'usage_call_chat', // Звонок-чат

        self::ID_TRUNK => 'usage_trunk', // транк
        self::ID_TRUNK_PACKAGE_ORIG => 'usage_trunk', // пакет ориг-транк
        self::ID_TRUNK_PACKAGE_TERM => 'usage_trunk', // пакет терм-транк
    ];

    public static $onlyRegionGroup = [
        self::ID_TRUNK,
        self::ID_SIPTRUNK,
    ];

    // Перевод названий полей модели
    use \app\classes\traits\AttributeLabelsTraits;

    // Определяет getList (список для selectbox)
    use \app\classes\traits\GetListTrait {
        getList as getListTrait;
    }

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'uu_service_type';
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            [['id', 'parent_id', 'close_after_days'], 'integer'],
            [['name'], 'string'],
            [['name'], 'required'],
        ];
    }

    /**
     * @return ActiveQuery
     */
    public function getParent()
    {
        return $this->hasOne(self::class, ['id' => 'parent_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getResources()
    {
        return $this->hasMany(ResourceModel::class, ['service_type_id' => 'id'])
            ->inverseOf('serviceType');
    }

    public function getServiceTypeResources()
    {
        return $this->hasMany(RewardsServiceTypeResource::class, ['service_type_id' => 'id']);
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
        $isWithNullAndNotNull = false,
        $isOnlyTopLevelStatuses = false
    ) {
        return self::getListTrait(
            $isWithEmpty,
            $isWithNullAndNotNull,
            $indexBy = 'id',
            $select = 'name',
            $orderBy = ['id' => SORT_ASC],
            $where = ($isOnlyTopLevelStatuses ? ['parent_id' => null] : [])
        );
    }

    /**
     * @return string
     * @throws \yii\base\InvalidParamException
     */
    public function getUrl()
    {
        return self::getUrlById($this->id);
    }

    /**
     * @param int $id
     * @return string
     * @throws \yii\base\InvalidParamException
     */
    public static function getUrlById($id)
    {
        return Url::to(['/uu/service-type/edit', 'id' => $id]);
    }

    /**
     * @return string
     */
    public function getColorClass()
    {
        switch ($this->id) {
            case self::ID_VPBX:
                return 'success';

            case self::ID_VOIP:
            case self::ID_VOIP_PACKAGE_CALLS:
            case self::ID_VOIP_PACKAGE_INTERNET:
                return 'info';

            case self::ID_TRUNK:
            case self::ID_TRUNK_PACKAGE_ORIG:
            case self::ID_TRUNK_PACKAGE_TERM:
                return 'primary';

            default:
                return 'warning';
        }
    }

    /**
     * Вернуть billing_uu.pricelist.service_type_id по uu_service_type.id
     *
     * @param int $serviceTypeId
     * @return int|null
     */
    public static function getNnpServiceTypeId($serviceTypeId)
    {
        return self::$nnpServiceTypeMap[$serviceTypeId] ?? null;
    }

    /**
     * @param int $serviceTypeId
     * @return int[]
     */
    public static function getPackageIds($serviceTypeId)
    {
        $serviceTypeIds = [];
        foreach (ServiceType::$packages as $serviceTypeIdPackage => $serviceTypeIdMain) {
            if ($serviceTypeId == $serviceTypeIdMain) {
                $serviceTypeIds[] = $serviceTypeIdPackage;
            }
        }

        return $serviceTypeIds;
    }

    /**
     * Соответствие неуниверсальной услуги
     *
     * @return string
     */
    public function getUsageName()
    {
        return isset(self::$idToUsageName[$this->id]) ? self::$idToUsageName[$this->id] : null;
    }

    /**
     * Для пакетов возвращается ID родителя.
     * Иначе возвращается null.
     *
     * @return null|integer
     */
    public function isPackage()
    {
        return self::isPackageById($this->id);
    }

    /**
     * @param integer $id
     * @return null|integer
     */
    public static function isPackageById($id)
    {
        return isset(self::$packages[$id]) ? self::$packages[$id] : null;
    }

    /**
     * @return array
     */
    public function getHelpConfluence()
    {
        return self::getHelpConfluenceById($this->id);
    }

    /**
     * @param int $id
     * @return array
     */
    public static function getHelpConfluenceById($id)
    {
        switch ($id) {
            case self::ID_VPBX:
                return ['confluenceId' => 25887462, 'message' => 'ВАТС'];
            case self::ID_VOIP:
                return ['confluenceId' => 25887465, 'message' => 'Телефония'];
            case self::ID_VOIP_PACKAGE_CALLS:
                return ['confluenceId' => 25887468, 'message' => 'Телефония. Пакет звонков'];
            case self::ID_VOIP_PACKAGE_INTERNET:
                return ['confluenceId' => 25887479, 'message' => 'Телефония. Пакет интернета'];
            case self::ID_TRUNK:
                return ['confluenceId' => 25887541, 'message' => 'Транк'];
            case self::ID_INFRASTRUCTURE:
                return ['confluenceId' => 25887545, 'message' => 'Инфраструктура'];
            case self::ID_VPS:
            case self::ID_VPS_LICENCE:
                return ['confluenceId' => 25887547, 'message' => 'VPS'];
            case self::ID_ONE_TIME:
                return ['confluenceId' => 25887552, 'message' => 'Разовая услуга'];
        }

        return ['confluenceId' => 25887452, 'message' => 'Типы услуг'];
    }
}
