<?php

namespace app\modules\uu\models;

use app\classes\model\ActiveRecord;
use app\exceptions\ModelValidationException;
use app\models\Language;
use app\models\OperationType;
use app\modules\uu\resourceReader\A2pResourceReader;
use app\modules\uu\resourceReader\AiAgentResourceReader;
use app\modules\uu\resourceReader\ApiResourceReader;
use app\modules\uu\resourceReader\CalltrackingResourceReader;
use app\modules\uu\resourceReader\InternetResourceReader;
use app\modules\uu\resourceReader\NnpNumberResourceReader;
use app\modules\uu\resourceReader\ResourceReaderInterface;
use app\modules\uu\resourceReader\SmsResourceReader;
use app\modules\uu\resourceReader\TrunkCallsResourceReader;
use app\modules\uu\resourceReader\TrunkCallsTermResourceReader;
use app\modules\uu\resourceReader\VoipPackageCallsResourceReader;
use app\modules\uu\resourceReader\VpbxDiskResourceReader;
use app\modules\uu\resourceReader\ZeroResourceReader;
use Yii;
use yii\db\ActiveQuery;

/**
 * Ресурс (справочник) (дисковое пространство, абоненты, линии и пр.)
 *
 * @property integer $id
 * @property string $name
 * @property float $min_value
 * @property float $max_value
 * @property integer $service_type_id
 * @property string $unit
 *
 * @property-read ServiceType $serviceType
 *
 * @method static ResourceModel findOne($condition)
 * @method static ResourceModel[] findAll($condition)
 */
class ResourceModel extends ActiveRecord
{
    // Перевод названий полей модели
    use \app\classes\traits\AttributeLabelsTraits;

    const ID_RESOURCES_WITHOUT_ENTRY = 0; // Ресурсы не внесенные в тразакции
    const ID_VPBX_DISK = 1; // ВАТС. Дисковое пространство
    const ID_VPBX_ABONENT = 2; // ВАТС. Абоненты
    const ID_VPBX_EXT_DID = 3; // ВАТС. Подключение номера другого оператора
    const ID_VPBX_RECORD = 4; // ВАТС. Запись звонков
    const ID_VPBX_FAX = 6; // ВАТС. Факс
    const ID_VPBX_MIN_ROUTE = 19; // ВАТС. Маршрутизация по минимальной цене
    const ID_VPBX_GEO_ROUTE = 20; // ВАТС. Маршрутизация по географии
    const ID_VPBX_SUB_ACCOUNT = 39; // ВАТС. Лимиты по субсчетам
    const ID_VPBX_VOICE_ASSISTANT = 51; // ВАТС. Голосовой помощник
    const ID_VPBX_ROBOT_CONTROLLER = 52; // ВАТС. Робот-контролер
    const ID_VPBX_RESERV = 57; // ВАТС. Резерв
    const ID_VPBX_PROMPTER = 60; // ВАТС. Cуфлер
    const ID_VPBX_OPERATOR_ASSESSMENT = 61; // ВАТС. Оценка оператора
    const ID_VPBX_TRUNK_EXT_VPBX = 66; // ВАТС. Транк для внешней АТС
    const ID_VPBX_SPECIAL_AUTOCALL = 67; // ВАТС. СпецАвтообзвон
    const ID_VPBX_CALL_END_MANAGEMENT = 68; // ВАТС. УправлениеЗавершениемЗвонка
    const ID_VPBX_TRANSCRIPTION = 72; // ВАТС. Транскрибация

    // VOIP
    const ID_VOIP_LINE = 7; // Телефония. Линия
    const ID_VOIP_FMC = 38; // Телефония. FMC
    const ID_VOIP_MOBILE_OUTBOUND = 43; // Телефония. "Исх. моб. связь"
    const ID_VOIP_ROBOCALL = 53; // Робокол
    const ID_VOIP_SMS_SUBABSENT = 55; // Телефония. SMS. Если абонент не в сети
    const ID_VOIP_SMS_DUPMTSMS = 56; // Телефония. SMS. Отправка всегда
    const ID_VOIP_GEO_REPLACE = 65; // Телефония - Гео-Автозамена
    const ID_VOIP_IS_SMART = 69; // Телефония. Is Smart
    const ID_VOIP_ONLY_FOR_TRUNK_VATS = 76; // Телефония. Только для ВАТС/транк
    const ID_VOIP_CALL_RECORDING = 79; // Телефония. Запись разговоров

    const ID_VOIP_PACKAGE_CALLS = 40; // Пакеты телефонии. Звонки
    const ID_VOIP_PACKAGE_INTERNET = 42; // Пакеты телефонии. Интернет
    const ID_VOIP_PACKAGE_SMS = 14; // Пакеты телефонии. СМС
    const ID_VOIP_PACKAGE_INTERNET_ROAMABILITY = 48; //Пакеты телефонии. Интернет. Roamability
    const ID_API_CALL = 50; //Билингация вызовов API. Вызов метода.

