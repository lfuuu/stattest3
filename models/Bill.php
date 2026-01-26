<?php

namespace app\models;

use app\classes\behaviors\BillChangeLog;
use app\classes\behaviors\BillInvoiceReversal;
use app\classes\behaviors\CheckBillPaymentOverdue;
use app\classes\behaviors\ModelLifeRecorder;
use app\classes\behaviors\SetBillPaymentDate;
use app\classes\behaviors\SetBillPaymentOverdue;
use app\classes\model\ActiveRecord;
use app\classes\Utils;
use app\dao\BillDao;
use app\dao\BillUuCorrectionDao;
use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\media\BillExtFiles;
use app\modules\uu\models\AccountEntryCorrection;
use app\modules\uu\models\Bill as uuBill;
use app\queries\BillQuery;
use Yii;
use yii\helpers\Url;

/**
 * Расчётный документ
 *
 * @property int $id
 * @property int $operation_type_id
 * @property string $bill_no        номер счета для отображения
 * @property string $bill_date      дата счета
 * @property string $date           дата счета -> bill_date
 * @property int $client_id      id лицевого счета
 * @property string $currency       валюта. значения: USD, RUB
 * @property int $is_approved    Признак проведенности счета. 1 - проведен, влияет на балланс. 0 - не проведен, не влияет на баланс.
 * @property float $sum            итоговая сумма, влияющая на баланс. не включает задаток. не включает не проведенные строки
 * @property float $sum_with_unapproved  итоговая сумма. не включает задаток. включает не проведенный строки
 * @property int $is_payed       признак оплаченности счета 0 - не оплачен, 1 - оплачен, 2 - оплачен частично, 3 - ??
 * @property string $comment
 * @property int $inv2to1        ??
 * @property float $inv_rub        ??
 * @property string $postreg        ?? date
 * @property int $courier_id     ??
 * @property string $nal            ??  значения: beznal,nal,prov
 * @property string $sync_1c        ??  значения: yes, no
 * @property string $push_1c        ??  значения: yes, no
 * @property string $state_1c       ??
 * @property string $is_rollback    1 - счет на возврат. 0 - обычный
 * @property string $payed_ya       ??
 * @property string $editor         ??  значения: stat, admin
 * @property int $is_show_in_lk     ??
 * @property string $doc_date       ??
 * @property int $is_user_prepay ??
 * @property string $bill_no_ext        ??
 * @property string $bill_no_ext_date   ??
 * @property int $price_include_vat   ??
 * @property int $biller_version
 * @property int $uu_bill_id
 * @property int $organization_id
 * @property string $pay_bill_until
 * @property int $is_pay_overdue
 * @property float $sum_correction
 * @property string $payment_date
 * @property bool $is_to_uu_invoice
 * @property string $invoice_no_ext
 * @property string $correction_bill_id
 *
 * @property-read OperationType $operationType
 * @property-read ClientAccount $clientAccount   из версий
 * @property-read ClientAccount $clientAccountModel   прямая модель
 * @property-read Organization $organization   прямая модель
 * @property-read BillLine[] $lines   ??
 * @property-read BillLineUu[] $uUlines   ??
 * @property-read Transaction[] $transactions   ??
 * @property-read Currency $currencyModel
 * @property-read Payment $creditNote
 * @property-read uuBill $universalBill
 * @property-read Trouble $trouble
 * @property-read Invoice[] $invoices
 * @property-read array $document
 * @property-read Payment[] $payments
 * @property-read BillExtFiles $extFile
 * @property-read Bill $correctionBill
 * @property-read AccountEntryCorrection $accountEntryCorrection
 * @property-read string $link
 */
class Bill extends ActiveRecord
{
    const MINIMUM_BILL_DATE = '2000-01-01';

    const PAY_NOT_PAYED = 0;
    const PAY_FULL_PAYED = 1;
    const PAY_PART_PAYED = 2;

    const STATUS_NOT_PAID = 0;
    const STATUS_IS_PAID = 1;
    const STATUS_PAID_IN_PART = 2;

