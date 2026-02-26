<?php

namespace app\modules\sim\models;

use app\classes\behaviors\HistoryChanges;
use app\classes\Html;
use app\classes\model\ActiveRecord;
use app\classes\traits\AttributeLabelsTraits;
use app\models\Number;
use app\modules\sim\behaviors\AccountTariffVoipImsiBehavior;
use app\modules\sim\behaviors\ImsiBehavior;
use app\modules\sim\behaviors\ImsiTele2StatusBehavior;
use app\modules\sim\dao\ImsiDao;
use Yii;
use yii\db\ActiveQuery;
use yii\db\Connection;
use yii\helpers\Url;

/**
 * Профиль абонента на SIM-карте
 *
 * @property int $imsi IMSI = International Mobile Subscriber Identity
 * @property int $iccid Родительская SIM-карта. ICCID = Integrated Circuit Card Id
 * @property int $msisdn MSISDN = Mobile Subscriber Integrated Services Digital Number
 * @property int $did DID = Direct Inward Dialing
 * @property int $is_anti_cli Анти-АОН. АОН = CLI = Calling Line Identification
 * @property int $is_roaming
 * @property int $is_active
 * @property int $status_id
 * @property int $partner_id
 * @property int $is_default
 * @property int $profile_id
 * @property string $actual_from
 * @property string $actual_to
 *
 * @property-read Card $card
 * @property-read Number $number
 * @property-read ImsiStatus $status
 * @property-read ImsiPartner $partner
 * @property-read ImsiProfile $profile
 * @property-read ImsiToken $token
 * @property-read ImsiExternalStatusLog $externalStatusLog
 *
 * @method static Imsi findOne($condition)
 * @method static Imsi[] findAll($condition)
 */
class Imsi extends ActiveRecord
{
    // Перевод названий полей модели
    use AttributeLabelsTraits;

    const PARTNER_MTT = 1;

    const imsiPrefixRegExp = '/^25037/'; // Tele2 imsi prefix

    /**
     * @return string
     */
    public static function tableName()
    {
        return 'billing_uu.sim_imsi';
    }

    /**
     * Returns the database connection
     *
     * @return Connection
     */
    public static function getDb()
    {
        return Yii::$app->dbPgNnp;
    }

    /**
     * @return string[]
     */
    public static function primaryKey()
    {
        return ['imsi'];
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            [['imsi', 'iccid'], 'required'],
            [['imsi', 'iccid', 'msisdn', 'did', 'is_active', 'is_anti_cli', 'is_roaming', 'status_id', 'partner_id', 'profile_id', 'is_default'], 'integer'],
            [['actual_from', 'actual_to'], 'date', 'format' => 'php:Y-m-d'],
            [['did', 'msisdn'], 'default', 'value' => null], // иначе пустая строка получается, ибо в БД это поле varchar
            ['did', 'exist', 'skipOnEmpty' => true, 'skipOnError' => true, 'targetClass' => Number::class, 'targetAttribute' => ['did' => 'number']],
            ['msisdn', 'exist', 'skipOnEmpty' => true, 'skipOnError' => true, 'targetClass' => Number::class, 'targetAttribute' => ['msisdn' => 'number']],
        ];
    }

    /**
     * @return array
     */
    public function behaviors()
    {
        return array_merge(
            parent::behaviors(),
            [
                HistoryChanges::class,
                ImsiBehavior::class,
                ImsiTele2StatusBehavior::class,
                AccountTariffVoipImsiBehavior::class,
            ]
        );
    }

    public static function dao()
    {
        return ImsiDao::me();
    }


    /**
     * @return ActiveQuery
     */
    public function getCard()
    {
        return $this->hasOne(Card::class, ['iccid' => 'iccid']);
    }

    /**
     * @return ActiveQuery
     */
    public function getNumber()
    {
        return $this->hasOne(Number::class, ['number' => 'msisdn']);
    }

    /**
     * @return ActiveQuery
     */
    public function getStatus()
    {
        return $this->hasOne(ImsiStatus::class, ['id' => 'status_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getPartner()
    {
        return $this->hasOne(ImsiPartner::class, ['id' => 'partner_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getProfile()
    {
        return $this->hasOne(ImsiProfile::class, ['id' => 'profile_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getToken()
    {
        return $this->hasOne(ImsiToken::class, ['imsi' => 'imsi']);
    }

    /**
     * @return ActiveQuery
     */
    public function getExternalStatusLog()
    {
        return $this->hasMany(ImsiExternalStatusLog::class, ['imsi' => 'imsi']);
    }

    /**
     * Вернуть ID родителя
     *
     * @return int
     */
    public function getParentId()
    {
        return $this->iccid;
    }

    /**
     * Установить ID родителя
     *
     * @param int $parentId
     */
    public function setParentId($parentId)
    {
        $this->iccid = $parentId;
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

            case 'did':
                if ($number = Number::findOne(['number' => $value])) {
                    return $number->getLink();
                }
                break;
        }

        return $value;
    }

    /**
     * Ссылка на услугу
     *
     * @return string
     */
    public function getLink()
    {
        return Html::a($this->iccid, $this->getUrl());
    }

    public function getUrl()
    {
        return Url::to(['/sim/card/edit', 'originIccid' => $this->iccid]);
    }

    public function __toString()
    {
        return 'IMSI: ' . $this->imsi . '(iccid: ' . $this->iccid . ')';
    }
}
