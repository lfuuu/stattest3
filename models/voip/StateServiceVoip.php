<?php

namespace app\models\voip;

use app\classes\model\ActiveRecord;
use app\classes\traits\GridSortTrait;
use app\classes\validators\FormFieldValidator;
use app\models\ClientAccount;
use app\models\UsageVoip;
use app\modules\uu\models\AccountTariff;
use Yii;

/**
 * Class StateServiceVoip
 * @property int usage_id
 * @property int client_id
 * @property int e164
 * @property int region
 * @property string actual_from
 * @property string actual_to
 * @property string activation_dt
 * @property string expire_dt
 * @property int lines_amount
 * @property string device_address
 * @property int $iccid
 * @property int $imsi
 *
 * @property-read ClientAccount clientAccount
 * @property-read AccountTariff accountTariff
 * @property-read UsageVoip usageVoip
 */
class StateServiceVoip extends ActiveRecord
{
    public static $primaryField = 'usage_id'; // for sorting in grid

    /**
     * @return array
     */
    public function attributeLabels()
    {
        return [
            'usage_id' => 'Услуга',
            'client_id' => 'УЛС',
            'e164' => 'Номер',
            'region' => 'Регион',
            'actual_from' => 'дата "С"',
            'actual_to' => 'дата "По"',
            'activation_dt' => 'время "С"',
            'expire_dt' => 'время "По"',
            'lines_amount' => 'Кол-во линий',
            'device_address' => 'Адрес установки оборудования',
            'iccid' => 'ICCID',
            'imsi' => 'IMSI',
        ];
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            [['usage_id', 'client_id', 'e164', 'region', 'lines_amount', 'imsi', 'iccid'], 'integer'],
            [['actual_from', 'actual_to', 'activation_dt', 'expire_dt', 'device_address'], 'string'],
            ['device_address', FormFieldValidator::class],
        ];
    }

    /**
     * @return string
     */
    public static function tableName()
    {
        return 'state_service_voip';
    }

    /**
     * @return string[]
     */
    public static function primaryKey()
    {
        return ['usage_id'];
    }


    public function getClientAccount()
    {
        return $this->hasOne(ClientAccount::class, ['id' => 'client_id']);
    }


    public function getAccountTariff()
    {
        return $this->hasOne(AccountTariff::class, ['id' => 'usage_id']);
    }

    public function getUsageVoip()
    {
        return $this->hasOne(UsageVoip::class, ['id' => 'usage_id']);
    }

    public function __toString()
    {
        return preg_replace('/\s+/', ' ', var_export($this->getAttributes(), true));
    }
}