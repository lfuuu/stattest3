<?php

namespace app\models;

use app\classes\model\ActiveRecord;

/**
 * Class ClientAccountOptions
 * @package app\models
 * @property int $client_account_id
 * @property string $option
 * @property string $value
 */
class ClientAccountOptions extends ActiveRecord
{

    const OPTION_MAIL_DELIVERY = 'mail_delivery_variant';
    const OPTION_MAIL_DELIVERY_DEFAULT_VALUE = 'undefined';
    const OPTION_MAIL_DELIVERY_LANGUAGE = 'mail_delivery_language';
    const OPTION_MAIL_DELIVERY__PAYMENT = 'payment';

    const OPTION_VOIP_CREDIT_LIMIT_DAY_WHEN = 'voip_credit_limit_day_when';
    const OPTION_VOIP_CREDIT_LIMIT_DAY_VALUE = 'voip_credit_limit_day_value';

    const OPTION_VOIP_CREDIT_LIMIT_DAY_MN_WHEN = 'voip_credit_limit_day_mn_when';
    const OPTION_VOIP_CREDIT_LIMIT_DAY_MN_VALUE = 'voip_credit_limit_day_mn_value';

    const OPTION_SETTINGS_ADVANCE_INVOICE = 'settings_advance_invoice';
    const SETTINGS_ADVANCE_NOT_SET = '';
    const SETTINGS_ADVANCE_1_AND_15 = '1_and_15';
    const SETTINGS_ADVANCE_EVERY_WEEK_ON_MONDAY = 'every_week_on_monday';

    const OPTION_ORIGINAL_SUPER_ID = 'original_super_id';
    const OPTION_ORIGINAL_CONTRACT_ID = 'original_contract_id';

    const OPTION_UPLOAD_TO_SALES_BOOK = 'upload_to_sales_book'; // default = 1
    const OPTION_TRUST_LEVEL = 'trust_level_id';

    const OPTION_SBIS_DOC_BASE = 'sbis_document_base';
    const OPTION_SBIS_DOC_BASE_BILL = 'bill'; // default
    const OPTION_SBIS_DOC_BASE_CONTRACT = 'contract';

    const OPTION_PAYMENT_SALDO_DATE = 'payment_saldo_date';

    public static $settingsAdvance = [
        self::SETTINGS_ADVANCE_NOT_SET => 'Не выставляются автоматически',
        self::SETTINGS_ADVANCE_1_AND_15 => 'Выставляются каждого 1 и 15 числа',
        self::SETTINGS_ADVANCE_EVERY_WEEK_ON_MONDAY => 'Выставлять по понедельникам',
    ];

    private static $_defaults = [
        self::OPTION_UPLOAD_TO_SALES_BOOK => "1", // там только строки
        self::OPTION_TRUST_LEVEL => "0", // 0 - Не установлен
        self::OPTION_SBIS_DOC_BASE => self::OPTION_SBIS_DOC_BASE_BILL,
    ];

    public static $sbisDocumentBaseList = [
        self::OPTION_SBIS_DOC_BASE_BILL => 'Счет',
        self::OPTION_SBIS_DOC_BASE_CONTRACT => 'Договор',
    ];

    public static $infoOptions = [
        self::OPTION_VOIP_CREDIT_LIMIT_DAY_WHEN,
        self::OPTION_VOIP_CREDIT_LIMIT_DAY_VALUE,
        self::OPTION_VOIP_CREDIT_LIMIT_DAY_MN_WHEN,
        self::OPTION_VOIP_CREDIT_LIMIT_DAY_MN_VALUE,
    ];


    public static function tableName()
    {
        return 'client_account_options';
    }

    public function rules()
    {
        return [
            [['client_account_id',], 'integer'],
            [['option', 'value',], 'string'],
            [['client_account_id', 'option', 'value'], 'required'],
        ];
    }

    public function getParentId()
    {
        return $this->client_account_id;
    }

    /**
     * Значение по умолчанию для опции
     *
     * @param string $value
     * @return mixed|null
     */
    public static function getDefaultValue($value)
    {
        if (isset(self::$_defaults[$value])) {
            return self::$_defaults[$value];
        }

        return null;
    }

}
