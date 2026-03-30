<?php

namespace app\modules\uu\models;

use app\classes\model\ActiveRecord;
use app\classes\traits\GetInsertUserTrait;
use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\ClientAccount;
use app\models\User;
use app\modules\uu\behaviors\AccountTariffAddDefaultPackage;
use app\modules\uu\behaviors\AccountTariffBiller;
use app\modules\uu\behaviors\AccountTariffLogicalChangeLog;
use app\modules\uu\behaviors\AccountTariffLogTimeHistory;
use app\modules\uu\behaviors\FillAccountTariffResourceLog;
use app\modules\uu\behaviors\ReferentialPackageControl;
use app\modules\uu\classes\AccountLogFromToResource;
use app\modules\uu\classes\AccountLogFromToTariff;
use app\modules\uu\tarificator\AccountLogPeriodTarificator;
use app\modules\uu\tarificator\AccountLogResourceTarificator;
use app\modules\uu\tarificator\AccountLogSetupTarificator;
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
 * Лог изменений тарифов универсальной услуги
 *
 * @property int $id
 * @property int $account_tariff_id
 * @property int $tariff_period_id если null, то закрыто
 * @property string $actual_from_utc
 *
 * @property string $actual_from
 *
 * @property-read TariffPeriod $tariffPeriod
 * @property-read AccountTariff $accountTariff
 *
 * @method static AccountTariffLog findOne($condition)
 * @method static AccountTariffLog[] findAll($condition)
 */
class AccountTariffLog extends ActiveRecord
{
    // Перевод названий полей модели
    use \app\classes\traits\AttributeLabelsTraits;

    // Методы для полей insert_time, insert_user_id
    use GetInsertUserTrait;

    private $_countLogs = null;

    /** @var int Код ошибки для АПИ */
    public $errorCode = null;

    /** @var string Это поле только для записи в историю */
    public $user_info = '';

    public $connectionAmount = null;