    const DOC_TYPE_INCOMEGOOD = 'incomegood';
    const DOC_TYPE_BILL = 'bill';
    const DOC_TYPE_UNKNOWN = 'unknown';

    const TYPE_1C = '1c';
    const TYPE_STAT = 'stat';

    const TRIGGER_CHECK_OVERDUE = 'trigger_check_overdue';

    const PERCENT_PAYMENT_PAY = 95; // сколько процентов оплаты считать, что счет оплачен полностью

    public static $paidStatuses = [
        self::STATUS_NOT_PAID => 'Не оплачен',
        self::STATUS_IS_PAID => 'Оплачен',
        self::STATUS_PAID_IN_PART => 'Оплачен частично',
    ];


    public $creatorId = null;
    public $logMessage = null;

    public $isHistoryVersioning = false;

    public $isSetPayOverdue = null;

    public $isSkipCheckCorrection = false;


    public const dateField = 'bill_date';
    /**
     * Название таблицы
     *
     * @return string
     */
    public static function tableName()
    {
        return 'newbills';
    }

    /**
     * @return array
     */
    public function transactions()
    {
        return [
            'default' => self::OP_INSERT | self::OP_UPDATE | self::OP_DELETE,
        ];
    }

    /**
     * Поведение модели
     *
     * @return array
     */
    public function behaviors()
    {
        return [
            'SetBillPaymentOverdue' => SetBillPaymentOverdue::class,
            'CheckBillPaymentOverdue' => CheckBillPaymentOverdue::class,
            'BillChangeLog' => BillChangeLog::class,
            'HistoryChanges' => \app\classes\behaviors\HistoryChanges::class,
            'SetBillPaymentDate' => SetBillPaymentDate::class,
            'BillInvoicesReversal' => BillInvoiceReversal::class,
            'ModelLifeRec' => [
                'class' => ModelLifeRecorder::class,
                'modelName' => 'bill',
            ],
        ];
    }

    /**
     * Query модели
     *
     * @return BillQuery
     */
    public static function find()
    {
        return new BillQuery(get_called_class());
    }

    /**
     * Dao
     *
     * @return BillDao
     * @throws \yii\base\Exception
     */
    public static function dao()
    {
        return BillDao::me();
    }

    /**
     * Название полей
     *
     * @return array
     */
    public function attributeLabels()
    {
        return [
            'id' => 'Id',
            'operation_type_id' => 'Тип документа',
            'client_id' => 'ЛС',
            'bill_no' => 'Номер счета',
            'bill_date' => 'Дата счета',
            'sum' => 'Сумма',
            'currency' => 'Валюта',
            'sum_with_unapproved' => 'Сумма (не проведенная)',
            'postreg' => 'Почтовый реестр',
            'courier_id' => 'Курьер',
            'state_1c' => 'Статус заказа',
            'doc_date' => 'Дата документа',
            'comment' => 'Комментарий',
            'nal' => 'Предпологаемый тип платежа',
            'is_pay' => 'Счет оплачен',
            'pay_bill_until' => 'Оплатить счет до',
            'is_pay_overdue' => 'Просрочена оплата счета',
            'payment_date' => 'Дата оплаты счета',
            'is_to_uu_invoice' => 'Включить в У-с/ф',
        ];
    }

    public function getParentId()
    {
        return $this->client_id;
    }

