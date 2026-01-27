<?php

namespace app\models;

use app\classes\enum\VoipRegistrySourceEnum;
use app\classes\Html;
use app\classes\HttpClient;
use app\classes\model\ActiveRecord;
use app\dao\NumberDao;
use app\models\light_models\NumberPriceLight;
use app\models\voip\Registry;
use app\modules\nnp\models\NdcType;
use app\modules\nnp\models\Operator;
use app\modules\sim\models\Imsi;
use app\modules\uu\models\AccountTariff;
use yii\base\InvalidConfigException;
use yii\helpers\Url;

/**
 * Class Number
 *
 * @property string $number
 * @property string $status
 * @property string $reserve_from
 * @property string $reserve_till
 * @property string $hold_from
 * @property string $hold_to
 * @property int $beauty_level
 * @property int $original_beauty_level
 * @property int $region
 * @property int $client_id
 * @property int $registry_id
 * @property int $usage_id
 * @property int $uu_account_tariff_id
 * @property string $reserved_free_date
 * @property string $used_until_date
 * @property int $edit_user_id
 * @property int $city_id
 * @property int $did_group_id
 * @property string $number_tech
 * @property int $ndc
 * @property string $number_subscriber
 * @property int $ndc_type_id
 * @property string $date_start
 * @property string $date_end
 * @property int $operator_account_id
 * @property int $country_code
 * @property int $calls_per_month_0 Кол-во звонков за текущий месяц
 * @property int $calls_per_month_1 Кол-во звонков за -1 месяц
 * @property int $calls_per_month_2 Кол-во звонков за -2 месяц
 * @property int $calls_per_month_3 Кол-во звонков за -3 месяц
 * @property int $is_ported
 * @property int $is_service
 * @property integer $fmc_trunk_id
 * @property integer $mvno_trunk_id
 * @property integer $mvno_partner_id
 * @property integer $imsi
 * @property integer $warehouse_status_id
 * @property integer $nnp_operator_id
 * @property integer $orig_nnp_operator_id
 * @property integer $usr_operator_id
 * @property string $source
 * @property integer $numbers_count
 * @property string $solution_date
 * @property integer $nnp_city_id
 * @property integer $nnp_region_id
 * @property integer $is_verified
 * @property integer $is_with_discount
 * @property integer $is_in_msteams
 * @property string $forced_status
 *
 * @property-read City $city
 * @property-read AccountTariff $accountTariff
 * @property-read Country $country
 * @property-read DidGroup $didGroup
 * @property-read DidGroupPriceLevel[] $didGroupPriceLevel
 * @property-read UsageVoip $usage
 * @property-read ClientAccount $clientAccount
 * @property-read NdcType $ndcType
 * @property-read Region $regionModel
 * @property-read City $cityByName
 * @property-read Imsi $imsiModel
 * @property-read Registry $registry
 * @property-read Operator $nnpOperator
 * @property-read Operator $origNnpOperator
 * @property-read \app\modules\nnp\models\Region $nnpRegion
 * @property-read \app\modules\nnp\models\City $nnpCity
 * @property-read string $link
 * @property-read Url $url
 *
 * @property-read float $originPrice
 * @property-read float $price
 * @property-read array $priceWithCurrency
 */
class Number extends ActiveRecord
{
    const STATUS_NOTSALE = 'notsale';
    const STATUS_INSTOCK = 'instock';
    const STATUS_NOT_VERFIED = 'not_verfied';
    const STATUS_ACTIVE_CONNECTED = 'active_connected';
    const STATUS_ACTIVE_TESTED = 'active_tested';
    const STATUS_ACTIVE_COMMERCIAL = 'active_commercial';
    const STATUS_ACTIVE_MSTEAMS = 'active_msteams';
    const STATUS_BLOCKED_BY_SUBSCRIBER = 'blocked_by_subscriber';
    const STATUS_BLOCKED_BY_OPERATOR = 'blocked_by_operator';
    const STATUS_NOTACTIVE_RESERVED = 'notactive_reserved';
    const STATUS_NOTACTIVE_HOLD = 'notactive_hold';
    const STATUS_RELEASED = 'released';
    const STATUS_RELEASED_AND_PORTED = 'released_and_ported';