    const ID_INTERNET_TRAFFIC = 9; // Интернет. Трафик

    const ID_VPN_TRAFFIC = 13; // VPN. Трафик

    const ID_VPS_PROCESSOR = 15; // VPS. Процессор
    const ID_VPS_HDD = 16; // VPS. Дисковое пространство
    const ID_VPS_RAM = 17; // VPS. Оперативная память

    const ID_ONE_TIME = 18; // Разовая услуга

    const ID_TRUNK_PACKAGE_ORIG_CALLS = 41; // Ориг-пакеты транка. Звонки
    const ID_TRUNK_PACKAGE_TERM_CALLS = 49; // Терм-пакеты транка. Звонки

    const ID_NNP_NUMBERS = 44; // ННП. Кол-во номеров

    const ID_CALLTRACKING = 45;
    const ID_CALLTRACKING_ADDITIONAL_SITES = 70; // Calltracking. Количество дополнительных сайтов

    // SIP-Trunk
    const ID_CALLLIMIT = 46;
    const ID_ALLOW_DIVERSION = 47;

    const ID_QUOTA_TRAFFIC = 48;

    const ID_BOT = 54;
    const ID_CB_ADMIN = 64; // Чат-бот - Администрирование (Да/Нет)


    // A2P
    const ID_A2P_ALFA_NUMBERS = 58;
    const ID_A2P_SMS = 59;

    // Voice Robot
    const ID_VR_TASKS = 62;
    const ID_VR_SPEED_DIAL = 63;

    /** @deprecated use ID_VR_TASKS */
    const ID_VR_CHANNEL_COUNT = self::ID_VR_TASKS;
    /** @deprecated use ID_VR_SPEED_DIAL */
    const ID_VR_CAROUSEL = self::ID_VR_SPEED_DIAL;

    // contact center AI (old MULTICHAT)
    const ID_CC_OPERATOR_COUNT = 71;

    const ID_CC_VOICE_ANALYTICS = 73;
    const ID_CC_RESOURCE1 = 74;
    const ID_CC_RESOURCE2 = 75;

    const ID_AI_AGENT_QTY = 77;
    const ID_AI_DIALOGUE_DURATION = 78;


    const TYPE_BOOLEAN = 'boolean';
    const TYPE_NUMBER = 'number';

    const DEFAULT_UNIT = '¤';

    protected $isAttributeTypecastBehavior = true;

    protected static $readers = [
        // Дисковое пространство (Гб, float). Берется из virtpbx_stat.use_space
        self::ID_VPBX_DISK => VpbxDiskResourceReader::class,

        // Звонки по пакетам телефонии (у.е, float). Берется из calls_raw
        self::ID_VOIP_PACKAGE_CALLS => VoipPackageCallsResourceReader::class,

        // Смс по пакетам телефонии. Берется из smsc_raw
        self::ID_VOIP_PACKAGE_SMS => SmsResourceReader::class,

        // Интернет-трафик по пакетам телефонии (Мб, float). Не важно, сколько потрачено
        self::ID_VOIP_PACKAGE_INTERNET => ZeroResourceReader::class,

        // Звонки ориг-пакета транка (у.е, float). Берется из calls_raw
        self::ID_TRUNK_PACKAGE_ORIG_CALLS => TrunkCallsResourceReader::class,

        // Звонки терм-пакета транка (у.е, float). Берется из calls_raw
        self::ID_TRUNK_PACKAGE_TERM_CALLS => TrunkCallsTermResourceReader::class,

        // Разовая услуга. Менеджер сам определяет стоимость
        self::ID_ONE_TIME => ZeroResourceReader::class,

        // ННП. Кол-во номеров
        self::ID_NNP_NUMBERS => NnpNumberResourceReader::class,

        // Calltracking
        self::ID_CALLTRACKING => CalltrackingResourceReader::class,

        // Интернет. Roamobility
        self::ID_VOIP_PACKAGE_INTERNET_ROAMABILITY => InternetResourceReader::class,

        //билингация вызовов API
        self::ID_API_CALL => ApiResourceReader::class,

        self::ID_A2P_SMS => A2pResourceReader::class,

        // AI Agent Dialogs
        self::ID_AI_DIALOGUE_DURATION => AiAgentResourceReader::class,
    ];

    public static $calls = [
        self::ID_VOIP_PACKAGE_CALLS => self::ID_VOIP_PACKAGE_CALLS,
        self::ID_TRUNK_PACKAGE_ORIG_CALLS => self::ID_TRUNK_PACKAGE_ORIG_CALLS,
        self::ID_TRUNK_PACKAGE_TERM_CALLS => self::ID_TRUNK_PACKAGE_TERM_CALLS,
        self::ID_API_CALL => self::ID_API_CALL,
        self::ID_QUOTA_TRAFFIC => self::ID_QUOTA_TRAFFIC,
        self::ID_A2P_SMS => self::ID_A2P_SMS,
        self::ID_VOIP_PACKAGE_SMS => self::ID_VOIP_PACKAGE_SMS,
    ];