    /**
     * Подготовка полей для исторических данных
     *
     * @param string $field
     * @param string $value
     * @return string
     */
    public static function prepareHistoryValue($field, $value)
    {
        switch ($field) {
            case 'courier_id':
                if ($courier = Courier::findOne(['id' => $value])) {
                    /** @var Courier $courier */
                    return $value . ' (' . $courier->name . ')';
                }
                break;
        }

        return $value;
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getOperationType()
    {
        return $this->hasOne(OperationType::class, ['id' => 'operation_type_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getLines()
    {
        return $this->hasMany(BillLine::class, ['bill_no' => 'bill_no']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getUuLines()
    {
        return $this->hasMany(BillLineUu::class, ['bill_no' => 'bill_no'])->indexBy('uu_account_entry_id');
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getTransactions()
    {
        return $this->hasMany(Transaction::class, ['bill_id' => 'id']);
    }

    /**
     * @return ClientAccount
     */
    public function getClientAccount()
    {
        static $cache = [];
        static $accountCache = [];

        $cacheKey = $this->client_id . ':' . $this->bill_date;

        // Проверяем кэш с учётом даты
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        // Кэшируем базовый объект ClientAccount отдельно
        if (!array_key_exists($this->client_id, $accountCache)) {
            $accountCache[$this->client_id] = ClientAccount::findOne(['id' => $this->client_id]);
        }

        $baseAccount = $accountCache[$this->client_id];

        if (!$baseAccount) {
            $cache[$cacheKey] = null;
            return null;
        }

        /** @var ClientAccount $account */
        $account = clone $baseAccount;
        $account->loadVersionOnDate($this->bill_date);

        $cache[$cacheKey] = $account;

        return $account;
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getClientAccountModel()
    {
        return $this->hasOne(ClientAccount::class, ['id' => 'client_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getOrganization()
    {
        return $this->hasOne(Organization::class, ['organization_id' => 'organization_id'])->orderBy(['id' => SORT_DESC]);
    }

    /**
     * Закрыт ли счет?
     *
     * @return bool
     * @throws \yii\base\Exception
     * @throws \yii\db\Exception
     */
    public function isClosed()
    {
        return Bill::dao()->isClosed($this);
    }

    /**
     * Счет содержит партнерские вознаграждения
     *
     * @return bool
     * @throws \yii\base\Exception
     */
    public function isHavePartnerRewards()
    {
        return self::dao()->isHavePartnerRewards($this);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getExtendsInfo()
    {
        return $this->hasOne(BillExtendsInfo::class, ['bill_no' => 'bill_no']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getCurrencyModel()
    {
        return $this->hasOne(Currency::class, ['id' => 'currency']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getUniversalBill()
    {
        return $this->hasOne(uuBill::class, ['id' => 'uu_bill_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getCorrectionBill()
    {
        return $this->hasOne(Bill::class, ['id' => 'correction_bill_id']);
    }
    /**
     * @return \yii\db\ActiveQuery
     */
    public function getTrouble()
    {
        return $this->hasOne(Trouble::class, ['bill_no' => 'bill_no']);
    }

    public function getExtFile()
    {
        return $this->hasOne(BillExtFiles::class, ['bill_no' => 'bill_no']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getAccountEntryCorrection()
    {
        return $this->hasOne(AccountEntryCorrection::class, ['bill_no' => 'bill_no', 'client_account_id' => 'client_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getInvoices()
    {
        return $this->hasMany(Invoice::class, ['bill_no' => 'bill_no'])
            ->where(['is_reversal' => 0])
            ->orderBy(['type_id' => SORT_ASC])
            ->indexBy('type_id');
    }

    /**
     * @return Payment
     * @throws \yii\base\Exception
     */
    public function getCreditNote()
    {
        return self::dao()->getCreditNote($this);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getPayments()
    {
        return $this->hasMany(Payment::class, ['bill_no' => 'bill_no'])
            ->orderBy(['payment_date' => SORT_ASC]);
    }

    /**
     * Получение разрешенных документов счета
     *
     * @return array
     */
    public function getDocument()
    {
        return BillDocument::dao()->getByBillNo($this->bill_no);
    }

    /**
     * Получаем последний неоплаченный счет
     *
     * @param int $clientId
     * @return boolean|\app\models\Bill
     */
    public static function getLastUnpaidBill($clientId)
    {
        $fromDate = self::MINIMUM_BILL_DATE;

        if (($clientAccount = ClientAccount::findOne($clientId)) === null) {
            return false;
        }

        if ($lastSaldo = Saldo::getLastSaldo($clientAccount->id)) {
            $fromDate = $lastSaldo->ts;
        }

        // First unpaid bill
        $bill = self::find()
            ->where([
                'client_id' => $clientAccount->id,
                'currency' => $clientAccount->currency,
                'biller_version' => ClientAccount::VERSION_BILLER_USAGE
            ])
            ->andWhere(['in', 'is_payed', [self::STATUS_NOT_PAID, self::STATUS_PAID_IN_PART]])
            ->andWhere(['>', 'bill_date', $fromDate])
            ->orderBy('bill_date')
            ->one();

        if ($bill === null) {
            // Last bill
            $bill = self::find()
                ->where([
                    'client_id' => $clientAccount->id,
                    'currency' => $clientAccount->currency,
                    'biller_version' => ClientAccount::VERSION_BILLER_USAGE
                ])
                ->andWhere(['is_payed' => 1])
                ->andWhere(['>', 'bill_date', $fromDate])
                ->orderBy(['bill_date' => SORT_DESC])
                ->one();
        }

        return $bill !== null ? $bill : false;
    }

    /**
     * @param bool $isToView
     * @return string
     */
    public function getUrl($isToView = true)
    {
        return Url::toRoute([
            '/',
            'module' => 'newaccounts',
            'action' => $isToView ? 'bill_view' : 'bill_edit',
            'bill' => $this->bill_no
        ]);
    }

    /**
     * @return bool
     * @throws \yii\db\Exception
     */
    public function beforeDelete()
    {
        if (!$this->isSkipCheckCorrection && $this->isCorrectionType()) {
            throw new \LogicException('Нельзя удалить корректировку');
        }

        Trouble::deleteAll(['bill_no' => $this->bill_no]);

        foreach ($this->lines as $line) {
            Transaction::dao()->markDeletedByBillLine($line, $this->id);
        }

        Yii::$app->db->createCommand(
            'update log_newbills set bill_no = :billNoWithDate where bill_no = :billNo',
            [':billNoWithDate' => $this->bill_no . date('dHs'), ':billNo' => $this->bill_no]
        )->execute();

        return parent::beforeDelete();
    }

    /**
     * @param bool $isInsert
     * @return bool
     */
    public function beforeSave($isInsert)
    {
        // проставляем организацию счета, если не установлена
        if ($this->bill_date && $this->client_id && !$this->organization_id) {
            $account = ClientAccount::findOne(['id' => $this->client_id])->loadVersionOnDate($this->bill_date);
            $this->organization_id = $account->contract->organization_id;
        }

        if ($this->sum < 0 && $this->operation_type_id == OperationType::ID_PRICE) {
            $this->operation_type_id = OperationType::ID_COST;
        }

        return parent::beforeSave($isInsert);
    }

    /**
     * Добавление строчки в счет
     *
     * @param string $item
     * @param float $amount
     * @param float $price
     * @param string $type
     * @param \DateTime|\DateTimeImmutable|string $dateFrom
     * @param \DateTime|\DateTimeImmutable|string $dateTo
     * @return BillLine
     * @throws \Exception
     */
    public function addLine($item, $amount, $price, $type = BillLine::LINE_TYPE_SERVICE, $dateFrom = null, $dateTo = null)
    {
        // начало периода задатковой проводки должно совпадать со счетом
        if ($type == BillLine::LINE_TYPE_ZADATOK && !$dateFrom && !$dateTo) {
            $dateFrom = $this->bill_date;
            $dateTo = (new \DateTimeImmutable($dateFrom))->modify('last day of this month')->format(DateTimeZoneHelper::DATE_FORMAT);
        }

        if (!$dateFrom) {
            $dateFrom = Utils::dateBeginOfPreviousMonth($this->bill_date);
        } elseif ($dateFrom instanceof \DateTimeImmutable || $dateFrom instanceof \DateTime) {
            $dateFrom =  $dateFrom->format(DateTimeZoneHelper::DATE_FORMAT);
        }

        if (!$dateTo) {
            $dateTo = Utils::dateBeginOfPreviousMonth($this->bill_date);
        } elseif ($dateTo instanceof \DateTimeImmutable || $dateTo instanceof \DateTime) {
            $dateTo =  $dateTo->format(DateTimeZoneHelper::DATE_FORMAT);
        }

        $line = new BillLine();
        $line->bill_no = $this->bill_no;
        $line->setParentId($this->id);
        $line->sort = ((int)BillLine::find()
                ->where(['bill_no' => $this->bill_no])
                ->max('sort')) + 1;
        $line->item = $item;
        $line->amount = $amount;
        $line->type = $type;
        $line->date_from = $dateFrom;
        $line->date_to = $dateTo;
        $line->tax_rate = $this->clientAccount->getTaxRate();
        $line->price = $price;
        // $line->service = $service;
        // $line->id_service = $id_service;
        $line->calculateSum($this->price_include_vat);
        $line->save();

        return $line;
    }

    /**
     * Счет просмотрен клиентом
     *
     * @throws ModelValidationException
     */
    public function setViewed()
    {
        $billSend = BillSend::findOne(['bill_no' => $this->bill_no]);

        if ($billSend && $billSend->state == BillSend::STATE_VIEWED) {
            return;
        }

        if (!$billSend) {
            $billSend = new BillSend();
            $billSend->bill_no = $this->bill_no;
            $billSend->client = $this->clientAccount->client;
        }

        $billSend->state = BillSend::STATE_VIEWED;

        if (!$this->save()) {
            throw new ModelValidationException($this);
        }
    }

    /**
     * Сгенерировать с/ф
     *
     * @throws \Throwable
     * @throws \yii\base\Exception
     */
    public function generateInvoices()
    {
        self::dao()->generateInvoices($this);
    }

    /**
     * Проверить с/ф
     *
     * проверяется сохранение строк с/ф
     *
     * @return void
     * @throws \Throwable
     * @throws \yii\base\Exception
     */
    public function checkInvoices()
    {
        self::dao()->generateInvoices($this, false, true);
    }

    /**
     * Сгенерировать авансовую с/ф
     *
     * @throws \Throwable
     * @throws \yii\base\Exception
     */
    public function generateAbInvoice()
    {
        self::dao()->generateInvoices($this, true);
    }

    /**
     * Получение позиций счета по типу
     *
     * @param int $typeId
     * @param bool $isInsert
     * @return array|bool
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function getLinesByTypeId($typeId, $isInsert = false)
    {
        return self::dao()->getLinesByTypeId($this, $typeId, $isInsert);
    }

    /**
     * Это счет 1С
     *
     * @return bool
     */
    public function is1C()
    {
        return strpos($this->bill_no, "/") !== false;
    }

    /**
     * Это ручная услуга
     *
     * @return bool
     */
    public function isOnTimeService()
    {
        $lines = $this->lines;

        if(count($lines) != 1) return false;

        $line = reset($lines);

        return $line->type == BillLine::LINE_TYPE_SERVICE && $line->id_service == 0;
    }

    /**
     * Информация о корректирующих документа
     *
     * @return array
     */
    public function getCorrectionInfo()
    {
        return BillCorrection::getInfo($this);
    }

    /**
     * Унификация с uuBill. Дата счета.
     *
     * @return string
     */
    public function getDate()
    {
        return $this->bill_date;
    }

    /**
     * Это корректировка
     *
     * @return bool
     */
    public function isCorrectionType()
    {
        return OperationType::isCorrection($this->operation_type_id);
    }

    /**
     * К платежно-расчетному документу. Список платежей.
     *
     * @return array
     */
    public function getInvoicePayments()
    {
        return self::dao()->getInvoicePayments($this->bill_no);
    }

    /**
     * Можно ли редактировать строки счета
     *
     * @return bool
     * @throws \yii\base\Exception
     */
    public function isEditable(): bool
    {
        return self::dao()->isEditable($this);
    }

    /**
     * Проверка необходимости создания (удаления) корректирующего счета
     *
     * @return void
     * @throws \yii\base\Exception
     */
    public function checkUuCorrectionBill()
    {
        return BillUuCorrectionDao::me()->checkBill($this);
    }

    public function getLink()
    {
        return self::makeLink($this->bill_no);
    }

    public static function makeLink($billNo)
    {
        return Url::to(['/',
            'module' => 'newaccounts',
            'action' => 'bill_view',
            'bill' => $billNo,
        ]);
    }
}
