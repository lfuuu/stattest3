<?php

namespace app\modules\uu\models;

use app\classes\behaviors\ModelLifeRecorder;
use app\classes\model\ActiveRecord;
use app\classes\traits\GetInsertUserTrait;
use app\classes\traits\GetUpdateUserTrait;
use app\classes\validators\FormFieldValidator;
use app\modules\uu\behaviors\AccountTariffCheckHlr;
use app\modules\uu\behaviors\AccountTariffImportantEvents;
use app\modules\uu\behaviors\AccountTariffLogicalChangeLog;
use app\modules\uu\behaviors\AccountTariffTransferClean;
use app\modules\uu\behaviors\AccountTariffVoipNumber;
use app\modules\uu\models\traits\AccountTariffBillerPeriodTrait;
use app\modules\uu\models\traits\AccountTariffBillerResourceTrait;
use app\modules\uu\models\traits\AccountTariffBillerSetupTrait;
use app\modules\uu\models\traits\AccountTariffBillerTrait;
use app\modules\uu\models\traits\AccountTariffBoolTrait;
use app\modules\uu\models\traits\AccountTariffGroupTrait;
use app\modules\uu\models\traits\AccountTariffHistoryTrait;
use app\modules\uu\models\traits\AccountTariffInfrastructure;
use app\modules\uu\models\traits\AccountTariffLinkTrait;
use app\modules\uu\models\traits\AccountTariffListTrait;
use app\modules\uu\models\traits\AccountTariffPackageTrait;
use app\modules\uu\models\traits\AccountTariffRelationsTrait;
use app\modules\uu\models\traits\AccountTariffValidatorTrait;
use Yii;
use yii\behaviors\AttributeBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * Универсальная услуга
 *
 * @property int $id
 * @property int $client_account_id
 * @property int $service_type_id
 * @property int $region_id
 * @property int $city_id
 * @property int $prev_account_tariff_id   Основная услуга
 * @property int $tariff_period_id   Если null, то закрыто. Кэш AccountTariffLog->TariffPeriod
 * @property string $comment
 * @property int $voip_number номер линии (если 4-5 символов) или телефона (fk на voip_numbers)
 * @property int $vm_elid_id ID VPS
 * @property int $prev_usage_id
 * @property int $is_unzipped
 * @property string $mtt_number
 * @property double $mtt_balance
 * @property int $trunk_type_id
 * @property int $infrastructure_project
 * @property int $infrastructure_level
 * @property int $price
 * @property int $datacenter_id
 * @property string $device_address
 * @property string $tariff_period_utc
 * @property string $account_log_period_utc
 * @property string $account_log_resource_utc
 * @property string $calltracking_params
 * @property string $route_name
 * @property string $route_name_default
 * @property int $calligrapher_node_id
 * @property int $calligrapher_type_connection_id
 * @property integer $is_verified
 * @property string $domain_name
 */
class AccountTariff extends ActiveRecord
{
    // Перевод названий полей модели
    use \app\classes\traits\AttributeLabelsTraits;

    use GetInsertUserTrait;
    use GetUpdateUserTrait;

    use AccountTariffRelationsTrait;
    use AccountTariffBillerTrait;
    use AccountTariffBillerSetupTrait;
    use AccountTariffBillerResourceTrait;
    use AccountTariffBillerPeriodTrait;
    use AccountTariffValidatorTrait;
    use AccountTariffBoolTrait;
    use AccountTariffGroupTrait;
    use AccountTariffPackageTrait;
    use AccountTariffLinkTrait;
    use AccountTariffListTrait;
    use AccountTariffHistoryTrait;
    use AccountTariffInfrastructure;

    const DELTA = 100000;

    const TRUNK_TYPE_MEGATRUNK = 1;
    const TRUNK_TYPE_MULTITRUNK = 2; // настраивается на Asterisk и физически не связана с услугой в стате, услуга в стате будет использоваться исключительно для билингации, канальность устанавливается пользователем в стате