    // ресурс считается в шт. за потребление
    public static $billPerPiece = [
        self::ID_AI_DIALOGUE_DURATION
    ];

    // map for operation types
    public static $operationTypesMap = [
        self::ID_TRUNK_PACKAGE_TERM_CALLS => OperationType::ID_COST,
    ];

    public static $billingResources = [
        self::ID_VOIP_SMS_SUBABSENT, // Телефония. SMS. Если абонент не в сети
        self::ID_VOIP_SMS_DUPMTSMS, // Телефония. SMS. Отправка всегда
    ];


    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'uu_resource';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['min_value', 'max_value'], 'number'],
            [['service_type_id'], 'integer'],
            [['name', 'unit'], 'string', 'max' => 50]
        ];
    }

    /**
     * @return ActiveQuery
     */
    public function getServiceType()
    {
        return $this->hasOne(ServiceType::class, ['id' => 'service_type_id']);
    }

    /**
     * @return string
     */
    public function getDataType()
    {
        return ($this->min_value == 0 && $this->max_value == 1) ? self::TYPE_BOOLEAN : self::TYPE_NUMBER;
    }

    /**
     * @return bool
     */
    public function isNumber()
    {
        return $this->getDataType() === self::TYPE_NUMBER;
    }

    /**
     * @param int $id
     * @return ResourceReaderInterface|null
     * @link http://rd.welltime.ru/confluence/pages/viewpage.action?pageId=13336881
     */
    public static function getReader($id)
    {
        if (!array_key_exists($id, self::$readers)) {
            return null;
        }

        $className = self::$readers[$id];
        return new $className();
    }

    /**
     * @return int[]
     */
    public static function getReaderIds()
    {
        return array_keys(self::$readers);
    }

    /**
     * @return string[]
     */
    public static function getReaderNames()
    {
        return self::$readers;
    }

    /**
     * Id трафика?
     *
     * @param int $id
     * @return bool
     */
    public static function isTrafficId($id)
    {
        return array_key_exists($id, self::$readers);
    }

    /**
     * Id Опции?
     *
     * @param int $id
     * @return bool
     */
    public static function isOptionId($id)
    {
        return !self::isTrafficId($id);
    }

    /**
     * Опция? Иначе ресурс
     *
     * @return bool
     */
    public function isOption()
    {
        return self::isOptionId($this->id);
    }

    /**
     * Это ресурс, который надо передавать на биллинг?
     *
     * @param integer $resourceId
     * @return bool
     */
    public static function isBillingResource($resourceId): bool
    {
        return in_array($resourceId, self::$billingResources);
    }

    /**
     * Вернуть список всех доступных значений
     *
     * @param int $serviceTypeId
     * @param bool $isWithEmpty
     * @return \string[]
     */
    public static function getList($serviceTypeId, $isWithEmpty = false)
    {
        $query = self::find()
            ->where($serviceTypeId ? ['service_type_id' => $serviceTypeId] : [])
            ->indexBy('id')
            ->orderBy(
                [
                    'service_type_id' => SORT_ASC,
                    'name' => SORT_ASC,
                ]
            );

        $list = $query->all();

        if (!$serviceTypeId) {
            array_walk(
                $list,
                function (self &$resource) {
                    $resource = $resource->getFullName();
                }
            );
        }

        if ($isWithEmpty) {
            $list = (['' => ''] + $list);
        }

        return $list;
    }

    /**
     * Преобразовать объект в строку
     *
     * @return string
     */
    public function __toString()
    {
        return $this->name;
    }

    /**
     * Вернуть полное имя (с типом услуги)
     *
     * @param string $langCode
     * @param bool $isTextFull
     * @return string
     */
    public function getFullName($langCode = Language::LANGUAGE_DEFAULT, $isTextFull = false)
    {
        $dictionary = 'models/' . self::tableName();

        return
            ($isTextFull ? Yii::t($dictionary, 'Resource consumption limit exceedance', [], $langCode) . ': ' : '') .
            Yii::t($dictionary, 'Resource #' . $this->id, [], $langCode);
    }

    /**
     * Вернуть ресурсы, сгруппированные по типу услуги
     *
     * @return self[][]
     */
    public static function getGroupedByServiceType()
    {
        $resources = [];
        $resourceQuery = self::find();
        /** @var self $resource */
        foreach ($resourceQuery->each() as $resource) {
            $resources[$resource->service_type_id][] = $resource;
        }

        return $resources;
    }

    /**
     * @return string
     */
    public function getMinValue()
    {
        return $this->isNumber() ?
            (string)($this->min_value ?: '-∞') :
            '';
    }

    /**
     * @return string
     */
    public function getMaxValue()
    {
        return $this->isNumber() ?
            (string)($this->max_value ?: '∞') :
            '';
    }

    /**
     * @return string
     */
    public function getValueRange()
    {
        return $this->isNumber() ?
            $this->getMinValue() . ' - ' . $this->getMaxValue() . ' ' . $this->getUnit() :
            '';
    }

    /**
     * @return string
     */
    public function getUnit()
    {
        return $this->isNumber() ?
            $this->unit :
            '';
    }

    /**
     * Можно ли поменять количество ресурса в принципе
     * А фактически еще надо вызвать AccountTariff::isResourceEditable для доп. проверок по логу
     *
     * @return bool
     */
    public function isEditable()
    {
        return $this->isOption();
    }

    /**
     * Добавить этот ресурс в тариф
     *
     * @param float $amount
     * @param float $pricePerUnit
     * @param float $priceMin
     * @throws \yii\db\Exception
     */
    public function addTariffResource($amount, $pricePerUnit = 1.0, $priceMin = 0.0)
    {
        $db = self::getDb();
        $resourceId = $this->id;
        $serviceTypeId = $this->service_type_id;

        $tariffTableName = Tariff::tableName();
        $tariffPeriodTableName = TariffPeriod::tableName();
        $tariffResourceTableName = TariffResource::tableName();
        $accountTariffLogTableName = AccountTariffLog::tableName();
        $accountTariffResourceLogTableName = AccountTariffResourceLog::tableName();

        $sql = <<<SQL
            INSERT INTO {$tariffResourceTableName}
                (amount, price_per_unit, price_min, resource_id, tariff_id)
            SELECT {$amount}, {$pricePerUnit}, {$priceMin}, {$resourceId}, id
            FROM {$tariffTableName}
            WHERE service_type_id = {$serviceTypeId};
SQL;
//        $db->createCommand($sql)->execute(); // already in Migration::insertResource


        if ($this->isOption()) {
            $sql = <<<SQL
            INSERT INTO {$accountTariffResourceLogTableName}
                (account_tariff_id, resource_id, amount, actual_from_utc, insert_time, insert_user_id, sync_time)
                
            WITH main AS (
                SELECT
                    {$accountTariffLogTableName}.account_tariff_id,
                    {$accountTariffLogTableName}.actual_from_utc,
                    {$accountTariffLogTableName}.insert_time,
                    {$accountTariffLogTableName}.insert_user_id,
                    ROW_NUMBER() OVER (
                        PARTITION BY {$accountTariffLogTableName}.account_tariff_id 
                        ORDER BY {$accountTariffLogTableName}.actual_from_utc, {$accountTariffLogTableName}.id
                    ) as idx
                FROM
                    {$accountTariffLogTableName}, 
                    {$tariffPeriodTableName}, 
                    {$tariffTableName}
                WHERE 
                    {$accountTariffLogTableName}.tariff_period_id IS NOT NULL
                    AND {$accountTariffLogTableName}.tariff_period_id = {$tariffPeriodTableName}.id
                    AND {$tariffPeriodTableName}.tariff_id = {$tariffTableName}.id
                    AND {$tariffTableName}.service_type_id = {$serviceTypeId}
            )
            SELECT account_tariff_id,
                   {$resourceId},
                   {$amount},
                   actual_from_utc,
                   insert_time,
                   insert_user_id,
                   UTC_TIMESTAMP
            FROM main WHERE idx = 1

SQL;
            $db->createCommand($sql)->execute();
        }
    }

    /**
     * Удалить этот ресурс из тарифа
     *
     * @throws \app\exceptions\ModelValidationException
     * @throws \Exception
     */
    public function deleteTariffResource()
    {
        $tariffResources = TariffResource::findAll(['resource_id' => $this->id]);
        foreach ($tariffResources as $tariffResource) {
            AccountLogResource::deleteAll(['tariff_resource_id' => $tariffResource->id]);
            AccountEntry::deleteAll(['type_id' => $tariffResource->id]);
        }

        TariffResource::deleteAll(['resource_id' => $this->id]);

        AccountTariffResourceLog::deleteAll(['resource_id' => $this->id]);

        if (!$this->delete()) {
            throw new ModelValidationException($this);
        }

    }

    public static function addResourceOnAccountTariffs($serviceTypeId, $resourceId, $pricePerUnit=1)
    {
        $resource = self::findOne(['service_type_id' => $serviceTypeId, 'id' => $resourceId]);

        if (!$resource) {
            throw new \InvalidArgumentException('resource not found');
        }

        $resource->addTariffResource(0, $pricePerUnit);
    }
}
