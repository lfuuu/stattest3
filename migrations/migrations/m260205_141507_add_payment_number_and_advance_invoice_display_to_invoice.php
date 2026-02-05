<?php

use app\models\Invoice;

/**
 * Class m260205_141507_add_payment_number_and_advance_invoice_display_to_invoice
 */
class m260205_141507_add_payment_number_and_advance_invoice_display_to_invoice extends \app\classes\Migration
{
    public function safeUp()
    {
        $this->addColumn(Invoice::tableName(), 'upd_payment_number', $this->string(255)->null());
        $this->addColumn(Invoice::tableName(), 'upd_advance_invoice_display', $this->string(255)->null());
    }

    public function safeDown()
    {
        $this->dropColumn(Invoice::tableName(), 'upd_advance_invoice_display');
        $this->dropColumn(Invoice::tableName(), 'upd_payment_number');
    }
}
