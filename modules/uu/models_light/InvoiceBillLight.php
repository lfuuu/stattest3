<?php

namespace app\modules\uu\models_light;

use app\helpers\DateTimeZoneHelper;
use app\classes\BillQRCode;
use app\models\Bill;
use app\models\Invoice;
use app\models\Payment;
use app\modules\uu\models\Bill as uuBill;
use yii\base\Component;
use yii\base\InvalidParamException;

class InvoiceBillLight extends Component implements InvoiceLightInterface
{

    public
        $id = 0,
        $date,
        $payment_date,
        $pay_bill_until,
        $summary_without_vat = 0,
        $summary_vat = 0,
        $summary_with_vat = 0,
        $payment_type = '',
        $original_id = '',
        $client_id,
        $qr_code = '';

    private $_language;
    private $_qrDocType;
    private $_isPdf;

    /**
     * @param Bill|uuBill $bill
     * @param Invoice $invoice
     * @param string $language
     * @param string|null $qrDocType
     * @param bool $isPdf
     */
    public function __construct($bill, $invoice, $language, $qrDocType = null, $isPdf = false)
    {
        parent::__construct();

        $this->id = $invoice ? $invoice->number : ($bill instanceof Bill ? $bill->bill_no : $bill->id);

        if ($invoice && $invoice->is_reversal && ($rInvoice = $invoice->getReversalInvoice())) {
            $this->original_id = $rInvoice->number;
        }

        $this->_language = $language;
        $this->_qrDocType = $qrDocType;
        $this->_isPdf = $isPdf;

        $statBill = $this->_getStatBill($bill);

//        $this->date = $invoice && ($invoice->is_reversal || $invoice->pay_bill_until) ? (new \DateTimeImmutable($invoice->date))->format(DateTimeZoneHelper::DATE_FORMAT) : $statBill->date;
        $this->date = $invoice ? (new \DateTimeImmutable($invoice->date))->format(DateTimeZoneHelper::DATE_FORMAT) : ($statBill ? $statBill->date : $bill->date);

        if ($bill instanceof uuBill && !$bill->is_converted) { // current statenent
            $this->date = (new \DateTimeImmutable('now'))->format(DateTimeZoneHelper::DATE_FORMAT);
        }

        if (!$statBill) {
            return;
        }

        $this->pay_bill_until = $invoice->pay_bill_until ?: $statBill->pay_bill_until;

        $this->_setPaymentDate($statBill);
        $this->_setPaymentType($statBill);

        $this->client_id = $statBill->client_id;

        $docType = $this->_getQrDocType($invoice);
        $this->qr_code = $this->_isPdf
            ? $this->_getInlineQrData($statBill->bill_no, $docType)
            : $this->_getAbsoluteQrUrl($statBill->bill_no, $docType);
    }

    /**
     * @return string
     */
    public function getLanguage()
    {
        return $this->_language;
    }

    /**
     * @param float $value
     * @return $this
     */
    public function setSummaryVat($value)
    {
        $this->summary_vat += $value;
        return $this;
    }

    /**
     * @param float $value
     * @return $this
     */
    public function setSummaryWithoutVat($value)
    {
        $this->summary_without_vat += $value;
        return $this;
    }

    /**
     * @param float $value
     * @return $this
     */
    public function setSummaryWithVat($value)
    {
        $this->summary_with_vat += $value;
        return $this;
    }

    /**
     * @return string
     */
    public static function getKey()
    {
        return 'bill';
    }

    /**
     * @return string
     */
    public static function getTitle()
    {
        return 'Данные о счете';
    }

    /**
     * @return array
     */
    public static function attributeLabels()
    {
        return [
            'id' => 'Номер счета',
            'date' => 'Дата выставления счета',
            'payment_date' => 'Дата первой оплаты счета',
            'pay_bill_until' => 'Дата, до которой надо оплатить счет',
            'summary_without_vat' => 'Сумма счета без НДС',
            'summary_vat' => 'Сумма НДС',
            'summary_with_vat' => 'Сумма счета с НДС',
            'client_id' => 'Номер ЛС клиента',
            'qr_code' => 'QR-код',
        ];
    }

    /**
     * Получаем статовский счет
     *
     * @param Bill|uuBill $bill
     * @return Bill
     */
    private function _getStatBill($bill)
    {
        if ($bill instanceof uuBill) {
            $bill = Bill::findOne(['uu_bill_id' => $bill->id]);
        }

        if (!$bill) {
            return null;
        }

        if (!($bill instanceof Bill)) {
            throw new InvalidParamException('Счет не найден');
        }

        return $bill;
    }

    /**
     * Утсанавливаем дату платежа
     *
     * @param Bill $bill
     */
    private function _setPaymentDate(Bill $bill)
    {
        $this->payment_date = Payment::find()
            ->where([
                'bill_no' => $bill->bill_no
            ])
            ->orderBy([
                'payment_date' => SORT_ASC
            ])
            ->select('payment_date')
            ->scalar();
    }

    private function _setPaymentType(Bill $bill)
    {
        $this->payment_type = \Yii::t('biller', $bill->nal, [], $this->_language);
    }

    /**
     * @param Invoice|null $invoice
     * @return string
     */
    private function _getQrDocType($invoice)
    {
        if ($this->_qrDocType) {
            return $this->_qrDocType;
        }

        if (!$invoice) {
            return 'bill';
        }

        $typeId = $invoice->type_id;
        $map = [
            Invoice::TYPE_1 => 'upd-1',
            Invoice::TYPE_2 => 'upd-2',
            Invoice::TYPE_GOOD => 'upd-3',
            Invoice::TYPE_PREPAID => 'upd-1',
        ];

        return $map[$typeId] ?? 'bill';
    }

    /**
     * @param string $billNo
     * @param string $docType
     * @return string
     */
    private function _getAbsoluteQrUrl($billNo, $docType)
    {
        $qrUrl = BillQRCode::getImgUrl($billNo, $docType);
        if ($qrUrl && strpos($qrUrl, 'http') !== 0) {
            $qrUrl = \Yii::$app->params['SITE_URL'] . ltrim($qrUrl, '/');
        }
        return $qrUrl;
    }

    /**
     * @param string $billNo
     * @param string $docType
     * @return string
     */
    private function _getInlineQrData($billNo, $docType)
    {
        $data = BillQRCode::encode($docType, $billNo);
        if (!$data) {
            return '';
        }

        $imageData = BillQRCode::generateGifData($data);
        if ($imageData === '') {
            return '';
        }

        return 'data:image/gif;base64,' . base64_encode($imageData);
    }
}