    const STATUS_GROUP_ACTIVE = 'active';
    const STATUS_GROUP_NOTACTIVE = 'notactive';

    const COUNT_CALLS_FOR_DISCOUNT = 7;

    /**
     * Номер не привязан к сим-карте, т.е. отсутствует imsi
     * @see CardController::actionAssignImsi
     */
    const STATUS_WAREHOUSE_NO_RELATION = -1;

    /**
     * Список номеров, которые игнорируются при синхронизации
     *
     * @see \app\modules\sim\commands\convert\CardController::actionSynchronization()
     */
    const LIST_SKIPPING = ['79587980598'];

    const NUMBER_MAX_LINE = 10000; // если Number до этого числа - это линия, если больше - номер

    public static $statusList = [
        self::STATUS_NOTSALE => 'Не продается',
        self::STATUS_INSTOCK => 'Свободен',
        self::STATUS_NOT_VERFIED => 'Не верифицирован',
        self::STATUS_ACTIVE_TESTED => 'Используется. Тестируется.',
        self::STATUS_ACTIVE_COMMERCIAL => 'Используется. В коммерции.',
        self::STATUS_ACTIVE_CONNECTED => 'Подключение запланировано',
        self::STATUS_ACTIVE_MSTEAMS => 'Используется. MS Teams',
        self::STATUS_BLOCKED_BY_SUBSCRIBER => 'Заблокирован абонентом',
        self::STATUS_BLOCKED_BY_OPERATOR => 'Заблокирован оператором',
        self::STATUS_NOTACTIVE_RESERVED => 'В резерве',
        self::STATUS_NOTACTIVE_HOLD => 'В отстойнике',
        self::STATUS_RELEASED => 'Откреплен',
    ];

    public static $statusGroup = [
        self::STATUS_GROUP_ACTIVE => [
            self::STATUS_ACTIVE_CONNECTED,
            self::STATUS_ACTIVE_TESTED,
            self::STATUS_ACTIVE_COMMERCIAL,
            self::STATUS_NOT_VERFIED,
            self::STATUS_ACTIVE_MSTEAMS,
            self::STATUS_BLOCKED_BY_SUBSCRIBER,
            self::STATUS_BLOCKED_BY_OPERATOR
        ],
        self::STATUS_GROUP_NOTACTIVE => [
            self::STATUS_NOTACTIVE_RESERVED,
            self::STATUS_NOTACTIVE_HOLD
        ],
    ];

    protected $callsCount = null;

    public $levenshtein = -1;

    const TYPE_NUMBER = 'number';
    const TYPE_7800 = '7800';
    const TYPE_LINE = 'line';

    /**
     * @return string
     */
    public static function tableName()
    {
        return 'voip_numbers';
    }

