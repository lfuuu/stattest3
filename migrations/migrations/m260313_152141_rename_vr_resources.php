<?php

use app\classes\Migration;
use app\modules\uu\models\ResourceModel;

/**
 * Переименование ресурсов голосового робота:
 * - Канальность -> Задания
 * - Карусель -> Скоростной обзвон
 */
class m260313_152141_rename_vr_resources extends Migration
{
    public function safeUp()
    {
        $this->update(
            ResourceModel::tableName(),
            ['name' => 'Задания'],
            ['id' => ResourceModel::ID_VR_TASKS]
        );

        $this->update(
            ResourceModel::tableName(),
            ['name' => 'Скоростной обзвон'],
            ['id' => ResourceModel::ID_VR_SPEED_DIAL]
        );
    }

    public function safeDown()
    {
        $this->update(
            ResourceModel::tableName(),
            ['name' => 'Канальность'],
            ['id' => ResourceModel::ID_VR_TASKS]
        );

        $this->update(
            ResourceModel::tableName(),
            ['name' => 'Карусель'],
            ['id' => ResourceModel::ID_VR_SPEED_DIAL]
        );
    }
}
