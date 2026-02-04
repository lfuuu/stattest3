<?php

namespace app\models;

use app\classes\model\ActiveRecord;

/**
 * Связь Invoice и Payment
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $payment_id
 * @property int $client_account_id
 * @property int $is_matched
 * @property float $sum
 *
 * @property-read Invoice $invoice
 * @property-read Payment $payment
 * @property-read ClientAccount $clientAccount
 */
class InvoicePaymentLink extends ActiveRecord
{
    /**
     * @return string
     */
    public static function tableName()
    {
        return 'invoice_payment_link';
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            [['invoice_id', 'payment_id', 'client_account_id'], 'required'],
            [['invoice_id', 'payment_id', 'client_account_id', 'is_matched'], 'integer'],
            [['sum'], 'number'],
            ['is_matched', 'default', 'value' => 0],
            ['sum', 'default', 'value' => 0],
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getInvoice()
    {
        return $this->hasOne(Invoice::class, ['id' => 'invoice_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getPayment()
    {
        return $this->hasOne(Payment::class, ['id' => 'payment_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getClientAccount()
    {
        return $this->hasOne(ClientAccount::class, ['id' => 'client_account_id']);
    }
}

