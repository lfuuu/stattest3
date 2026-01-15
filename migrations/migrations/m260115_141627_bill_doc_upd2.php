<?php

/**
 * Class m260115_141627_bill_doc_upd2
 */
class m260115_141627_bill_doc_upd2 extends \app\classes\Migration
{
    /**
     * Up
     */
    public function safeUp()
    {
        $this->addColumn(\app\models\BillDocument::tableName(), 'upd2_1', $this->tinyInteger()->notNull()->defaultValue(0));
        $this->addColumn(\app\models\BillDocument::tableName(), 'upd2_2', $this->tinyInteger()->notNull()->defaultValue(0));
    }

    /**
     * Down
     */
    public function safeDown()
    {
        $this->dropColumn(\app\models\BillDocument::tableName(), 'upd2_1');
        $this->dropColumn(\app\models\BillDocument::tableName(), 'upd2_2');
    }
}
