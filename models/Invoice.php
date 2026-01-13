<?php

namespace app\models;

use app\classes\behaviors\InvoiceGeneratePdf;
use app\classes\behaviors\InvoiceNextIdx;
use app\classes\behaviors\InvoiceSetFlags;
use app\classes\Encrypt;
use app\classes\HttpClient;
use app\classes\model\ActiveRecord;
use app\dao\InvoiceDao;
use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\modules\sbisTenzor\models\SBISGeneratedDraft;
use app\modules\uu\models_light\InvoiceLight;
use yii\base\InvalidCallException;
use yii\db\Expression;
use yii\helpers\Url;
use yii\web\NotAcceptableHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Счёт-фактура
 *
 * @property int $id
 * @property string $number
 * @property int $organization_id
 * @property string $bill_no
 * @property int $idx
 * @property int $type_id
 * @property string $date
 * @property float $sum
 * @property float $sum_tax
 * @property float $sum_without_tax
 * @property bool $is_reversal
 * @property string $add_date
 * @property string $reversal_date
 * @property int $correction_bill_id
 * @property float $original_sum
 * @property float $original_sum_tax
 * @property int $correction_idx
 * @property int $is_invoice
 * @property int $is_act
 * @property string $pay_bill_until
 * @property int $is_payed
 * @property string $payment_date
 * @property string $invoice_date
 *
 * @property-read Bill $bill
 * @property-read InvoiceLine[] $lines
 * @property-read Organization $organization
 * @property-read Bill $correctionBill
 * @property-read SBISGeneratedDraft $sbisDraft
 *
 * @property-read float $currencyRates
 * @property-read float $currencyRatesInEuro
 * @property-read string $link
 */
class Invoice extends ActiveRecord
{
    const TYPE_1 = 1;
    const TYPE_2 = 2;
    const TYPE_GOOD = 3;
    const TYPE_PREPAID = 4;

    const DATE_ACCOUNTING = '2018-08-01';
    const DATE_NO_RUSSIAN_ACCOUNTING = '2019-01-01';

    public static $types = [self::TYPE_1, self::TYPE_2, self::TYPE_GOOD];

    // Создается draft
    public $isSetDraft = null;

    public $isAsInsert = false;

    public const dateField = 'date';

    /**
     * Название таблицы
     *
     * @return string
     */
    public static function tableName()
    {
        return 'invoice';
    }

    /**
     * Поведение модели
     *
     * @return array
     */
    public function behaviors()
    {
        return [
            'InvoiceNextIdx' => InvoiceNextIdx::class,
            'InvoiceGeneratePdf' => InvoiceGeneratePdf::class,
            'InvoiceSetFlags' => InvoiceSetFlags::class,
        ];
    }

