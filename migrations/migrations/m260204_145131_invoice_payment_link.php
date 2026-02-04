<?php

use app\classes\Migration;
use app\models\ClientAccount;
use app\models\Invoice;
use app\models\InvoicePaymentLink;
use app\models\Payment;


/**
 * Class m260204_145131_invoice_payment_link
 */
class m260204_145131_invoice_payment_link extends Migration
{
    /**
     * Up
     */
    public function safeUp()
    {
        $this->createTable(InvoicePaymentLink::tableName(), [
            'id' => $this->primaryKey()->unsigned(),
            'invoice_id' => $this->integer()->notNull(),
            'payment_id' => $this->integer()->unsigned()->notNull(),
            'client_account_id' => $this->integer()->notNull(),
            'is_matched' => $this->tinyInteger(1)->notNull()->defaultValue(0),
            'sum' => $this->decimal(12, 2)->notNull()->defaultValue(0),
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8');

        $this->createIndex('idx-client_account_id', InvoicePaymentLink::tableName(), 'client_account_id');

        $this->addForeignKey(
            'fk-invoice_payment_link-invoice_id',
            InvoicePaymentLink::tableName(), 'invoice_id',
            Invoice::tableName(), 'id',
            'CASCADE', 'CASCADE'
        );

        $this->addForeignKey(
            'fk-invoice_payment_link-payment_id',
            InvoicePaymentLink::tableName(), 'payment_id',
            Payment::tableName(), 'id',
            'CASCADE', 'CASCADE'
        );

        $this->addForeignKey(
            'fk-invoice_payment_link-client_account_id',
            InvoicePaymentLink::tableName(), 'client_account_id',
            ClientAccount::tableName(), 'id',
            'CASCADE', 'CASCADE'
        );
    }

    /**
     * Down
     */
    public function safeDown()
    {
        $this->dropForeignKey('fk-invoice_payment_link-client_account_id', InvoicePaymentLink::tableName());
        $this->dropForeignKey('fk-invoice_payment_link-payment_id', InvoicePaymentLink::tableName());
        $this->dropForeignKey('fk-invoice_payment_link-invoice_id', InvoicePaymentLink::tableName());
        $this->dropTable(InvoicePaymentLink::tableName());
    }

}