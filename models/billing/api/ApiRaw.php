<?php

namespace app\models\billing\api;

use app\classes\model\ActiveRecord;
use app\models\billing\api\ApiMethod;
use app\dao\billing\ApiRawDao;
use Yii;

/**
 * @property int $id
 * @property int $server_id
 * @property bool $orig
 * @property int $cdr_id
 * @property int $connect_time
 * @property int $account_id
 * @property int $api_id
 * @property int $api_method_id
 * @property int $service_api_id
 * @property int $api_pricelist_id
 * @property int $api_pricelist_item_id
 * @property int $api_count
 * @property float $api_weight
 * @property float $rate
 * @property float $cost
 * @property float $price_rate
 * @property string $cost_currency_id
 * @property int $nnp_package_api_id
 * @property int $account_tariff_light_id
 * @property string $mcn_api_call_uuid
 */
class ApiRaw extends ActiveRecord
{

    /**
     * @return string
     */
    public static function tableName()
    {
        return 'api_raw.api_raw';
    }

    /**
     * Returns the database connection
     *
     * @return \yii\db\Connection
     */
    public static function getDb()
    {
        return Yii::$app->dbPgNnp;
    }   

    /**
     * Навзание полей
     *
     * @return array
     */
    public function attributeLabels()
    {
        return [
            'connect_time' => 'Время вызова, UTC',
            'api_method_id' => 'API-метод',
            'rate' => 'Ставка',
            'cost' => 'Стоимость',
            'api_weight' => '\'Вес\' вызова',
        ];
    }

    /**
     * @return ApiRawDao
     * @throws \yii\base\Exception
     */
    public static function dao()
    {
        return ApiRawDao::me();
    }

    public function getMethod()
    {
        return $this->hasOne(ApiMethod::class, ['id' => 'api_method_id']);
    }
}