    /**
     * @return string
     */
    public static function tableName()
    {
        return 'uu_account_tariff_log';
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            [['account_tariff_id', 'tariff_period_id'], 'integer'],
            [['account_tariff_id'], 'required'],
            ['actual_from', 'date', 'format' => 'php:' . DateTimeZoneHelper::DATE_FORMAT],
            ['actual_from', 'validatorFuture', 'skipOnEmpty' => false],
            ['actual_from', 'validatorPackage', 'skipOnEmpty' => false],
            ['tariff_period_id', 'validatorCreateNotClose', 'skipOnEmpty' => false],
            ['id', 'validatorBalance', 'skipOnEmpty' => false],
            ['tariff_period_id', 'validatorDoublePackage'],
            ['tariff_period_id', 'validatorNdcTypeAndSource'],
            ['tariff_period_id', 'validatorOneActive'],
            ['tariff_period_id', 'validatorWithTrunkOrVats'],
            ['user_info', 'string'],
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
                'TimestampBehaviorInsertTime' => [
                    // Установить "когда создал"
                    'class' => TimestampBehavior::class,
                    'createdAtAttribute' => 'insert_time',
                    'updatedAtAttribute' => false,
                    'value' => new Expression('UTC_TIMESTAMP()'), // "NOW() AT TIME ZONE 'utc'" (PostgreSQL) или 'UTC_TIMESTAMP()' (MySQL)
                ],
                'AttributeBehaviorInsertUser' => [
                    // Установить "кто создал"
                    'class' => AttributeBehavior::class,
                    'attributes' => [
                        ActiveRecord::EVENT_BEFORE_INSERT => 'insert_user_id',
                    ],
                    'value' => Yii::$app->user->getId(),
                ],
                'HistoryChanges' => \app\classes\behaviors\HistoryChanges::class,
                'AccountTariffAddDefaultPackage' => AccountTariffAddDefaultPackage::class,
                'AccountTariffBiller' => AccountTariffBiller::class, // Пересчитать транзакции, проводки и счета
                'FillAccountTariffResourceLog' => FillAccountTariffResourceLog::class, // Создать лог ресурсов при создании услуги. Удалить при удалении
                'ReferentialPackageControl' => ReferentialPackageControl::class,
                'AccountTariffLogicalChangeLog' => AccountTariffLogicalChangeLog::class,
                'AccountTariffLogTimeHistory' => AccountTariffLogTimeHistory::class, // Обновление время продажи и допродажи в модели AccountTariff
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
    public function getTariffPeriod()
    {
        return $this->hasOne(TariffPeriod::class, ['id' => 'tariff_period_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountTariff()
    {
        return $this->hasOne(AccountTariff::class, ['id' => 'account_tariff_id']);
    }

    /**
     * Вернуть сгенерированное имя
     *
     * @return string
     */
    public function getName()
    {
        return $this->tariffPeriod ?
            $this->tariffPeriod->getName() :
            Yii::t('common', 'Switched off');
    }

    /**
     * Вернуть html: имя + ссылка на тариф
     *
     * @return string
     */
    public function getTariffPeriodLink()
    {
        return $this->tariff_period_id ?
            $this->tariffPeriod->getLink() :
            Yii::t('common', 'Switched off');
    }

    /**
     * Валидировать дату смены тарифа
     *
     * @param string $attribute
     * @param array $params
     */
    public function validatorFuture($attribute, $params)
    {
        Yii::trace('AccountTariffLog. Before validatorFuture', 'uu');

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

        if (
            $this->actual_from_utc < $currentDateTimeUtc
            && !(
                // если нельзя, но очень хочется, то базовые пакеты иногда можно
                array_key_exists($accountTariff->service_type_id, ServiceType::$packages)
                && $this->tariff_period_id
                && ($this->tariffPeriod->tariff->is_default || $this->tariffPeriod->tariff->is_bundle)
            )
        ) {
            $this->addError($attribute, 'Нельзя менять тариф задним числом.');
            $this->errorCode = AccountTariff::ERROR_CODE_DATE_PREV;
            return;
        }

        if (
            $this->actual_from_utc == $currentDateTimeUtc
            && self::find()
                ->where(['account_tariff_id' => $this->account_tariff_id])
                ->andWhere(['=', 'actual_from_utc', $currentDateTimeUtc])
                ->count()
        ) {
            $this->addError($attribute, 'Сегодня тариф уже меняли. Теперь можно сменить его не ранее завтрашнего дня.');
            $this->errorCode = AccountTariff::ERROR_CODE_DATE_TODAY;
            return;
        }

        if (self::find()
                ->where(['account_tariff_id' => $this->account_tariff_id])
                ->andWhere(['>', 'actual_from_utc', $currentDateTimeUtc])
                ->count()
            && count($accountTariff->accountTariffLogs) > 1 // исключим проблему при смене таймзоны клиента на тестовом тарифе (менеджеры говорят, что это нормально)
        ) {
            $this->addError($attribute, 'Уже назначена смена тарифа в будущем. Если вы хотите установить новый тариф - сначала отмените эту смену.');
            $this->errorCode = AccountTariff::ERROR_CODE_DATE_FUTURE;
            return;
        }

        /*
        if (!$this->tariff_period_id
            && $this->actual_from < ($minEditDate = $accountTariff->getDefaultActualFrom())
            // менеджеру в Стате разрешено отключать услуги раньше срока
            && !(User::isLogged() || $accountTariff->isPricePackage())
        ) {
            $this->addError($attribute, 'Закрыть можно, начиная с ' . $minEditDate);
            $this->errorCode = AccountTariff::ERROR_CODE_DATE_PAID;
            return;
        }
        */

        Yii::trace('AccountTariffLog. After validatorFuture', 'uu');
    }

    /**
     * Валидировать, что при создании сразу же не закрытый
     *
     * @param string $attribute
     * @param array $params
     */
    public function validatorCreateNotClose($attribute, $params)
    {
        Yii::trace('AccountTariffLog. Before validatorCreateNotClose', 'uu');

        if (!$this->tariff_period_id && !$this->_getCountLogs()) {
            $this->addError($attribute, 'Не указан тариф / период.');
            $this->errorCode = AccountTariff::ERROR_CODE_TARIFF_EMPTY;
            return;
        }

        if (!$this->isNewRecord) {
            return;
        }

        if ($this->_getCountLogs()
            && $this->accountTariff->service_type_id != ServiceType::ID_ONE_TIME // одноразовая услуга создается и сразу же закрывается
            && !$this->accountTariff->isLogEditable()
        ) {
            $this->addError($attribute, 'Услуга нередактируемая.');
            $this->errorCode = AccountTariff::ERROR_CODE_USAGE_NOT_EDITABLE;
            return;
        }

        $accountTariffLog = $this->accountTariff->getAccountTariffLogs()->one();
        if ($accountTariffLog) {

            if ($this->tariff_period_id == $accountTariffLog->tariff_period_id) {
                $this->addError($attribute, 'Нет смысла менять период/тариф на тот же самый. Выберите другой период/тариф.');
                $this->errorCode = AccountTariff::ERROR_CODE_TARIFF_SAME;
                return;
            }

            if (!$accountTariffLog->tariff_period_id) {
                $this->addError($attribute, 'После закрытия нельзя ничего делать.');
                $this->errorCode = AccountTariff::ERROR_CODE_USAGE_NOT_EDITABLE;
                return;
            }
        }

        Yii::trace('AccountTariffLog. After validatorCreateNotClose', 'uu');
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

    /**
     * Вернуть уникальный Id
     * Поле id хоть и уникальное, но не подходит для поиска нерассчитанных данных при тарификации
     *
     * @return string
     */
    public function getUniqueId()
    {
        return $this->actual_from . '_' . $this->tariff_period_id;
    }

    /**
     * Валидировать, что realtime balance больше обязательного платежа по услуге (подключение + абонентка + ресурс + минималка)
     * В логе, а не услуге, потому что нужна дата включения
     *
     * @param string $attribute
     * @param array $params
     * @throws \RangeException
     * @throws \Exception
     */
    public function validatorBalance($attribute, $params)
    {
        Yii::trace('AccountTariffLog. Before validatorBalance', 'uu');

        if (!$this->isNewRecord) {
            return;
        }

        $accountTariff = $this->accountTariff;
        if (!$accountTariff) {
            $this->addError($attribute, 'Услуга не указана.');
            $this->errorCode = AccountTariff::ERROR_CODE_USAGE_EMPTY;
            return;
        }

        $clientAccount = $accountTariff->clientAccount;
        if (!$clientAccount) {
            $this->addError($attribute, 'ЛС не указан.');
            $this->errorCode = AccountTariff::ERROR_CODE_ACCOUNT_EMPTY;
            return;
        }

        if ($clientAccount->account_version != ClientAccount::VERSION_BILLER_UNIVERSAL) {
            $this->addError($attribute, 'Универсальную услугу можно добавить только ЛС, тарифицируемому универсально.');
            $this->errorCode = AccountTariff::ERROR_CODE_ACCOUNT_IS_NOT_UU;
            return;
        }


        $tariffPeriod = $this->tariffPeriod;
        if ($tariffPeriod) {
            $tariff = $tariffPeriod->tariff;
            if ($tariff->currency_id != $clientAccount->currency) {
                $this->addError($attribute, sprintf('Валюта акаунта %s и тарифа %s не совпадают.', $clientAccount->currency, $tariffPeriod->tariff->currency_id));
                $this->errorCode = AccountTariff::ERROR_CODE_ACCOUNT_CURRENCY;
                return;
            }

            $isPackage = array_key_exists($tariff->service_type_id, ServiceType::$packages);
            if ($isPackage && ($tariff->is_default || $tariff->is_bundle || $tariff->is_charge_after_blocking)) {
                // пакеты по умолчанию подключаются/отключаются автоматически. Им можно всё
                // пакеты со списание после блокировки можно подключить всегда
                return;
            }
        }

        $warnings = $clientAccount->getVoipWarnings();
        $isCountLogs = $this->_getCountLogs(); // смена тарифа или закрытие услуги

        if (!$tariffPeriod) {
            if ($isCountLogs) {
                // закрытие услуги
                return;
            }

            // подключение новой услуги
            $this->addError($attribute, 'Не указан тариф / период.');
            $this->errorCode = AccountTariff::ERROR_CODE_TARIFF_EMPTY;
            return;
        }

        if ($clientAccount->is_blocked) {
            $error = 'ЛС заблокирован';
            if ($isCountLogs) {
                // смена тарифа
                $this->_shiftActualFrom($error);
            } else {
                // подключение новой услуги
                $this->addError($attribute, $error);
            }

            $this->errorCode = AccountTariff::ERROR_CODE_ACCOUNT_BLOCKED_PERMANENT;
            return;
        }

        if (isset($warnings[ClientAccount::WARNING_OVERRAN])) {
            $error = 'ЛС заблокирован из-за превышения лимитов';
            if ($isCountLogs) {
                // смена тарифа
                $this->_shiftActualFrom($error);
            } else {
                // подключение новой услуги
                $this->addError($attribute, $error);
            }

            $this->errorCode = AccountTariff::ERROR_CODE_ACCOUNT_BLOCKED_TEMPORARY;
            return;
        }

        list($tariffPrice, $accountLogSetup, $accountLogPeriod, $priceResources, $priceMin) = $this->getConnectionAmount($accountTariff, $tariffPeriod, $isCountLogs, true);

        if ($tariffPrice > 0) {
            // Эти проверки только для платной услуги

            $credit = $clientAccount->credit; // кредитный лимит
            $estimationSum = Estimation::find()->where(['client_account_id' => $accountTariff->client_account_id])->sum('price') ?: 0;
            $realtimeBalance = $clientAccount->balance - $estimationSum;
            $realtimeBalanceWithCredit = ($realtimeBalance + $credit);


            if ($realtimeBalanceWithCredit < 0 || isset($warnings[ClientAccount::WARNING_FINANCE]) || isset($warnings[ClientAccount::WARNING_CREDIT])) {
                $error = sprintf('Платную услугу нельзя подключить, потому что ЛС находится в финансовой блокировке. На счету %.2f %s и кредит %.2f %s', $realtimeBalance, $clientAccount->currency, $credit, $clientAccount->currency);
                if ($isCountLogs) {
                    // смена тарифа
                    $this->_shiftActualFrom($error);
                } else {
                    // подключение новой услуги
                    $this->addError($attribute, $error);
                }

                $this->errorCode = AccountTariff::ERROR_CODE_ACCOUNT_BLOCKED_FINANCE;
                return;
            }

            if ($realtimeBalanceWithCredit < $tariffPrice) {
                $error = sprintf(
                    'На ЛС %.2f и кредит %.2f = %.2f %s, что меньше первичного платежа по тарифу, который составляет %.2f %s (подключение %.2f + абонентка %.2f + ресурсы %.2f + минималка %.2f)',
                    $realtimeBalance,
                    $credit,
                    $realtimeBalanceWithCredit,
                    $clientAccount->currency,
                    $tariffPrice,
                    $clientAccount->currency,
                    $accountLogSetup->price,
                    $accountLogPeriod->price,
                    $priceResources,
                    $priceMin
                );

                $this->addError($attribute, $error);
                $this->errorCode = AccountTariff::ERROR_CODE_ACCOUNT_MONEY;
                return;
            }
        }

        // все хорошо - денег хватает
        // на самом деле мы не знаем, сколько клиент уже потратил на звонки сегодня. Но это дело низкоуровневого биллинга. Если денег не хватит - заблокирует финансово
        // транзакции не сохраняем, деньги пока не списываем. Подробнее см. AccountTariffBiller
        Yii::trace('AccountTariffLog. After validatorBalance', 'uu');
    }

    public function afterSave($isInsert, $changedAttributes)
    {
        if ($this->connectionAmount !== null && abs((float)$this->connectionAmount) >= 0.01) {

            $estimation = new Estimation();
            $estimation->client_account_id = $this->accountTariff->client_account_id;
            $estimation->account_tariff_id = $this->account_tariff_id;
            $estimation->price = (float)$this->connectionAmount;

            if (!$estimation->save()) {
                throw new ModelValidationException($estimation);
            }
        }
        parent::afterSave($isInsert, $changedAttributes);
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
     * Валидировать, что дата включения пакета не раньше даты включения услуги
     *
     * @param string $attribute
     * @param array $params
     */
    public function validatorPackage($attribute, $params)
    {
        Yii::trace('AccountTariffLog. Before validatorPackage', 'uu');

        $isNewRecord = !$this->_getCountLogs();
        if (!$isNewRecord) {
            // смена тарифа или закрытие услуги. А все последующие проверки только при создании услуги
            return;
        }

        $accountTariff = $this->accountTariff;
        if (!array_key_exists($accountTariff->service_type_id, ServiceType::$packages)) {
            // не пакет
            return;
        }

        $prevAccountTariff = $accountTariff->prevAccountTariff;
        if (!$prevAccountTariff) {
            $this->addError($attribute, 'Не указана основная услуга для пакета');
            $this->errorCode = AccountTariff::ERROR_CODE_USAGE_MAIN;
            return;
        }

        $prevAccountTariffLogs = $prevAccountTariff->accountTariffLogs;
        if (count($prevAccountTariffLogs) > 1) {
            // основная услуга уже действует
            return;
        }

        $prevAccountTariffLog = reset($prevAccountTariffLogs);
        if ($prevAccountTariffLog->actual_from > $this->actual_from) {
            $this->addError($attribute, sprintf('Пакет может начать действовать (%s) только после начала действия (%s) основной услуги', $this->actual_from, $prevAccountTariffLog->actual_from));
            $this->errorCode = AccountTariff::ERROR_CODE_DATE_TARIFF;
            return;
        }

        Yii::trace('AccountTariffLog. After validatorPackage', 'uu');
    }

    /**
     * Установить actual_from из date в таймзоне клиента в datetime UTC
     * Для совместимости, ибо старое поле actual_from удалено
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
     * Для совместимости, ибо старое поле actual_from удалено
     *
     * @return string|null
     * @throws \Exception
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
     * Валидировать, что пакет подключен только 1 раз
     *
     * @param string $attribute
     * @param array $params
     */
    public function validatorDoublePackage($attribute, $params)
    {
        if (!$this->tariff_period_id) {
            // закрытие услуги всегда можно
            return;
        }

        $accountTariff = $this->accountTariff;
        if (!$accountTariff->prev_account_tariff_id) {
            // не пакет
            return;
        }

        switch ($accountTariff->service_type_id) {
            case ServiceType::ID_VOIP_PACKAGE_CALLS:
                // только для пакетов звонков
                /** @var AccountTariff[] $accountTariffSiblings */
                $accountTariffSiblings = AccountTariff::find()
                    ->where(
                        [
                            'service_type_id' => $accountTariff->service_type_id,
                            'prev_account_tariff_id' => $accountTariff->prev_account_tariff_id,
                            'tariff_period_id' => $this->tariff_period_id,
                        ]
                    )
                    ->andWhere(['!=', 'id', (int)$this->account_tariff_id])// кроме себя же
                    ->all();
                foreach ($accountTariffSiblings as $accountTariffSibling) {
                    if (
                        (
                            $accountTariffSibling->tariffPeriod->tariff->getPackageMinutes()->count()
                            + $accountTariffSibling->tariffPeriod->tariff->getPackagePricelistsNnp()->andWhere(['>', 'minute', 0])->count()
                        ) == 0) {
                        // пакет с минутами подключать повторно можно (минуты суммируются), а все остальное нельзя (не имеет смысла, ибо дешевый прайслист и так действует)
                        $this->addError($attribute, 'Этот пакет уже подключен на эту же базовую услугу. Повторное подключение не имеет смысла.');
                        $this->errorCode = AccountTariff::ERROR_CODE_USAGE_DOUBLE_PREV;
                        return;
                    }
                }
                break;

            case ServiceType::ID_VOIP_PACKAGE_INTERNET:
                // только для пакетов интернета

                $countOfCarryPeriod = $accountTariff->tariffPeriod->tariff->count_of_carry_period;

                /** @var AccountTariff[] $accountTariffSiblings */
                $accountTariffSiblings = AccountTariff::find()
                    ->where(
                        [
                            'service_type_id' => $accountTariff->service_type_id,
                            'prev_account_tariff_id' => $accountTariff->prev_account_tariff_id,
                        ]
                    )
                    ->andWhere(['NOT', ['tariff_period_id' => null]])// включенные
                    ->andWhere(['!=', 'id', (int)$this->account_tariff_id])// кроме себя же
                    ->all();
                foreach ($accountTariffSiblings as $accountTariffSibling) {
                    $countOfCarryPeriodSibling = $accountTariffSibling->tariffPeriod->tariff->count_of_carry_period;
                    if (
                        ($countOfCarryPeriodSibling && !$countOfCarryPeriod) ||
                        (!$countOfCarryPeriodSibling && $countOfCarryPeriod)
                    ) {
                        $this->addError($attribute, 'Сгораемые и несгораемые пакеты интернета несовместимы');
                        $this->errorCode = AccountTariff::ERROR_CODE_USAGE_BURN_INTERGER;
                        return;
                    }
                }
                break;
        }

        // Любой менеджер или аккаунт-менеджер имеет право добавлять неограниченное количество пакетов в день
        if (User::isManagerLogined()) {
            return;
        }

        $accountTariffTableName = AccountTariff::tableName();
        $accountTariffLogTableName = AccountTariffLog::tableName();
        if (AccountTariffLog::find()
            ->joinWith('accountTariff')
            ->where(
                [
                    $accountTariffTableName . '.service_type_id' => $accountTariff->service_type_id,
                    $accountTariffTableName . '.prev_account_tariff_id' => $accountTariff->prev_account_tariff_id,
                    $accountTariffLogTableName . '.tariff_period_id' => $this->tariff_period_id,
                ]
            )
            ->andWhere(
                [
                    '>=',
                    $accountTariffLogTableName . '.actual_from_utc',
                    (new DateTime())->modify('-1 day')->format(DateTimeZoneHelper::DATETIME_FORMAT) // "-1 day" для того, чтобы не мучиться с таймзоной клиента, а гарантированно получить нужное
                ]
            )
            ->andWhere(
                [
                    '!=',
                    $accountTariffLogTableName . '.id',
                    $this->id // Всё, кроме этого пакета. Необходимо при изменении даты включения.
                ])
            ->count()
        ) {
            $this->addError($attribute, 'Этот пакет уже запланирован на подключение на эту же базовую услугу. Повторное подключение не имеет смысла.');
            $this->errorCode = AccountTariff::ERROR_CODE_USAGE_DOUBLE_FUTURE;
            return;
        }

    }

    /**
     * Валидировать, что телефония может быть подключена только для соответствующего тарифа
     *
     * @param string $attribute
     * @param array $params
     */
    public function validatorNdcTypeAndSource($attribute, $params)
    {
        if (!$this->tariff_period_id) {
            // закрытие услуги всегда можно
            return;
        }

        $accountTariff = $this->accountTariff;
        switch ($accountTariff->service_type_id) {
            case ServiceType::ID_VOIP:
                $number = $accountTariff->number;
                break;
            case ServiceType::ID_VOIP_PACKAGE_CALLS:
                $prevAccountTariff = $accountTariff->prevAccountTariff;
                $number = $prevAccountTariff ? $prevAccountTariff->number : null;
                break;
            default:
                // не телефония
                return;
        }

        if (!$number) {
            return;
        }

        if (!isset($this->tariffPeriod->tariff->voipNdcTypes[$number->ndc_type_id])) {
            $this->addError($attribute, 'Этот тариф для другого типа NDC, чем телефонный номер.');
            $this->errorCode = AccountTariff::ERROR_CODE_USAGE_DOUBLE_PREV;
            return;
        }

        $voipSources = $this->tariffPeriod->tariff->voipSources;

        if ($voipSources && !in_array($number->source, array_keys($voipSources))) {
            $this->addError($attribute, 'Неподходящий источник номера');
            $this->errorCode = AccountTariff::ERROR_CODE_TARIFF_WRONG;
            return;
        }
    }

    public function validatorOneActive($attribute, $params)
    {
        // закртытие или тариф можно подключить болле 1 раза.
        if (!$this->tariff_period_id || !$this->tariffPeriod->tariff->is_one_active) {
            return;
        }

        $accountTariff = $this->accountTariff;
        // поиск уже включенных

        $query = AccountTariff::find()
            ->where([
                'prev_account_tariff_id' => $accountTariff->prev_account_tariff_id,
                'tariff_period_id' => $this->tariff_period_id,
                'client_account_id' => $accountTariff->id,
            ])->andWhere(['not', ['id' => $accountTariff->id]]);

        if ($query->count()) {
            $this->addError($attribute, 'Тариф нельзя включить более одного раза');
            $this->errorCode = AccountTariff::ERROR_CODE_USAGE_DEFAULT;
            return;
        }

        $accountTariffTableName = AccountTariff::tableName();
        $accountTariffLogTableName = AccountTariffLog::tableName();
        if (AccountTariffLog::find()
            ->joinWith('accountTariff')
            ->where(
                [
                    $accountTariffTableName . '.client_account_id' => $accountTariff->client_account_id,
                    $accountTariffTableName . '.service_type_id' => $accountTariff->service_type_id,
                    $accountTariffTableName . '.prev_account_tariff_id' => $accountTariff->prev_account_tariff_id,
                    $accountTariffLogTableName . '.tariff_period_id' => $this->tariff_period_id,
                ]
            )
            ->andWhere(
                [
                    '>=',
                    $accountTariffLogTableName . '.actual_from_utc',
                    (new DateTime())->modify('-1 day')->format(DateTimeZoneHelper::DATETIME_FORMAT) // "-1 day" для того, чтобы не мучиться с таймзоной клиента, а гарантированно получить нужное
                ]
            )
            ->andWhere(['not', [$accountTariffLogTableName . '.id' => $this->id]])
            ->count()
        ) {
            $this->addError($attribute, 'Тариф нельзя включить более одного раза.');
            $this->errorCode = AccountTariff::ERROR_CODE_USAGE_DEFAULT;
            return;
        }

    }

    /**
     * При подключении VoIP-тарифа с опцией "Только для ВАТС/транк"
     * на ЛС должна быть активная услуга ВАТС или транк
     */
    public function validatorWithTrunkOrVats($attribute, $params)
    {
        if (!$this->tariff_period_id) {
            return;
        }

        // включается переносимая услуга
        if ($this->accountTariff->isTransfer) {
            return;
        }

        $accountTariff = $this->accountTariff;
        if (!$accountTariff || $accountTariff->service_type_id != ServiceType::ID_VOIP) {
            return;
        }

        $tariffPeriod = $this->tariffPeriod;
        if (!$tariffPeriod) {
            return;
        }

        $tariffResource = TariffResource::find()
            ->where([
                'tariff_id' => $tariffPeriod->tariff_id,
                'resource_id' => ResourceModel::ID_VOIP_ONLY_FOR_TRUNK_VATS,
            ])
            ->one();

        if (!$tariffResource || !$tariffResource->amount) {
            return;
        }

        $hasVatsOrTrunk = AccountTariff::find()
            ->where([
                'client_account_id' => $accountTariff->client_account_id,
                'service_type_id' => [ServiceType::ID_VPBX, ServiceType::ID_TRUNK],
            ])
            ->andWhere(['NOT', ['tariff_period_id' => null]])
            ->exists();

        if (!$hasVatsOrTrunk) {
            $this->addError($attribute, 'Тариф доступен только при наличии активной услуги ВАТС или транк на лицевом счёте');
            $this->errorCode = AccountTariff::ERROR_CODE_ACCOUNT_NEED_VATS_OR_TRUNK;
        }
    }

    public function getConnectionAmount($accountTariff = null, $tariffPeriod = null, $isCountLogs = null, $isWithObjects = false)
    {
        if (!$accountTariff) {
            $accountTariff = $this->accountTariff;
        }

        if (!$tariffPeriod) {
            $tariffPeriod = $this->tariffPeriod;
        }

        if ($isCountLogs === null) {
            $isCountLogs = $this->_getCountLogs();
        }

        $accountLogFromToTariff = new AccountLogFromToTariff();
        $accountLogFromToTariff->dateFrom = new DateTimeImmutable($this->actual_from);
        $accountLogFromToTariff->dateTo = $tariffPeriod->chargePeriod->getMinDateTo($accountLogFromToTariff->dateFrom);
        $accountLogFromToTariff->tariffPeriod = $tariffPeriod;
        $accountLogFromToTariff->isFirst = true;

        // AccountLogSetupTarificator и AccountLogPeriodTarificator сейчас нельзя вызвать, потому что записи в логе тарифов еще нет
        // AccountLogResourceTarificator пока нет
        //
        // подключение
        $accountLogSetup = (new AccountLogSetupTarificator())->getAccountLogSetup($accountTariff, $accountLogFromToTariff);
        if (
            $isCountLogs > 1 // Это уже не первая смена тарифа. Плату за подключение уже взимали раньше
            || (
                // первая смена с не-тестового. Плату за подключение уже взимали раньше
                $isCountLogs == 1
                && ($accountTariffLogPrev = self::findOne(['account_tariff_id' => $this->account_tariff_id]))
                && !$accountTariffLogPrev->tariffPeriod->tariff->getIsTest()
            )
        ) {
            // Плата за номер не взимается
            $accountLogSetup->price = max(0, $accountLogSetup->price - $accountLogSetup->price_number);
            $accountLogSetup->price_number = 0;
        }

        // абонентка
        $accountLogPeriod = (new AccountLogPeriodTarificator())->getAccountLogPeriod($accountTariff, $accountLogFromToTariff);

        // ресурсы
        $accountLogResourceTarificator = (new AccountLogResourceTarificator());

        $priceResources = 0;
        $tariffResources = $tariffPeriod->tariff->tariffResources;
        foreach ($tariffResources as $tariffResource) {

            if (!ResourceModel::isOptionId($tariffResource->resource_id)) {
                // этот ресурс - не опция. Он считается по факту, а не заранее
                continue;
            }

            $accountLogFromToResource = new AccountLogFromToResource;
            $accountLogFromToResource->dateFrom = $accountLogFromToTariff->dateFrom;
            $accountLogFromToResource->dateTo = $accountLogFromToTariff->dateTo;
            $accountLogFromToResource->tariffPeriod = $tariffPeriod;
            $accountLogFromToResource->amountOverhead = (float)$accountTariff->getResourceValue($tariffResource->resource_id, $isCurrentOnly = false); // текущее кол-во ресурса может быть null, если услуга только создается
            if ($accountLogFromToResource->amountOverhead) {
                $accountLogFromToResource->amountOverhead -= $tariffResource->amount; // в amountOverhead должно быть не общее кол-во ресурса, а лишь превышение
            }

            $accountLogResource =
                $accountLogResourceTarificator
                    ->getAccountLogResource($accountTariff, $accountLogFromToResource, $tariffResource->resource_id);
            $priceResources += $accountLogResource->price;
        }

        // минималка
        $priceMin = ($tariffPeriod->price_min * $accountLogPeriod->coefficient);
        $priceMin -= $priceResources;
        $priceMin = max(0, $priceMin);

        // суммарный платеж
        $this->connectionAmount = $accountLogSetup->price + $accountLogPeriod->price + $priceResources + $priceMin;
        return !$isWithObjects ? $this->connectionAmount : [$this->connectionAmount, $accountLogSetup, $accountLogPeriod, $priceResources, $priceMin];
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

            case 'tariff_period_id':
                if (!$value) {
                    return 'Закрыто';
                }

                if ($tariffPeriod = TariffPeriod::findOne(['id' => $value])) {
                    return $tariffPeriod->getLink();
                }
                break;

            default:
                return parent::prepareHistoryValue($field, $value);
        }

        return true;
    }

    /**
     * Какие поля не показывать в исторических данных
     *
     * @param string $action
     * @return string[]
     */
    public static function getHistoryHiddenFields($action)
    {
        return [
            'id',
            'account_tariff_id',
            'insert_user_id',
            'insert_time',
        ];
    }

    /**
     * Вернуть ID родителя
     *
     * @return int
     */
    public function getParentId()
    {
        return $this->account_tariff_id;
    }

    /**
     * Установить ID родителя
     *
     * @param int $parentId
     */
    public function setParentId($parentId)
    {
        $this->account_tariff_id = $parentId;
    }

    /**
     * @return array
     */
    public static function getHelpConfluence()
    {
        return ['confluenceId' => 25887561, 'message' => 'Условия подключение тарифа'];
    }
}