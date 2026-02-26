<?php

use app\models\voip\StateServiceVoip;

class m260226_142510_state_voip_imsi_iccid extends \app\classes\Migration
{
    public function safeUp()
    {
        $table = StateServiceVoip::tableName();
        $this->addColumn($table, 'iccid', $this->bigInteger()->null()->after('is_verified'));
        $this->addColumn($table, 'imsi', $this->bigInteger()->null()->after('iccid'));
    }

    public function safeDown()
    {
        $table = StateServiceVoip::tableName();
        $this->dropColumn($table, 'imsi');
        $this->dropColumn($table, 'iccid');
    }
}
