<?php

namespace app\modules\uu\models;

use app\classes\model\ActiveRecord;
use app\classes\traits\GetInsertUserTrait;
use app\helpers\DateTimeZoneHelper;
use app\models\ClientAccount;
use app\modules\uu\behaviors\AccountTariffBiller;
use app\modules\uu\behaviors\AccountTariffLogicalChangeLog;
use app\modules\uu\behaviors\AccountTariffResourceLogSyncBiller;
use app\modules\uu\classes\AccountLogFromToResource;
use app\modules\uu\tarificator\AccountLogResourceTarificator;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Yii;
use yii\behaviors\AttributeBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\web\Application;

/**
 * Лог изменений опциональных ресурсов универсальной услуги
 * По аналогии с AccountTariffLog. Это не инкрементационный лог, а действует последнее значение
 * Значение абсолютное (как на платформе). Для билинга из него надо вычесть включенное количество в тариф
 *
 * @property int $id
 * @property int $account_tariff_id
 * @property int $resource_id
 * @property float $amount
 * @property string $actual_from_utc
 * @property string $sync_time
 * @property int $is_announced
 *
 * @property string $actual_from
 *
 * @property-read AccountTariff $accountTariff
 * @property-read ResourceModel $resource
 *
 * @method static AccountTariffResourceLog findOne($condition)
 * @method static AccountTariffResourceLog[] findAll($condition)
 */
class AccountTariffResourceLog extends ActiveRecord
{
    // Перевод названий полей модели
    use \app\classes\traits\AttributeLabelsTraits;

    // Методы для полей insert_time, insert_user_id
    use GetInsertUserTrait;

    /** @var int Код ошибки для АПИ */
    public $errorCode = null;

    private $_countLogs = null;

    protected $isAttributeTypecastBehavior = true;

    /** @var string Это поле только для записи в историю */
    public $user_info = '';

    public $isAllowSavingInPast = false;

    public $isValidateOnly = false;

