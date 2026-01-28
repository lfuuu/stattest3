<?php

/**
 * Class m260127_120000_voip_number_blocked_status
 */
class m260127_120000_voip_number_blocked_status extends \app\classes\Migration
{

    /**
     * Up
     */
    public function safeUp()
    {
        $this->alterColumn(\app\models\Number::tableName(), 'status',
            "ENUM ('notsale', 'instock', 'active_tested', 'active_commercial', 'notactive_reserved', 'notactive_hold', 'released', 'released_and_ported', 'active_connected', 'not_verfied', 'active_msteams', 'blocked_by_subscriber', 'blocked_by_operator') DEFAULT 'notsale' NOT NULL",
        );

        $this->addColumn(\app\models\Number::tableName(), 'forced_status', $this->string(50)->null()->comment('Внешне установленный статус'));
    }

    /**
     * Down
     */
    public function safeDown()
    {
        $this->alterColumn(\app\models\Number::tableName(), 'status',
            "ENUM ('notsale', 'instock', 'active_tested', 'active_commercial', 'notactive_reserved', 'notactive_hold', 'released', 'released_and_ported', 'active_connected', 'not_verfied', 'active_msteams') DEFAULT 'notsale' NOT NULL",
        );
        $this->dropColumn(\app\models\Number::tableName(), 'forced_status');
    }
}