    /**
     * Вернуть имена полей
     *
     * @return array
     */
    public function attributeLabels()
    {
        return [
            'number' => 'Номер',
            'client_id' => 'Клиент',
            'usage_id' => 'Услуга',
            'city_id' => 'Город',
            'did_group_id' => 'DID-группа',
            'beauty_level' => 'Степень красивости',
            'status' => 'Статус',
            'region' => 'Точка подключения',
            'registry_id' => 'Реестр',
            'ndc_type_id' => 'Тип номера',
            'number_tech' => 'Технический номер',
            'imsi' => 'Привязка к сим-карте (IMSI)',
            'warehouse_status_id' => 'Статус скалада сим-карты',
            'nnp_operator_id' => 'Текущий ННП-оператор',
            'orig_nnp_operator_id' => 'Первоначальный ННП-оператор',
            'usr_operator_id' => 'ННП-оператор пользователя',
            'source' => 'Источник',
            'original_beauty_level' => 'Степень красивости (изначальная)',
            'calls_per_month_0' => 'Кол-во звонков за текущий месяц',
            'calls_per_month_1' => 'Кол-во звонков за -1 месяц',
            'calls_per_month_2' => 'Кол-во звонков за -2 месяц',
            'calls_per_month_3' => 'Кол-во звонков за -3 месяц',
            'is_with_discount' => 'Со скидкой',
        ];
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            [['status', 'number_tech', 'source'], 'string'],
            [['beauty_level', 'original_beauty_level'], 'integer'],
            [['imsi', 'warehouse_status_id'], 'integer'],
            [['nnp_operator_id', 'usr_operator_id', 'registry_id', 'region', 'is_with_discount'], 'integer'],
            [['status', 'beauty_level'], 'required', 'on' => 'save'],
        ];
    }

    public function behaviors()
    {
        return array_merge(
            parent::behaviors(),
            [
                \app\classes\behaviors\HistoryChangesGrayLog::class,
            ]
        );
    }

    /**
     * @return NumberDao
     */
    public static function dao()
    {
        return NumberDao::me();
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getCity()
    {
        return $this->hasOne(City::class, ['id' => 'city_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getAccountTariff()
    {
        return $this->hasOne(AccountTariff::class, ['id' => 'uu_account_tariff_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getCountry()
    {
        return $this->hasOne(Country::class, ['code' => 'country_code']);
    }

    /**
     * @return Country
     */
    public function getCachedCountry()
    {
        static $cache = [];

        if (!isset($cache[$this->country_code])) {
            $cache[$this->country_code] = $this->country;
        }

        return $cache[$this->country_code];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getDidGroup()
    {
        return $this->hasOne(DidGroup::class, ['id' => 'did_group_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getDidGroupPriceLevel()
    {
        return $this->hasMany(DidGroupPriceLevel::class, ['did_group_id' => 'did_group_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getUsage()
    {
        return $this->hasOne(UsageVoip::class, ['id' => 'usage_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getNdcType()
    {
        return $this->hasOne(NdcType::class, ['id' => 'ndc_type_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getClientAccount()
    {
        return $this->hasOne(ClientAccount::class, ['id' => 'client_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getRegionModel()
    {
        return $this->hasOne(Region::class, ['id' => 'region']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getImsiModel()
    {
        return $this->hasOne(Imsi::class, ['imsi' => 'imsi']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getRegistry()
    {
        return $this->hasOne(Registry::class, ['id' => 'registry_id']);
    }

    public function getNnpOperator()
    {
        return $this->hasOne(Operator::class, ['id' => 'nnp_operator_id']);
    }

    public function getOrigNnpOperator()
    {
        return $this->hasOne(Operator::class, ['id' => 'orig_nnp_operator_id']);
    }

    public function getNnpRegion()
    {
        return $this->hasOne(\app\modules\nnp\models\Region::class, ['id' => 'nnp_region_id']);
    }

    public function getNnpCity()
    {
        return $this->hasOne(\app\modules\nnp\models\City::class, ['id' => 'nnp_city_id']);
    }

    /**
     * @param string|null $currency
     * @param ClientAccount $clientAccount
     * @return float|null
     */
    public function getPrice($currency = null, ClientAccount $clientAccount = null)
    {
        try {
            $price = $this->getOriginPrice($clientAccount, null, $this->is_with_discount);

            return $this->_getPrice($price, $currency, $clientAccount);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @param string|null $currency
     * @param ClientAccount $clientAccount
     * @return float|null
     */
    public function _getPrice($price, $currency = null, ClientAccount $clientAccount = null)
    {
        if (is_null($price)) {
            return $price;
        }

        if (!is_null($currency) && $this->getCachedDidGroup()->getCachedCountry()->currency_id != $currency) {
            if (($tariffCurrencyRate = CurrencyRate::dao()->getRate($this->getCachedDidGroup()->getCachedCountry()->currency_id))) {
                $price *= $tariffCurrencyRate;
            }

            if (($currencyRate = CurrencyRate::dao()->getRate($currency))) {
                $price /= $currencyRate;
            }
        }

        return $price;
    }

    /**
     * @param string $currency
     * @param ClientAccount $clientAccount
     * @return NumberPriceLight
     */
    public function getPriceWithCurrency($currency = Currency::RUB, $clientAccount = null)
    {
        $formattedResult = new NumberPriceLight;
        $formattedResult->setAttributes([
            'currency' => $currency,
            'price' => $this->getPrice($currency, $clientAccount),
        ]);
        return $formattedResult;
    }

    /**
     * @param ClientAccount $clientAccount
     * @param int $priceLevel
     * @return float
     */
    public function getOriginPrice($clientAccount = null, $priceLevel = null, $isWithDiscount = false)
    {
        if (!$priceLevel) {
            $priceLevel = max(ClientAccount::DEFAULT_PRICE_LEVEL, $clientAccount ? $clientAccount->price_level : ClientAccount::DEFAULT_PRICE_LEVEL);
        }

        $didGroup = $this->getCachedDidGroup();
        return $didGroup ? $didGroup->getPrice($priceLevel, $isWithDiscount) : null;
    }

    /**
     * @param ClientAccount $clientAccount
     * @return NumberPriceLight
     */
    public function getOriginPriceWithCurrency($clientAccount = null)
    {
        $formattedResult = new NumberPriceLight;
        try {

            if (!$this->getCachedDidGroup()) {
                throw new \LogicException('DID group not found');
            }

            $formattedResult->setAttributes([
                'currency' => $this->getCachedDidGroup()->getCachedCountry()->currency_id,
                'price' => $this->getOriginPrice($clientAccount, null, $this->is_with_discount),
            ]);

        } catch (\Exception $e) {
            $formattedResult->setAttributes([
                'currency' => null,
                'price' => null,
            ]);
        }

        return $formattedResult;
    }

    /**
     * @return DidGroup
     */
    public function getCachedDidGroup()
    {
        static $cache = [];

        if (!isset($cache[$this->did_group_id])) {
            $cache[$this->did_group_id] = $this->didGroup;
        }

        return $cache[$this->did_group_id];
    }

    /**
     * @return string
     * @throws \yii\base\InvalidParamException
     */
    public function getUrl()
    {
        return self::getUrlById($this->number);
    }

    /**
     * Ссылка на страницу номера
     *
     * @param int $id
     * @return string
     * @throws \yii\base\InvalidParamException
     */
    public static function getUrlById($id)
    {
        return Url::to(['/voip/number/view', 'did' => $id]);
    }

    /**
     * Вернуть html: имя + ссылка
     *
     * @return string
     * @throws \yii\base\InvalidParamException
     */
    public function getLink()
    {
        return Html::a(Html::encode($this->number), $this->getUrl());
    }

    /**
     * Вернуть кол-во звонков за месяц
     *
     * @param string $month %02d
     * @return int
     */
    public function getCallsWithoutUsagesByMonth($month)
    {
        if (is_null($this->callsCount)) {
            $this->callsCount = self::dao()->getCallsWithoutUsages($this->city->connection_point_id, $this->number);
        }

        foreach ($this->callsCount as $calls) {
            if ($calls['m'] === $month) {
                return $calls['c'];
            }
        }

        return '';
    }

    /**
     * Получаем лог изменений состояния номера
     *
     * @return array
     * @throws \yii\db\Exception
     */
    public function getChangeStatusLog()
    {
        return self::dao()->getChangeStateLog($this);
    }

    /**
     * Мобильный и непортированный - значит, наш. Тогда FMC можно включить/выключить по желанию юзера
     *
     * @return bool
     */
    public function isFmcEditable()
    {
        return $this->ndc_type_id == NdcType::ID_MOBILE /* && !$this->is_ported */;
    }

    /**
     * @return bool
     */
    public function isMobileMcn()
    {
        return $this->isFmcEditable();
    }

    /**
     * Мобильный и портированный - FMC всегда включен.
     *
     * @return bool
     */
    public function isFmcAlwaysActive()
    {
        return $this->ndc_type_id == NdcType::ID_MOBILE && $this->is_ported;
    }

    /**
     * Немобильный - FMC всегда выключен.
     *
     * @return bool
     */
    public function isFmcAlwaysInactive()
    {
        return $this->ndc_type_id != NdcType::ID_MOBILE;
    }

    /**
     * Мобильный и непортированный - значит, наш. Тогда "Исх. моб. связь" можно включить/выключить по желанию юзера
     *
     * @return bool
     */
    public function isMobileOutboundEditable()
    {
        return $this->isFmcEditable();
    }

    /**
     * Мобильный и портированный - "Исх. моб. связь" всегда включен.
     *
     * @return bool
     */
    public function isMobileOutboundAlwaysActive()
    {
        return $this->isFmcAlwaysActive();
    }

    /**
     * Немобильный - "Исх. моб. связь" всегда выключен.
     *
     * @return bool
     */
    public function isMobileOutboundAlwaysInactive()
    {
        return $this->isFmcAlwaysInactive();
    }

    /**
     * @return City
     */
    public function getCityByNumber()
    {
        if ($this->city_id) {
            return $this->city;
        }

        $cities = $this->regionModel->cities;
        return reset($cities);
    }

    /**
     * Это линия без номера
     *
     * @param string $number
     * @return bool
     */
    public static function isMcnLine($number)
    {
        $numberLenth = strlen($number);
        return $numberLenth >= 4 && $numberLenth <= 5;
    }

    /**
     * Получить nnp-информацию по номеру
     *
     * @param number|string $number
     * @param bool $isWithPorting - с учетом портирования
     * @return array
     * @throws InvalidConfigException
     */
    public static function getNnpInfo($number, bool $isWithPorting = true)
    {
        $url = \Yii::$app->params['nnpInfoServiceURL'] ?? false;

        if (!$url) {
            throw new InvalidConfigException('nnpInfoServiceURL not set');
        }

        $count = 0;
        do {
            try {
                return (new HttpClient())
                    ->get($url, [
                        'cmd' => 'getNumberRangeByNum',
                        'num' => $number,
                    ] + ($isWithPorting ? [] : ['isWithoutPorted' => 1])
                    )
                    ->getResponseDataWithCheck();
            } catch (\Exception $e) {
                \Yii::error($e);
                $count++;
                sleep(1);
                if ($count > 3) {
                    throw $e;
                }
            }
        } while (true);
    }

    /**
     * Получить nnp-информацию по текущему номеру
     *
     * @return array
     * @throws InvalidConfigException
     */
    public function getNnpInfoData()
    {
        return self::getNnpInfo($this->number);
    }

    /**
     *
     *
     * @return mixed|null
     */
    public static function getMCNOperatorId()
    {
        return \Yii::$app->params['nnpMCNOperatorId'] ?? null;
    }

    /**
     * Принадлежит ли номер MCN Телеком
     *
     * @return bool
     * @throws InvalidConfigException
     */
    public function isMcnNumber()
    {
        $operatorId = self::getMCNOperatorId();
        if (empty($operatorId)) {
            return false;
        }

        $data = $this->getNnpInfoData();
        if (empty($data['nnp_operator_id'])) {
            return false;
        }

        return $data['nnp_operator_id'] === $operatorId;
    }

    /**
     * Номер был портирован к нам
     *
     * @return bool
     */
    public function isPorted()
    {
        return $this->source == VoipRegistrySourceEnum::PORTABILITY_NOT_FOR_SALE;
    }

    public function isRusMob()
    {
        return strpos((string)$this->number, '79') === 0;
    }

    /**
     * @return \app\modules\nnp\models\Number
     */
    public function getNnpPorted()
    {
        return \app\modules\nnp\models\Number::findOne(['full_number' => $this->number]);
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return (string)$this->number;
    }
}