    // Ошибки ЛС
    const ERROR_CODE_ACCOUNT_EMPTY = 1; // ЛС не указан
    const ERROR_CODE_ACCOUNT_BLOCKED_PERMANENT = 2; // ЛС заблокирован
    const ERROR_CODE_ACCOUNT_BLOCKED_TEMPORARY = 3; // ЛС заблокирован из-за превышения лимитов
    const ERROR_CODE_ACCOUNT_BLOCKED_FINANCE = 4; // ЛС в финансовой блокировке
    const ERROR_CODE_ACCOUNT_IS_NOT_UU = 5; // Универсальную услугу можно добавить только ЛС, тарифицируемому универсально
    const ERROR_CODE_ACCOUNT_IS_UU = 6; // Неуниверсальную услугу можно добавить только ЛС, тарифицируемому неуниверсально
    const ERROR_CODE_ACCOUNT_CURRENCY = 7; // Валюта акаунта и тарифа не совпадают
    const ERROR_CODE_ACCOUNT_MONEY = 8; // На ЛС даже с учетом кредита меньше первичного платежа по тарифу
    const ERROR_CODE_ACCOUNT_TRUNK = 9; // Универсальную услугу транка можно добавить только ЛС с договором Межоператорка
    const ERROR_CODE_ACCOUNT_TRUNK_SINGLE = 10; // Для ЛС можно создать только одну базовую услугу транка. Зато можно добавить несколько пакетов.
    const ERROR_CODE_ACCOUNT_POSTPAID = 11; // ЛС и тариф должны быть либо оба предоплатные, либо оба постоплатные
    const ERROR_CODE_ACCOUNT_NEED_VATS_OR_TRUNK = 12; // Тариф с опцией "Только для ВАТС/транк" требует активной услуги ВАТС или транк

    // Ошибки даты
    const ERROR_CODE_DATE_PREV = 21; // Нельзя менять задним числом
    const ERROR_CODE_DATE_TODAY = 22; // Сегодня уже меняли. Теперь можно сменить его не ранее завтрашнего дня
    const ERROR_CODE_DATE_FUTURE = 23; // Уже назначена смена в будущем. Если вы хотите установить новое значение - сначала отмените эту смену
    const ERROR_CODE_DATE_TARIFF = 24; // Пакет телефонии может начать действовать только после начала действия основной услуги телефонии
    const ERROR_CODE_DATE_PAID = 25; // Нельзя менять раньше уже оплаченного периода

    // Ошибки тарифа
    const ERROR_CODE_SERVICE_TYPE = 31; // Нельзя менять тип услуги
    const ERROR_CODE_TARIFF_EMPTY = 32; // Не указан тариф / период
    const ERROR_CODE_TARIFF_WRONG = 33; // Неправильный тариф / период
    const ERROR_CODE_TARIFF_SERVICE_TYPE = 34; // Тариф / период не соответствует типу услуги
    const ERROR_CODE_TARIFF_SAME = 35; // Нет смысла менять на то же самое значение. Выберите другое значение

    // Ошибки услуги
    const ERROR_CODE_USAGE_EMPTY = 41; // Услуга не указана
    const ERROR_CODE_USAGE_MAIN = 42; // Не указана основная услуга телефонии для пакета телефонии
    const ERROR_CODE_USAGE_DOUBLE_PREV = 43; // Этот пакет уже подключен на эту же базовую услугу. Повторное подключение не имеет смысла.
    const ERROR_CODE_USAGE_DOUBLE_FUTURE = 44; // Этот пакет уже запланирован на подключение на эту же базовую услугу. Повторное подключение не имеет смысла
    const ERROR_CODE_USAGE_CANCELABLE = 45; // Нельзя отменить уже примененный тариф
    const ERROR_CODE_USAGE_DEFAULT = 46; // Нельзя подключить второй базовый пакет на ту же услугу
    const ERROR_CODE_USAGE_NOT_EDITABLE = 47; // Услуга нередактируемая
    const ERROR_CODE_USAGE_NUMBER_NOT_IN_STOCK = 48; // Этот телефонный номер нельзя подключить
    const ERROR_CODE_NUMBER_NOT_FOUND = 49; // Номер не найден
    const ERROR_CODE_USAGE_BURN_INTERGER = 50; // Сгораемые и несгораемые пакеты интернета несовместимы

    // Ошибки ресурса
    const ERROR_CODE_RESOURCE_WRONG = 51; // Указан несуществующий ресурс
    const ERROR_CODE_RESOURCE_TYPE_WRONG = 52; // Этот ресурс от другого типа услуги
    const ERROR_CODE_RESOURCE_TRAFFIC = 54; // Этот ресурс - трафик, а не опция. Его нельзя установить заранее.
    const ERROR_CODE_RESOURCE_AMOUNT_MIN = 55; // Значение ресурса меньше минимально допустимого значения.
    const ERROR_CODE_RESOURCE_AMOUNT_MAX = 56; // Значение ресурса больше максимально допустимого значения.
    const ERROR_CODE_RESOURCE_NOT_CANCELABLE = 57; // Ресурс невозможно отменить

    // Ошибка телефонии
    const ERROR_CODE_VOIP_WRONG_STATUS = 72; // Статус тарифа не совпадает со статусом телефонного номера

    // Ошибки шаблона
    const ERROR_CODE_TEMPLATE_TYPE_NOT_FOUND = 81; // Указан неправильный template_id
    const ERROR_CODE_TEMPLATE_NOT_FOUND = 82; // Для текущей страны клиента шаблон не найден

    protected $isAttributeTypecastBehavior = true;

    public $voip_numbers_warehouse_status = null;