    /**
     * @return string
     */
    public static function tableName()
    {
        return 'uu_account_tariff_resource_log';
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            [['account_tariff_id', 'resource_id', 'amount'], 'required'],
            [['account_tariff_id', 'resource_id', 'is_announced'], 'integer'],
            ['resource_id', 'validateTariffResource'],
            [['amount'], 'number'],
            [['amount'], 'validatorOther', 'skipOnEmpty' => false],
            ['actual_from', 'date', 'format' => 'php:' . DateTimeZoneHelper::DATE_FORMAT],
            ['actual_from', 'validatorFuture', 'skipOnEmpty' => false],
            ['id', 'validatorBalance', 'skipOnEmpty' => false],
            ['user_info', 'string'],
            ['is_announced', 'default', 'value' => 0],
        ];
    }

    /**
     * @return array
     */
    public function behaviors()
    {
        return array_merge(
            parent::behaviors(),
            [
                [
                    // Установить "когда создал"
                    'class' => TimestampBehavior::class,
                    'createdAtAttribute' => 'insert_time',
                    'updatedAtAttribute' => false,
                    'value' => new Expression('UTC_TIMESTAMP()'), // "NOW() AT TIME ZONE 'utc'" (PostgreSQL) или 'UTC_TIMESTAMP()' (MySQL)
                ],
                [
                    // Установить "кто создал"
                    'class' => AttributeBehavior::class,
                    'attributes' => [
                        ActiveRecord::EVENT_BEFORE_INSERT => 'insert_user_id',
                    ],
                    'value' => Yii::$app->user->getId(),
                ],
                AccountTariffBiller::class, // Пересчитать транзакции, проводки и счета
                AccountTariffLogicalChangeLog::class,
                AccountTariffResourceLogSyncBiller::class, // Поставить на синхронизацию ресурсы в биллер
            ]
        );
    }

    /**
     * @param array $fields the fields being requested. If empty, all fields as specified by [[fields()]] will be returned.
     * @param array $expand the additional fields being requested for exporting. Only fields declared in [[extraFields()]]
     * will be considered.
     * @param bool $recursive whether to recursively return array representation of embedded objects.
     * @return array the array representation of the object
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true)
    {
        return array_merge(
            parent::toArray(),
            ['user_info' => $this->user_info]
        );
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountTariff()
    {
        return $this->hasOne(AccountTariff::class, ['id' => 'account_tariff_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getResource()
    {
        return $this->hasOne(ResourceModel::class, ['id' => 'resource_id']);
    }

    /**
     * Установить actual_from из date в таймзоне клиента в datetime UTC
     *
     * @param string $date
     */
    public function setActual_from($date)
    {
        $this->actual_from_utc = $this->getClientDateTime($date)
            ->setTime(0, 0, 0)
            ->setTimezone(new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC))
            ->format(DateTimeZoneHelper::DATETIME_FORMAT);

    }

    /**
     * Вернуть actual_from в виде date в таймзоне клиента, а не datetime UTC
     *
     * @return string|null
     */
    public function getActual_from()
    {
        if (!$this->actual_from_utc) {
            return null;
        }

        return (new DateTime($this->actual_from_utc, new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC)))
            ->setTimezone($this->getClientTimeZone())
            ->format(DateTimeZoneHelper::DATE_FORMAT);

    }

    /**
     * Вернуть DateTime в таймзоне клиента
     *
     * @param string $date в таймзоне клиента
     * @return DateTimeImmutable
     */
    public function getClientDateTime($date = 'now')
    {
        return new DateTimeImmutable($date, $this->getClientTimeZone());
    }

    /**
     * Вернуть DateTimeZone клиента
     *
     * @return DateTimeZone
     */
    public function getClientTimeZone()
    {
        if ($this->accountTariff && $this->accountTariff->clientAccount) {
            return $this->accountTariff->clientAccount->getTimezone();
        }

        return new DateTimeZone(DateTimeZoneHelper::TIMEZONE_DEFAULT);
    }

    /**
     * Валидировать ресурс
     *
     * @param string $attribute
     * @param array $params
     */
    public function validateTariffResource($attribute, $params)
    {
        if (!$this->resource) {
            $this->addError($attribute, 'Указан несуществующий ресурс.');
            $this->errorCode = AccountTariff::ERROR_CODE_RESOURCE_WRONG;
            return;
        }

        $tariffPeriod = $this->accountTariff->tariffPeriod;
        if ($tariffPeriod && $this->resource->service_type_id != $tariffPeriod->tariff->service_type_id) {
            $this->addError($attribute, 'Этот ресурс "' . ($this->resource ? $this->resource->name : $this->resource_id) . '" от другого типа услуги.');
            $this->errorCode = AccountTariff::ERROR_CODE_RESOURCE_TYPE_WRONG;
            return;
        }

        if (!$this->resource->isOption() && !$this->isAllowSavingInPast) {
            $this->addError($attribute, 'Этот ресурс "' . ($this->resource ? $this->resource->name : $this->resource_id) . '" - трафик, а не опция. Его нельзя установить заранее.');
            $this->errorCode = AccountTariff::ERROR_CODE_RESOURCE_TRAFFIC;
            return;
        }
    }

    /**
     * Валидировать дату смены количества ресурса
     *
     * @param string $attribute
     * @param array $params
     */
    public function validatorFuture($attribute, $params)
    {
        Yii::trace('AccountTariffResourceLog. Before validatorFuture', 'uu');

        if (!$this->isNewRecord) {
            return;
        }

        $accountTariff = $this->accountTariff;
        $clientAccount = $accountTariff->clientAccount;
        if (!$clientAccount) {
            $this->addError($attribute, 'ЛС не указан.');
            $this->errorCode = AccountTariff::ERROR_CODE_ACCOUNT_EMPTY;
            return;
        }

        $currentDateTimeUtc = $clientAccount
            ->getDatetimeWithTimezone()
            ->setTime(0, 0, 0)
            ->setTimezone(new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC))
            ->format(DateTimeZoneHelper::DATETIME_FORMAT);

        if (!$this->isAllowSavingInPast && $this->actual_from_utc < $currentDateTimeUtc) {
            $this->addError($attribute, 'Нельзя менять количество ресурса "' . ($this->resource ? $this->resource->name : $this->resource_id) . '" задним числом.');
            $this->errorCode = AccountTariff::ERROR_CODE_DATE_PREV;
            return;
        }

        /*
            if (
                $this->actual_from_utc == $currentDateTimeUtc
                && self::find()
                    ->where([
                        'account_tariff_id' => $this->account_tariff_id,
                        'resource_id' => $this->resource_id,
                    ])
                    ->andWhere(['=', 'actual_from_utc', $currentDateTimeUtc])
                    ->count()
            ) {
                $this->addError($attribute, 'Сегодня количество ресурса уже меняли. Теперь можно сменить его не ранее завтрашнего дня.');
                $this->errorCode = AccountTariff::ERROR_CODE_DATE_TODAY;
                return;
            }
        */

        if (!$this->isValidateOnly && self::find()
            ->where([
                'account_tariff_id' => $this->account_tariff_id,
                'resource_id' => $this->resource_id,
            ])
            ->andWhere(['>', 'actual_from_utc', $currentDateTimeUtc])
            ->count()
        ) {
            $this->addError($attribute,
                'Уже назначена смена количество ресурса "' . ($this->resource ? $this->resource->name : $this->resource_id) . '" в будущем. Если вы хотите установить новое количество ресурса - сначала отмените эту смену.');
            $this->errorCode = AccountTariff::ERROR_CODE_DATE_FUTURE;
            return;
        }

        Yii::trace('AccountTariffResourceLog. After validatorFuture', 'uu');
    }

    public function deleteAppointmentsInTheFuture(): ?int
    {
        if ($this->isValidateOnly) {
            return null;
        }

        $accountTariff = $this->accountTariff;
        $clientAccount = $accountTariff->clientAccount;

        $currentDateTimeUtc = $clientAccount
            ->getDatetimeWithTimezone()
            ->setTime(0, 0, 0)
            ->setTimezone(new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC))
            ->format(DateTimeZoneHelper::DATETIME_FORMAT);

        $deletedCount = 0;
        $query = self::find()
            ->where([
                'account_tariff_id' => $this->account_tariff_id,
                'resource_id' => $this->resource_id,
            ])
            ->andWhere(['>', 'actual_from_utc', $currentDateTimeUtc]);

        /** @var self $log */
        foreach ($query->each() as $log) {
            $deletedCount += (int)$log->delete();
        }

        return $deletedCount;
    }

    public function isResourceLogCancelable()
    {
        $clientAccount = $this->accountTariff->clientAccount;
        $dateTimeNow = $clientAccount->getDatetimeWithTimezone(); // по таймзоне клиента
        return $this->actual_from > $dateTimeNow->format(DateTimeZoneHelper::DATE_FORMAT);
    }

    /**
     * Валидировать, что меняется на другое значение
     *
     * @param string $attribute
     * @param array $params
     */
    public function validatorOther($attribute, $params)
    {
        Yii::trace('AccountTariffResourceLog. Before validatorOther', 'uu');

        if (!$this->isNewRecord) {
            // При обновлении не проверяем. Клиент обновить все равно не может. Он может только удалить (если дата еще не наступила) или добавить новый (если дата ужа наступила)
            return;
        }

        $resource = $this->resource;
        if ($this->amount < $resource->min_value) {
            $this->addError($attribute, 'Значение ' . $this->amount . ' ресурса "' . ($this->resource ? $this->resource->name : $this->resource_id) . '" меньше минимально допустимого значения ' . $resource->min_value . '.');
            $this->errorCode = AccountTariff::ERROR_CODE_RESOURCE_AMOUNT_MIN;
            return;
        }

        if ($resource->max_value && $this->amount > $resource->max_value) {
            $this->addError($attribute, 'Значение ' . $this->amount . ' ресурса "' . ($this->resource ? $this->resource->name : $this->resource_id) . '" больше максимально допустимого значения ' . $resource->max_value . '.');
            $this->errorCode = AccountTariff::ERROR_CODE_RESOURCE_AMOUNT_MAX;
            return;
        }


        // Проверка, что бы кол-во оплаченных абонентов на ВАТС не было меньше фактического
//        if (
//            $resource->service_type_id == ServiceType::ID_VPBX
//            && $resource->id == Resource::ID_VPBX_ABONENT
//        ) {
//            if (
//                ($lastAbonents = VirtpbxStat::getLastValue($this->account_tariff_id, 'numbers'))
//                && ($lastAbonents > $this->amount)
//            ) {
//                $this->addError($attribute, 'Значение ' . $this->amount . ' ресурса "' . ($this->resource ? $this->resource->name : $this->resource_id) . '" меньше фактического количества: ' . $lastAbonents . '.');
//                $this->errorCode = AccountTariff::ERROR_CODE_RESOURCE_AMOUNT_MIN;
//                return;
//            }
//        }

        /** @var self $prev */
        $prev = self::find()
            ->where([
                'account_tariff_id' => $this->account_tariff_id,
                'resource_id' => $this->resource_id,
            ])
            ->orderBy([
                'id' => SORT_DESC,
            ])
            ->one();

        if ($prev && $this->amount == $prev->amount) {
            $this->addError($attribute, 'Нет смысла менять значение ресурса "' . ($this->resource ? $this->resource->name : $this->resource_id) . '" на тот же самый. Выберите другое значение.');
            $this->errorCode = AccountTariff::ERROR_CODE_TARIFF_SAME;
            return;
        }

        Yii::trace('AccountTariffResourceLog. After validatorCreateNotClose', 'uu');
    }

    /**
     * @return string
     */
    public function getAmount()
    {
        if (!$this->resource) {
            return null;
        }

        if ($this->resource->isNumber()) {
            return (string)$this->amount;
        }

        return $this->amount ? '+' : '-';
    }

    /**
     * Валидировать, что realtime balance больше обязательного платежа по ресурсу
     *
     * @param string $attribute
     * @param array $params
     * @return AccountLogResource|null
     * @throws \RangeException
     * @throws \Exception
     */
    public function validatorBalance($attribute, $params)
    {
        Yii::trace('AccountTariffResourceLog. Before validatorBalance', 'uu');

//        if (!$this->isNewRecord) {
//            return null;
//        }

        $accountTariff = $this->accountTariff;
        if (!$accountTariff) {
            $this->addError($attribute, 'Услуга не указана.');
            $this->errorCode = AccountTariff::ERROR_CODE_USAGE_EMPTY;
            return null;
        }

        $clientAccount = $accountTariff->clientAccount;
        if (!$clientAccount) {
            $this->addError($attribute, 'ЛС не указан.');
            $this->errorCode = AccountTariff::ERROR_CODE_ACCOUNT_EMPTY;
            return null;
        }

        if ($clientAccount->account_version != ClientAccount::VERSION_BILLER_UNIVERSAL) {
            $this->addError($attribute, 'Универсальную услугу можно добавить только ЛС, тарифицируемому универсально.');
            $this->errorCode = AccountTariff::ERROR_CODE_ACCOUNT_IS_NOT_UU;
            return null;
        }

        if (!$this->_getCountLogs()) {
            // при инициализации ресурсов денег не списывается. Проверять дальше не смысла
            return null;
        }

        $tariffPeriod = $accountTariff->tariffPeriod;
        if (!$tariffPeriod) {
            // Это еще не значит, что услуга закрыта. Возможно, она только что создана и еще не успела проставиться
            // Возьмем тариф из лога. Он нужен для расчета срока списания денег за ресурс, но без фактического списывания
            $accountTariffLogs = $accountTariff->accountTariffLogs;
            if (count($accountTariffLogs) === 1) {
                // Только если одна запись, которая при создании.
                // Если ни одной записи - что-то не так. Лог ресурсов должен создаваться из FillAccountTariffResourceLog, который вызывается из AccountTariffLog
                // Если две записи - это уже не создание. В этом случае тариф должен быть у услуги. Значит, что-то не так.
                $tariffPeriod = reset($accountTariffLogs)->tariffPeriod;
            }
        }

        if (!$tariffPeriod) {
            $this->addError($attribute, 'Услуга закрыта.');
            $this->errorCode = AccountTariff::ERROR_CODE_TARIFF_EMPTY;
            return null;
        }

        $credit = $clientAccount->credit; // кредитный лимит
        $realtimeBalance = $clientAccount->balance;
        $realtimeBalanceWithCredit = ($realtimeBalance + $credit);

        $warnings = $clientAccount->getVoipWarnings();

        if ($clientAccount->is_blocked) {
            $this->addError('ЛС заблокирован');
            $this->errorCode = AccountTariff::ERROR_CODE_ACCOUNT_BLOCKED_PERMANENT;
            return null;
        }

        if (isset($warnings[ClientAccount::WARNING_OVERRAN])) {
            $this->addError('ЛС заблокирован из-за превышения лимитов');
            $this->errorCode = AccountTariff::ERROR_CODE_ACCOUNT_BLOCKED_TEMPORARY;
            return null;
        }

        $maxPaidAmount = $accountTariff->getMaxPaidAmount($tariffPeriod->tariff, $this->resource_id);
        $accountLogFromToResource = new AccountLogFromToResource;
        $accountLogFromToResource->dateFrom = new DateTimeImmutable($this->actual_from);
        $accountLogFromToResource->dateTo = $tariffPeriod->chargePeriod->getMinDateTo($accountLogFromToResource->dateFrom);
        $accountLogFromToResource->tariffPeriod = $tariffPeriod;
        $accountLogFromToResource->amountOverhead = max(0, $this->amount - $maxPaidAmount);

        $accountLogResource = (new AccountLogResourceTarificator())->getAccountLogResource($accountTariff, $accountLogFromToResource, $this->resource_id);
        $priceResources = $accountLogResource->price;

        if ($priceResources > 0) {
            // Эти проверки только для платного ресурса

            if ($realtimeBalanceWithCredit < 0 || isset($warnings[ClientAccount::WARNING_FINANCE]) || isset($warnings[ClientAccount::WARNING_CREDIT])) {
                $error = sprintf('Платные ресурсы нельзя подключить, потому что ЛС находится в финансовой блокировке. На счету %.2f %s и кредит %.2f %s', $realtimeBalance, $clientAccount->currency, $credit, $clientAccount->currency);
                $this->addError($attribute, $error);
                $this->errorCode = AccountTariff::ERROR_CODE_ACCOUNT_BLOCKED_FINANCE;
                return null;
            }

            if ($realtimeBalanceWithCredit < $priceResources) {
                $error = sprintf(
                    'На ЛС %.2f %s и кредит %.2f %s, что меньше стоимости ресурсов %.2f',
                    $realtimeBalance,
                    $clientAccount->currency,
                    $credit,
                    $clientAccount->currency,
                    $priceResources
                );
                $this->addError($attribute, $error);
                $this->errorCode = AccountTariff::ERROR_CODE_ACCOUNT_MONEY;
                return null;
            }
        }

        // все хорошо - денег хватает
        // на самом деле мы не знаем, сколько клиент уже потратил на звонки сегодня. Но это дело низкоуровневого биллинга. Если денег не хватит - заблокирует финансово
        // транзакции не сохраняем, деньги пока не списываем. Подробнее см. AccountTariffBiller
        Yii::trace('AccountTariffResourceLog. After validatorBalance', 'uu');
        return $accountLogResource;
    }

    /**
     * Сдвинуть actual_from на завтра
     *
     * @param string $error
     */
    private function _shiftActualFrom($error)
    {
        $accountTariff = $this->accountTariff;
        $clientAccount = $accountTariff->clientAccount;
        $datimeNow = $clientAccount->getDatetimeWithTimezone();
        if ($datimeNow->format(DateTimeZoneHelper::DATE_FORMAT) == $this->actual_from) {
            $this->actual_from = $accountTariff->getDefaultActualFrom();
            $msg = $error . '. Дата включения сдвинута.';
            if (Yii::$app instanceof Application) {
                Yii::$app->session->setFlash('error', $msg);
            } else {
                echo PHP_EOL . 'Error: ' . $msg;
            }
        }
    }

    /**
     * Вернуть кол-во предыдущих логов
     *
     * @return int
     */
    private function _getCountLogs()
    {
        if (!is_null($this->_countLogs)) {
            return $this->_countLogs;
        }

        return $this->_countLogs = self::find()
            ->where(['account_tariff_id' => $this->account_tariff_id])
            ->count();
    }
}