    /**
     * @return InvoiceDao
     * @throws \yii\base\Exception
     */
    public static function dao()
    {
        return InvoiceDao::me();
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getBill()
    {
        return $this->hasOne(Bill::class, ['bill_no' => 'bill_no']);
    }

    /**
     * Получение списка с платной доставкой.
     *
     * @param int $organizationId
     * @param string $dateFrom
     * @param string $dateTo
     */
    public function getPaymentDeliveryList($organizationId, $dateFrom, $dateTo)
    {
        return self::find()
            ->alias('i')
            ->joinWith([
                'bill b',
                'bill.clientAccountModel',
                'bill.clientAccountModel.options o'
            ])
            ->where([
                'i.organization_id' => $organizationId,
                'o.option' => ClientAccountOptions::OPTION_MAIL_DELIVERY,
                'o.value' => ClientAccountOptions::OPTION_MAIL_DELIVERY__PAYMENT
            ])
            ->andWhere(['between', 'b.bill_date', $dateFrom, $dateTo])
            ->groupBy('b.bill_no')
            ->orderBy(['i.id' => SORT_ASC])
            ->all();
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getLines()
    {
        return $this->hasMany(InvoiceLine::class, ['invoice_id' => 'id'])->orderBy(['sort' => SORT_ASC]);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getOrganization()
    {
        return $this->hasOne(Organization::class, ['organization_id' => 'organization_id'])->orderBy(['id' => SORT_DESC]);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getCorrectionBill()
    {
        return $this->hasOne(Bill::class, ['id' => 'correction_bill_id']);
    }

    public function getCurrencyRate()
    {
        return $this->hasMany(CurrencyRate::class, ['date' => 'date']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getSbisDraft()
    {
        return $this->hasOne(SBISGeneratedDraft::class, ['invoice_id' => 'id']);
    }

    /**
     * Вычисляет организацию с/ф
     *
     * @return Organization
     */
    public function calculateOrganization(): Organization
    {
        try {
            return $this->bill->clientAccountModel->clientContractModel->loadVersionOnDate($this->date)->organization;
        } catch (\Exception $e) {
            return $this->bill->organization;
        }
    }

    /**
     * @return float
     */
    public static function getCurrencyRates($date, $currency = null)
    {
        static $cache = [];

        if (!isset($cache[$date])) {
            $cache[$date] = CurrencyRate::find()
                ->where([
                    'date' => $date,
                ])
                ->select('rate')
                ->indexBy('currency')
                ->column();
        }

        $rates = $cache[$date];

        if ($currency) {
            return isset($rates[$currency]) ? $rates[$currency] : false;
        }

        return $rates;
    }

    public function getCurrencyRateToEuro()
    {
        $bill = $this->bill;
        $origCurrency = $bill->currency;
        $billDate = $bill->bill_date;

        return self::getCurrencyRates($billDate, $origCurrency) / self::getCurrencyRates($billDate, Currency::EUR);
    }

    /**
     * @return \DateTimeImmutable
     * @throws \Exception
     */
    public function getDateImmutable()
    {
        return (new \DateTimeImmutable($this->date));
    }

    /**
     * есть ли зарегестрированный инвойс
     *
     * @param Bill $bill
     * @param int $typeId
     * @param bool $isReversal
     * @return bool
     */
    public static function isHaveRegistredInvoices(Bill $bill, $typeId, $isReversal = false)
    {
        return Invoice::find()
            ->where([
                'bill_no' => $bill->bill_no,
                'type_id' => $typeId,
                'is_reversal' => (int)$isReversal,
            ])
            ->andWhere(['NOT', ['idx' => null]])
            ->exists();
    }

    /**
     * Получает даты по типу
     *
     * @param Bill $bill
     * @param int $typeId
     * @return bool|\DateTimeImmutable
     * @throws \Exception
     */
    public static function getDate(Bill $bill, $typeId)
    {
        $date = new \DateTimeImmutable($bill->bill_date);

        if ($bill->is1C()) {
            //all as good invoice
            $typeId = self::TYPE_GOOD;
        }

        if (
            in_array($typeId, [self::TYPE_1, self::TYPE_2])
            && $bill->clientAccount->contragent->country_id != Country::RUSSIA
//            && self::isHaveRegistredInvoices($bill, $typeId)
        ) {
            return (new \DateTimeImmutable('now'));
        }

        switch ($typeId) {
            case self::TYPE_1:
                return $date->modify('last day of this month');

            case self::TYPE_2:
                return $date->modify('last day of previous month');

            case self::TYPE_GOOD:
                return self::getBillWithGoodDate($bill, $date);

            case self::TYPE_PREPAID:
                return self::getBillPaymentDate($bill);

            default:
                return $date;
        }
    }

    /**
     * Дата первого платежа для с/ф 4
     *
     * @param Bill $bill
     * @return bool|\DateTimeImmutable
     * @throws \Exception
     */
    public static function getBillPaymentDate(Bill $bill)
    {
        /** @var Payment $payment */
        $payment = Payment::find()
            ->where(['bill_no' => $bill->bill_no])
            ->orderBy(['id' => SORT_ASC])
            ->one();

        if ($payment) {
            return (new \DateTimeImmutable($payment->payment_date));
        }

        return false;
    }

    /**
     * @param Bill $bill
     * @param $defaultDate
     * @return bool|\DateTimeImmutable
     * @throws \Exception
     */
    protected static function getBillWithGoodDate(Bill $bill, $defaultDate)
    {
        if (!$bill->is1C()) {
            return $defaultDate;
        }

        $date = self::getShippedDateFromTrouble($bill);

        if ($date) {
            return $date;
        }

        return false;
    }

    /**
     * @param Bill $bill
     * @return bool|\DateTimeImmutable
     * @throws \yii\db\Exception
     */
    public static function getShippedDateFromTrouble(Bill $bill)
    {
        $value = \Yii::$app->db->createCommand("
                     SELECT
                        min(cast(date_start AS DATE))
                     FROM
                        tt_troubles t , `tt_stages` s
                     WHERE
                            t.bill_no = :bill_no
                        AND t.id = s.trouble_id
                        AND state_id IN (SELECT id FROM tt_states WHERE state_1c = 'Отгружен')
                        ", [":bill_no" => $bill->bill_no])
            ->queryScalar();

        if ($value) {
            return (new \DateTimeImmutable($value));
        }

        return false;
    }

    /**
     * @param bool $isRevertSum
     * @throws \Throwable
     */
    public function setReversal($isRevertSum = false)
    {
        if ($this->is_reversal) {
            return;
        }

        $transaction = $this->db->beginTransaction();
        try {
            $now = (new \DateTime('now', new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_MOSCOW)));

            $this->is_reversal = 1;
            $this->reversal_date = $now->format(DateTimeZoneHelper::DATETIME_FORMAT);

            // дата сторинрования для "не русских" с/ф должна быть "сегодня"
            if ($this->bill->clientAccount->contragent->country_id != Country::RUSSIA) {
                $this->date = $now->format(DateTimeZoneHelper::DATE_FORMAT);
            }

            if ($isRevertSum) {
                $this->sum = -$this->sum;
                $this->sum_without_tax = -$this->sum_without_tax;
                $this->sum_tax = -$this->sum_tax;
            }

            if ($this->correction_bill_id) {
                $this->correctionBill->isSkipCheckCorrection = true;
                $this->correctionBill->delete();
                $this->correction_bill_id = null;
            }

            if (!$this->save()) {
                throw new ModelValidationException($this);
            }
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollback();
            throw $e;
        }
    }

    /**
     * @param $billNo
     * @return array
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     */
    public static function getInfo($billNo)
    {
        $bill = Bill::findOne(['bill_no' => $billNo]);

        if (!$bill) {
            throw new NotFoundHttpException('Bill not found');
        }

        $info = [];
        foreach (array_merge(self::$types, [self::TYPE_PREPAID]) as $typeId) {
            if ($typeInfo = self::getInfoByType($bill, $typeId)) {
                $info[$typeId] = $typeInfo;
            }
        }

        return $info;
    }

    /**
     * @param Bill $bill
     * @param $typeId
     * @return array|bool
     * @throws \yii\base\Exception
     */
    public static function getInfoByType(Bill $bill, $typeId)
    {
        $lines = $bill->getLinesByTypeId($typeId);

        // @TODO
        // проверка - можно ли по этому счету выписать авансовую с/ф

        // нет проводок - нет документа. Кроме авансовой с/ф
        if ($typeId != self::TYPE_PREPAID && !$lines) {
            return false;
        }

        $info = [
            'status' => 'empty',
            'invoices' => [],
        ];

        /** @var Invoice $invoice */
        foreach (Invoice::find()
                     ->where(['bill_no' => $bill->bill_no, 'type_id' => $typeId])
                        ->with('sbisDraft')
                     ->orderBy(['id' => SORT_ASC])
                     ->all()
                 as $invoice) {
            $info['invoices'][] = $invoice;

            if ($invoice->is_reversal) {
                $info['status'] = 'reversal';
            } elseif (!$invoice->idx) {
                $info['status'] = 'draft';
            } else {
                $info['status'] = 'invoice';
                $info['stornoId'] = $invoice->id;
            }
            $info['lastId'] = $invoice->id;
        }

        return $info;
    }

    /**
     * Получаем Invoice, который сторнировали
     *
     * @return Invoice
     */
    public function getReversalInvoice()
    {
        return Invoice::find()
            ->alias('i')
            ->join('INNER JOIN', ['orig' => Invoice::tableName()], 'i.bill_no = orig.bill_no AND i.id < orig.id AND orig.sum = -i.sum')
            ->where(['orig.number' => $this->number])
            ->orderBy(['i.id' => SORT_DESC])
            ->one();
    }

    /**
     * @return bool
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    public function beforeDelete()
    {
        if ($this->correction_bill_id) {
            $correction = $this->correctionBill;
            $correction->isSkipCheckCorrection = true;
            $correction->delete();
        }

        return parent::beforeDelete();
    }

    /**
     * @param bool $isInsert
     * @param array $changedAttributes
     * @throws ModelValidationException
     * @throws \yii\base\Exception
     */
    public function afterSave($isInsert, $changedAttributes)
    {
        if (
            (!$isInsert && !$this->isAsInsert)
//            || $this->bill->clientAccount->country_id == Country::RUSSIA
            || $this->bill->bill_date < '2019-02-01'
        ) {
            return;
        }

        // строки уже есть
        if ($this->isAsInsert && $this->getLines()->count() > 0) {
            return;
        }

        foreach ($this->bill->getLinesByTypeId($this->type_id, $isInsert) as $line) {
            $newLine = new InvoiceLine();

            if ($line instanceof BillLine) {
                $data = $line->getAttributes(null, ['pk']);
            } else {
                // array
                $data = $line;
                unset($data['pk']);
            }

            $newLine->setAttributes($data, false);
            $newLine->invoice_id = $this->id;
            $newLine->line_id = $line->pk;

            if ($this->is_reversal) {
                $newLine->price = -$newLine->price;
                $newLine->sum = -$newLine->sum;
                $newLine->sum_tax = -$newLine->sum_tax;
                $newLine->sum_without_tax = -$newLine->sum_without_tax;
            }

            if (!$newLine->save()) {
                throw new ModelValidationException($newLine);
            }
        }

        parent::afterSave($isInsert, $changedAttributes);
    }

    /**
     *
     * @throws \Throwable
     * @throws \yii\db\Exception
     */
    public function recalcSumCorrection()
    {
        $transaction = Invoice::getDb()->beginTransaction();
        try {
            $sums = InvoiceLine::find()
                ->where(['invoice_id' => $this->id])
                ->select([
                    'sum' => (new Expression('SUM(sum)')),
                    'sum_tax' => (new Expression('SUM(sum_tax)')),
                    'sum_without_tax' => (new Expression('SUM(sum_without_tax)')),
                ])
                ->asArray()
                ->one();

            $this->sum = $sums['sum'];
            $this->sum_tax = $sums['sum_tax'];
            $this->sum_without_tax = $sums['sum_without_tax'];

            if (!$this->save()) {
                throw new ModelValidationException($this);
            }

            $this->makeCorrectionBill();

            ClientAccount::dao()->updateBalance($this->bill->client_id);

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Создание корректировочного счета
     *
     * @throws ModelValidationException
     * @throws \Throwable
     * @throws \yii\base\Exception
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     */
    protected function makeCorrectionBill()
    {
        $diffSum = $this->sum - $this->original_sum;
        $diffSumTax = $this->sum_tax - $this->original_sum_tax;

        if (abs($diffSum) < 0.01 && abs($diffSumTax) < 0.01) {
            if ($this->correction_bill_id) {
                $this->correctionBill->isSkipCheckCorrection = true;
                $this->correctionBill->delete();
            }

            return;
        }

        if (!$this->correction_bill_id) {
            $bill = Bill::dao()->createBill($this->bill->clientAccount, $this->bill->currency, true);

            $bill->operation_type_id = OperationType::ID_CORRECTION;
            $bill->comment = 'Автоматическая корректировка к счету ' . $this->bill_no . ' (' . $this->type_id . ')';
            $this->correction_bill_id = $bill->id;
            $bill->price_include_vat = 1; // здесь сумма конечная, всегда с НДС


            $lineItem = \Yii::t(
                'biller',
                'correct_sum',
                [],
                \app\classes\Language::normalizeLang($this->bill->clientAccount->contract->contragent->lang_code)
            );

            $bill->addLine(
                $lineItem,
                1,
                $diffSum,
                BillLine::LINE_TYPE_SERVICE
            );

            $bill->sum = $diffSum;

            if (!$this->save()) {
                throw new ModelValidationException($this);
            }

            if (!$bill->save()) {
                throw new ModelValidationException($bill);
            }
        }

        $correctionBill = $this->correctionBill;

        if ($diffSum != $correctionBill->sum) {

            $line = reset($correctionBill->lines);
            $line->price = $diffSum;
            $line->calculateSum($correctionBill->price_include_vat);

            if (!$line->save()) {
                throw new ModelValidationException($line);
            }

            Bill::dao()->recalcBill($correctionBill);
        }
    }

    /**
     * @return string
     * @throws \Exception
     */
    public function getPath()
    {
        $pathStore = realpath(\Yii::$app->basePath . '/../store/invoices');

        $organization = $this->bill->clientAccount->contract->organization->firma;
        $dateStr = (new \DateTime($this->date))->format('Y-m');

        $dirData = [$organization, $dateStr];

        $path = $pathStore;
        foreach ($dirData as $p) {
            $path .= '/' . $p;
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }

        return $path . '/';
    }

    /**
     * Получить начальную дату
     *
     * @return string
     */
    public function getInitialDate()
    {
        if ($this->correction_idx) {
            return self::find()
                ->select('date')
                ->where([
                    'number' => $this->number,
                    'is_reversal' => false,
                ])
                ->orderBy(['correction_idx' => SORT_ASC])
                ->limit(1)
                ->scalar();
        }

        return $this->date;
    }

    /**
     * Получить путь к pdf-файлу
     *
     * @param string $document
     * @return string
     * @throws \Exception
     */
    public function getFilePath(string $document = 'invoice'): string
    {
        return $this->getPath()
            . $this->getFileName($document);
    }

    /**
     * @param string $document
     * @return string
     */
    public function getFileName(string $document = 'invoice'): string
    {
        return $this->bill->client_id
        . '-' . $document . '-' . $this->number
        . ($this->is_reversal ? 'R' : '')
        . ($this->correction_idx ? '-' . $this->correction_idx : '')
        . '.pdf';
    }

    /**
     * @throws NotAcceptableHttpException
     * @throws \HttpResponseException
     * @throws \yii\base\ExitException
     * @throws \Exception
     */
    public function downloadFile($document)
    {
        if (!file_exists($this->getFilePath($document))) {
            $this->generatePdfFile($document);
        }

        if (\Yii::$app instanceof \yii\console\Application) {
            throw new InvalidCallException('В консольном режиме скачать файл не возможно');
        }

        $filePath = $this->getFilePath($document);
        $info = pathinfo($filePath);

        \Yii::$app->response->format = Response::FORMAT_RAW;
        \Yii::$app->response->content = file_get_contents($filePath);
        \Yii::$app->response->setDownloadHeaders($info['basename'], 'application/pdf', true);

        \Yii::$app->end();
    }

    /**
     * @param string $document
     * @return bool
     * @throws NotAcceptableHttpException
     * @throws \HttpResponseException
     */
    public function generatePdfFile($document)
    {
        if (!$this->bill) {
            return false;
        }

        $filePath = $this->getFilePath($document);

        // already exists
        if (file_exists($filePath)) {
            return null;
        }

        if (in_array($this->organization_id, [Organization::TEL2TEL_KFT, Organization::TEL2TEL_GMBH])) {
            $pdf = $this->getContentTemplate1Pdf();
        } else {
            $pdf = $this->downloadPdfContent($document);
        }

        return file_put_contents($filePath, $pdf);
    }

    /**
     * @return mixed
     */
    protected function getContentTemplate1Pdf()
    {
        $invoiceDocument = (new InvoiceLight($this->bill->clientAccount))
            ->setBill($this->bill);

        $invoiceDocument->setInvoice($this);

        $langCode = $this->bill->clientAccount->contragent->lang_code;

        if (!is_null($langCode)) {
            $invoiceDocument->setLanguage($langCode);
        }

        return $invoiceDocument->render($isPdf = true);
    }

    /**
     * @param $document
     * @return mixed
     * @throws NotAcceptableHttpException
     * @throws \HttpResponseException
     */
    protected function downloadPdfContent($document = BillDocument::TYPE_INVOICE)
    {
        $data = [
            'bill' => $this->bill_no,
            'object' => $document . '-' . $this->type_id,
            'client' => (string)$this->bill->client_id,
            'is_pdf' => '1',
            'emailed' => '1',
        ];

        $link = Encrypt::encodeArray($data);

        $req = (new HttpClient())
            ->get(\Yii::$app->params['SITE_URL'] . 'bill.php?bill=' . $link)
            ->send();

        if ($req->statusCode != 200) {
            throw new NotAcceptableHttpException($req->content, $req->statusCode);
        }

        if (strpos($req->content, '%PDF') !== 0) {
            throw new \LogicException('Content is not PDF');
        }

        return $req->content;
    }

    /**
     * @return bool
     * @throws \Exception
     */
    public function isAllPdfGenerated()
    {
        if ($this->is_act && !file_exists($this->getFilePath(BillDocument::TYPE_ACT))) {
            return false;
        }

        if ($this->is_invoice && !file_exists($this->getFilePath(BillDocument::TYPE_INVOICE))) {
            return false;
        }

        return $this->is_act || $this->is_invoice;
    }

    /**
     * @param int $invoiceId
     * @throws ModelValidationException
     * @throws \yii\base\Exception
     * @throws \Exception
     */
    public static function checkAllPdfFiles($invoiceId)
    {
        $invoice = Invoice::findOne(['id' => $invoiceId]);

        if ($invoice && $invoice->isAllPdfGenerated()) {
            EventQueue::go(EventQueue::INVOICE_ALL_PDF_CREATED, ['id' => $invoice->id]);
        }
    }

    public function afterFind()
    {
        if ($this->invoice_date) {
            $this->date = $this->invoice_date;
        }
        parent::afterFind();
    }

    public function getLink()
    {
        return Url::to(['/',
            'module' => 'newaccounts',
            'bill' => $this->bill_no,
            'invoice2' => 1,
            'action' => 'bill_mprint',
            'invoice_id' => $this->id
        ]);
    }
}