    public array $attributesProtectedForVersioning = ['account_log_period_utc', 'account_log_resource_utc'];

    /**
     * @return array
     */
    public function behaviors()
    {
        return array_merge(
            parent::behaviors(),
            [
                \app\classes\behaviors\HistoryChanges::class,
                AccountTariffImportantEvents::class,
                AccountTariffVoipNumber::class,
                AccountTariffTransferClean::class,
                AccountTariffCheckHlr::class,
                AccountTariffLogicalChangeLog::class,
//                ClientChangeNotifier::class,
                [
                    // Установить "когда создал" и "когда обновил"
                    'class' => TimestampBehavior::class,
                    'createdAtAttribute' => 'insert_time',
                    'updatedAtAttribute' => 'update_time',
                    'value' => new Expression('UTC_TIMESTAMP()'), // "NOW() AT TIME ZONE 'utc'" (PostgreSQL) или 'UTC_TIMESTAMP()' (MySQL)
                ],
                [
                    // Установить "кто создал" и "кто обновил"
                    'class' => AttributeBehavior::class,
                    'attributes' => [
                        ActiveRecord::EVENT_BEFORE_INSERT => ['insert_user_id', 'update_user_id'],
                        ActiveRecord::EVENT_BEFORE_UPDATE => 'update_user_id',
                    ],
                    'value' => Yii::$app->user->getId(),
                ],
                'ModelLifeRec' => [
                    'class' => ModelLifeRecorder::class,
                    'modelName' => 'service',
                    'isRegisterUpdate' => false,
                ]

            ]
        );
    }

    /**
     * @return string
     */
    public static function tableName()
    {
        return 'uu_account_tariff';
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            [['client_account_id', 'service_type_id'], 'required', 'on' => ['default', 'serviceTransfer']],
            [
                [
                    'client_account_id',
                    'service_type_id',
                    'region_id',
                    'city_id',
                    'prev_account_tariff_id',
                    'trunk_type_id',
                    'vm_elid_id',
                    'voip_numbers_warehouse_status',
                    'calligrapher_node_id',
                    'calligrapher_type_connection_id',
                ],
                'integer'
            ],
            [['comment', 'device_address', 'calltracking_params', 'domain_name'], 'string'],
            [['comment', 'device_address'], FormFieldValidator::class, 'skipOnError' => true],
            ['voip_number', 'match', 'pattern' => '/^\d{4,15}$/'],
            ['service_type_id', 'validatorServiceType'],
            ['client_account_id', 'validatorTrunk', 'skipOnEmpty' => false],
            ['service_type_id', 'validatorTariffPeriod'],
            ['voip_number', 'validatorVoipNumber', 'skipOnEmpty' => true, 'on' => ['default']],
            [
                ['voip_number'],
                'required',
                'when' => function (AccountTariff $accountTariff) {
                    return $accountTariff->service_type_id == ServiceType::ID_VOIP;
                },
                'whenClient' => 'function(attribute, value) { return false; }', // не проверять на клиенте
            ],
            [
                ['region_id'],
                'required',
                'when' => function (AccountTariff $accountTariff) {
                    return in_array($accountTariff->service_type_id, [ServiceType::ID_VPBX, ServiceType::ID_SIPTRUNK]);
                },
                'whenClient' => 'function(attribute, value) { return false; }', // не проверять на клиенте
            ],
            ['region_id', 'validateRegion', 'skipOnEmpty' => false],

            [['infrastructure_project', 'infrastructure_level', 'datacenter_id'], 'integer'],
            [['price'], 'number'],
            [
                ['price'],
                'required',
                'skipOnEmpty' => false,
                'when' => function (AccountTariff $accountTariff) {
                    return $accountTariff->service_type_id == ServiceType::ID_INFRASTRUCTURE;
                },
                'whenClient' => 'function(attribute, value) { return false; }', // не проверять на клиенте
            ],
            ['iccid', 'validateIccid', 'when' => fn(AccountTariff $accountTariff) => $accountTariff->isNewRecord && $accountTariff->service_type_id == ServiceType::ID_ESIM],
            ['calltracking_params', function ($value) {
                if ($this->calltracking_params && !json_decode($this->calltracking_params)) {
                    $this->addError('calltracking_params', 'Невалидный JSON формат');
                }
            }],
        ];
    }

    /**
     * Является ли услуга тестовой для отрицательных проводок
     *
     * @return bool
     */
    public function isTestForOperationCost()
    {
        // по услуге
        if (YII_ENV_TEST) {
            if (in_array($this->id, [
                AccountTariff::DELTA + 10
            ])) {
                return true;
            }
        }

        return true;
        /*
        // по клиенту
        if (in_array($this->client_account_id, [
            57863, 63885, 59028, 60431
        ])) {
            return true;
        }

        return false;
        */
    }
}
