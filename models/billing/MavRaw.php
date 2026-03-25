<?php

namespace app\models\billing;

use app\classes\model\ActiveRecord;
use Yii;

/**
 * @property int $server_id
 * @property int $id
 * @property bool $orig
 * @property int $cdr_id
 * @property string $connect_time
 * @property int $trunk_id
 * @property int $account_id
 * @property int $number_service_id
 * @property int $src_number
 * @property int $dst_number
 * @property int $billed_time
 * @property float $rate
 * @property float $cost
 * @property string $signalling_call_id
 * @property int $account_tariff_light_id
 * @property string $mcn_callid
 * @property int $session_time
 * @property int $nnp_pricelist_id
 * @property string $nas_ip
 * @property int $instance_id
 * @property bool $is_mav
 * @property bool $is_label
 */
class MavRaw extends ActiveRecord
{
    public static function tableName()
    {
        return 'mm_raw.mm_raw';
    }

    public static function getDb()
    {
        return Yii::$app->dbPg;
    }

    public function attributeLabels()
    {
        return [
            'account_id' => 'ЛС',
            'connect_time' => 'Время вызова (UTC)',
            'src_number' => 'Номер А',
            'dst_number' => 'Номер Б',
            'billed_time' => 'Длительность, сек',
            'session_time' => 'Время сессии, сек',
            'rate' => 'Ставка',
            'cost' => 'Стоимость',
        ];
    }
}
