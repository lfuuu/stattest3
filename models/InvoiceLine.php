<?php

namespace app\models;

use app\classes\model\ActiveRecord;
use app\helpers\DateTimeZoneHelper;

/**
 * Строка в счёте-фактуре
 *
 * @property integer $pk
 * @property integer $invoice_id
 * @property string $bill_no
 * @property integer $sort
 * @property string $item
 * @property float $amount
 * @property float $price
 * @property float $sum
 * @property string $date_from
 * @property string $date_to
 * @property string $type
 * @property integer $tax_rate
 * @property float $sum_without_tax
 * @property float $sum_tax
 * @property integer $line_id
 * @property-read float $price_per_unit
 *
 * @property-read Invoice $invoice
 * @property-read BillLine $line
 */
class InvoiceLine extends ActiveRecord
{
    /**
     * @return string
     */
    public static function tableName()
    {
        return 'invoice_lines';
    }

    public function rules()
    {
        return [
            [['invoice_id', 'item', 'amount', 'price'], 'required'],
            [['sum_without_tax', 'sum_tax'], 'number'],
            ['type', 'default', 'value' => BillLine::LINE_TYPE_SERVICE],
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
    public function getLine()
    {
        return $this->hasOne(BillLine::class, ['pk' => 'line_id']);
    }

    /**
     * Рассчитать все суммы до сохранения
     *
     * @param bool $insert
     * @return bool
     */
    public function beforeSave($insert)
    {
        $this->calculateSum($this->invoice->bill->price_include_vat);

        return parent::beforeSave($insert);
    }

    /**
     * @param boolean $priceIncludeVat
     */
    public function calculateSum($priceIncludeVat)
    {
        $sum = $this->price * $this->amount;

        if ($priceIncludeVat) {
            $this->sum = round($sum, 2);
            $this->sum_tax = round($this->tax_rate / (100.0 + $this->tax_rate) * $this->sum, 2);
            $this->sum_without_tax = $this->sum - $this->sum_tax;
        } else {
            $this->sum_without_tax = round($sum, 2);
            $this->sum_tax = round($this->sum_without_tax * $this->tax_rate / 100, 2);
            $this->sum = $this->sum_without_tax + $this->sum_tax;
        }
    }

    /**
     * Дата счёта-фактуры в timestamp
     *
     * @throws \Exception
     */
    public function setDates()
    {
        $endDate = (new \DateTimeImmutable($this->invoice->bill->bill_date))
            ->modify('last day of ' . ($this->invoice->type_id == 1 ? 'this' : 'previous') . ' month');

        $startDate = $endDate->modify('first day of this month');

        $this->date_from = $startDate->format(DateTimeZoneHelper::DATE_FORMAT);
        $this->date_to = $endDate->format(DateTimeZoneHelper::DATE_FORMAT);
    }

    /**
     * @return float
     */
    public function getVat()
    {
        return $this->sum_tax;
    }

    /**
     * @return float
     */
    public function getPrice_without_vat()
    {
        return $this->sum_without_tax;
    }

    /**
     * @return float
     */
    public function getPrice_with_vat()
    {
        return $this->sum;
    }

    /**
     * @return float
     */
    public function getAmount()
    {
        return $this->amount;
    }

    /**
     * @return string
     */
    public function getFullName()
    {
        return $this->item;
    }

    /**
     * @return string
     */
    public function getTypeUnitName()
    {
        return $this->type == BillLine::LINE_TYPE_GOOD ? 'шт.' : '-';
    }

    /**
     * @return int
     */
    public function getVat_rate()
    {
        return $this->tax_rate;
    }

    /**
     * @return float
     */
    public function getOutprice()
    {
        return abs(($this->sum_without_tax / $this->amount) - $this->price) < 0.1 ? $this->price : round($this->sum_without_tax / $this->amount, 4);
    }

    public function getPrice_per_unit()
    {
        return $this->amount > 0 ? $this->sum_without_tax / $this->amount : 0;
    }

    /**
     * @return null|string
     */
    public function getGtd()
    {
        if ($this->type != BillLine::LINE_TYPE_GOOD) {
            return null;
        }

        if (!$this->invoice || !$this->invoice->bill) {
            return null;
        }

        if ($this->line_id) {
            return $this->line->gtd;
        }

        return $this->invoice->bill->getLines()->where(['item' => $this->item, 'price' => $this->price])->select('gtd')->scalar();
    }

    public function getCountry_Id()
    {
        if ($this->type != BillLine::LINE_TYPE_GOOD) {
            return null;
        }

        if (!$this->invoice || !$this->invoice->bill) {
            return null;
        }

        if ($this->line_id) {
            return $this->line->country_id ?: Country::DEFAULT_GOOD_COUNTRY;
        }

        return $this->invoice->bill->getLines()->where(['item' => $this->item, 'price' => $this->price])->select('country_id')->scalar() ?: Country::DEFAULT_GOOD_COUNTRY;
    }

    public function getCountry_name()
    {
        if ($this->type != BillLine::LINE_TYPE_GOOD) {
            return null;
        }

        if (!$this->invoice || !$this->invoice->bill) {
            return null;
        }


        if ($this->line_id) {
            $row = $this->line->getAttributes(['contry_maker', 'country_id']);
        } else {
            $row = $this->invoice->bill->getLines()->where(['item' => $this->item, 'price' => $this->price])->select(['contry_maker', 'country_id'])->asArray()->one();
        }

        if (!is_array($row)) {
            $row = [];
        }

        if (!$row || !isset($row['country_id']) || !$row['country_id']) {
            $row['country_id'] = Country::DEFAULT_GOOD_COUNTRY;
        }

        if ($row['country_maker']) {
            return $row['country_maker'];
        }

        if ($row['country_id']) {
            return mb_strtoupper(Country::find()->where(['code' => $row['country_id']])->select('name_rus')->scalar());
        }

        return null;
    }

    public function __toString()
    {
        return $this->item;
    }
}