<?php

use app\models\Invoice;

/**
 * Class m260224_110105_add_is_hide_payment_number_to_invoice
 */
class m260224_110105_add_is_hide_payment_number_to_invoice extends \app\classes\Migration
{
    public function safeUp()
    {
        $this->addColumn(
            Invoice::tableName(),
            'is_hide_payment_number',
            $this->tinyInteger(1)->notNull()->defaultValue(0)
        );
    }

    public function safeDown()
    {
        $this->dropColumn(Invoice::tableName(), 'is_hide_payment_number');
    }
}
