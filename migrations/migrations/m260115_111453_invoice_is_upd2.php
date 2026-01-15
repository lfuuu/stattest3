<?php

/**
 * Class m260115_111453_invoice_is_upd2
 */
class m260115_111453_invoice_is_upd2 extends \app\classes\Migration
{
    /**
     * Up
     */
    public function safeUp()
    {
        $this->addColumn(\app\models\Invoice::tableName(), 'is_upd2', $this->tinyInteger()->notNull()->defaultValue(0));
    }

    /**
     * Down
     */
    public function safeDown()
    {
        $this->dropColumn(\app\models\Invoice::tableName(), 'is_upd2');
    }
}
