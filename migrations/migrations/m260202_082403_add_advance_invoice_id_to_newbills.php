<?php

use app\models\Bill;
use app\models\Invoice;

/**
 * Class m260202_082403_add_advance_invoice_id_to_newbills
 */
class m260202_082403_add_advance_invoice_id_to_newbills extends \app\classes\Migration
{
 /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn(Bill::tableName(), 'advance_invoice_id', $this->integer()->null());
        $this->addForeignKey(
            'fk-newbills-advance_invoice_id',
            Bill::tableName(),
            'advance_invoice_id',
            Invoice::tableName(),
            'id',
            'SET NULL',
            'CASCADE'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropForeignKey('fk-newbills-advance_invoice_id', Bill::tableName());
        $this->dropColumn(Bill::tableName(), 'advance_invoice_id');
    }
}
