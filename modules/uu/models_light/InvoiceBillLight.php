<?php

namespace app\modules\uu\models_light;

use app\dao\ClientContractDao;
use app\helpers\DateTimeZoneHelper;
use app\classes\BillQRCode;
use app\models\Bill;
use app\models\ClientAccount;
use app\models\ClientAccountOptions;
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
        $correction_number = null,
        $correction_date = null,
        $client_id,
        $bill_no,
        $bill_date,
        $reason_for_transfer = '',
        $pageCount = 1,
        $qr_code = '',
        $upd_payment_number,
        $upd_advance_invoice;

    private $_language;
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
        $this->_isPdf = $isPdf;

        $statBill = $this->_getStatBill($bill);

//        $this->date = $invoice && ($invoice->is_reversal || $invoice->pay_bill_until) ? (new \DateTimeImmutable($invoice->date))->format(DateTimeZoneHelper::DATE_FORMAT) : $statBill->date;
        $this->date = $invoice ? (new \DateTimeImmutable($invoice->date))->format(DateTimeZoneHelper::DATE_FORMAT) : ($statBill ? $statBill->date : $bill->date);

        if ($invoice && $invoice->correction_idx) {
            $this->correction_number = $invoice->correction_idx;
            $this->correction_date = (new \DateTimeImmutable($invoice->date))->format(DateTimeZoneHelper::DATE_FORMAT);
        }

        if ($bill instanceof uuBill && !$bill->is_converted) { // current statenent
            $this->date = (new \DateTimeImmutable('now'))->format(DateTimeZoneHelper::DATE_FORMAT);
        }

        if (!$statBill) {
            return;
        }

        $this->pay_bill_until = $invoice->pay_bill_until ?: $statBill->pay_bill_until;
        $this->bill_no = $statBill->bill_no;
        $this->bill_date = $statBill->date;
        $this->_setReasonForTransfer($statBill);

        $this->_setPaymentDate($statBill);
        $this->_setPaymentType($statBill);

        $this->client_id = $statBill->client_id;

        if ($invoice) {
            $this->upd_payment_number = $invoice->upd_payment_number;
            $this->upd_advance_invoice = $invoice->upd_advance_invoice;
        }

        $docType = $invoice
            ? $invoice->getQrDocType($qrDocType)
            : 'bill';
        $this->qr_code = $this->_isPdf
            ? BillQRCode::getImgDataUri($statBill->bill_no, $docType)
            : BillQRCode::getImgUrl($statBill->bill_no, $docType);
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

    public function setPageCount($count)
    {
        $this->pageCount = $count;
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

    public static function reasonForTransferUpd(ClientAccount $clientAccount, Bill $bill): array
    {
        $billDateTime = new \DateTime($bill->date);

        switch ($clientAccount->getOptionValue(ClientAccountOptions::OPTION_SBIS_DOC_BASE)) {
            case ClientAccountOptions::OPTION_SBIS_DOC_BASE_BILL:
                $billNumber = (string)$bill->bill_no;
                $billDate = $billDateTime->format(DateTimeZoneHelper::DATE_FORMAT);
                $billDateHuman = $billDateTime->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED);

                return [
                    'type' => ClientAccountOptions::OPTION_SBIS_DOC_BASE_BILL,
                    'title' => 'Счет',
                    'number' => $billNumber,
                    'date' => $billDate,
                    'date_human' => $billDateHuman,
                    'sbis_name' => 'Счет',
                    'upd_name' => sprintf('Счет №%s от %s', $billNumber, $billDateHuman),
                ];

            case ClientAccountOptions::OPTION_SBIS_DOC_BASE_CONTRACT:
                $contract = ClientContractDao::me()->getContractInfo($clientAccount->contract, $billDateTime);
                $contractDateTime = new \DateTime($contract->contract_date, new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_DEFAULT));
                $contractDate = $contractDateTime->format(DateTimeZoneHelper::DATE_FORMAT);
                $contractDateHuman = $contractDateTime->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED);
                $contractNumber = (string)$contract->contract_no;
                $contractName = sprintf('Договор №%s от %s', $contractNumber, $contractDateHuman);

                return [
                    'type' => ClientAccountOptions::OPTION_SBIS_DOC_BASE_CONTRACT,
                    'title' => 'Договор',
                    'number' => $contractNumber,
                    'date' => $contractDate,
                    'date_human' => $contractDateHuman,
                    'sbis_name' => $contractName,
                    'upd_name' => $contractName,
                ];

            default:
                throw new \InvalidArgumentException('СБИС. Непонятное основание');
        }
    }

    /**
     * @param Bill $bill
     */
    private function _setReasonForTransfer(Bill $bill)
    {
        $reasonForTransfer = self::reasonForTransferUpd($bill->clientAccount, $bill);
        $this->reason_for_transfer = $reasonForTransfer['upd_name'];
    }

} 
