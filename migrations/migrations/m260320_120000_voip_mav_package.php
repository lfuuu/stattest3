<?php

use app\modules\uu\models\ServiceType;

/**
 * Class m260320_120000_voip_mav_package
 */
class m260320_120000_voip_mav_package extends \app\classes\Migration
{
    /**
     * Up
     */
    public function safeUp()
    {
        $this->insert(ServiceType::tableName(), [
            'id' => ServiceType::ID_VOIP_PACKAGE_MAV,
            'name' => 'Телефония. МАВ',
            'parent_id' => ServiceType::ID_VOIP,
            'close_after_days' => ServiceType::CLOSE_AFTER_DAYS,
        ]);
    }

    /**
     * Down
     */
    public function safeDown()
    {
        $this->delete(ServiceType::tableName(), ['id' => ServiceType::ID_VOIP_PACKAGE_MAV]);
    }
}
