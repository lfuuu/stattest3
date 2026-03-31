<?php

namespace app\forms\client;

use app\classes\api\ApiCore;
use app\classes\Assert;
use app\classes\dto\ChangeClientStructureRegistratorDto;
use app\classes\Form;
use app\classes\Html;
use app\classes\traits\GetListTrait;
use app\classes\validators\ArrayValidator;
use app\classes\validators\BikValidator;
use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\Bik;
use app\models\ClientAccount;
use app\models\ClientAccountOptions;
use app\models\ClientContact;
use app\models\ClientContract;
use app\models\ClientContragent;
use app\models\Currency;
use app\models\EntryPoint;
use app\models\GoodPriceType;
use app\models\Region;
use app\models\Timezone;
use app\modules\sbisTenzor\classes\ContractorInfo;
use app\modules\sbisTenzor\classes\SBISExchangeStatus;
use app\modules\sbisTenzor\helpers\SBISDataProvider;
use http\Client;
use Yii;
use yii\base\Exception;
use yii\helpers\ArrayHelper;

/**
 * Class AccountEditForm
 */
class AccountEditForm extends Form
{
    /** @var ClientAccount */
    public $clientM = null;

    public $historyVersionRequestedDate = null;
    public $historyVersionStoredDate = null; // сохранение на дату из dropdown'а
    public $historyVersionStoredDateSelected = null; // сохранение на выбранную дату

    public $id,
        $super_id,
        $contract_id;

    public
        $client,
        $region = ClientAccount::DEFAULT_REGION,
        $status,
        $address_post,
        $address_post_real,
        $address_connect,
        $currency,
        $stamp,
        $nal,
        $credit = ClientAccount::DEFAULT_CREDIT,
        $phone_connect,
        $form_type,
        $price_type = GoodPriceType::DEFAULT_PRICE_LIST,
        $voip_disabled,
        $voip_credit_limit_day = ClientAccount::DEFAULT_VOIP_CREDIT_LIMIT_DAY,
        $voip_is_day_calc = ClientAccount::DEFAULT_VOIP_IS_DAY_CALC,
        $voip_limit_mn_day = ClientAccount::DEFAULT_VOIP_MN_LIMIT_DAY,
        $voip_is_mn_day_calc = ClientAccount::DEFAULT_VOIP_IS_MN_DAY_CALC,
        $mail_who,
        $head_company,
        $head_company_address_jur,
        $bill_rename1 = 'no',
        $is_agent,
        $is_with_consignee,
        $consignee,
        $is_upd_without_sign,
        $timezone_name = DateTimeZoneHelper::TIMEZONE_MOSCOW,
        $is_active,
        $admin_contact_id = 0,
        $admin_is_active = 0,
        $bik,
        $corr_acc,
        $pay_acc,
        $bank_name,
        $custom_properties,
        $bank_properties,
        $bank_city,
        $admin_email,
        $lk_balance_view_mode,
        $anti_fraud_disabled,
        $options,
        $site_name,
        $account_version,
        $is_postpaid,
        $type_of_bill = ClientAccount::TYPE_OF_BILL_DETAILED,
        $effective_vat_rate = 0,
        $pay_bill_until_days = ClientAccount::PAY_BILL_UNTIL_DAYS,
        $price_level = ClientAccount::DEFAULT_PRICE_LEVEL,
        $uu_tariff_status_id,
        $settings_advance_invoice,
        $upload_to_sales_book,
        $show_in_lk = ClientAccount::SHOW_IN_LK_ALWAYS,
        $exchange_group_id,
        $exchange_status = SBISExchangeStatus::UNKNOWN,
        $transfer_params_from = 0,
        $transfer_contract_id = 0,
        $contractor_exchange_id = '';

    protected $contractorInfo;

    /**
     * Правила
     *
     * @return array
     */
    public function rules()
    {
        $rules = [
            [
                [
                    'client',
                    'address_post',
                    'address_post_real',
                    'address_connect',
                    'phone_connect',
                    'mail_who',
                    'head_company',
                    'head_company_address_jur',
                    'consignee',
                    'bik',
                    'corr_acc',
                    'pay_acc',
                    'bank_name',
                    'bank_city',
                    'bank_properties',
                    'contractor_exchange_id',
                    'historyVersionStoredDate',
                    'historyVersionStoredDateSelected'
                ],
                'string'
            ],
            [
                [
                    'client',
                    'address_post',
                    'address_post_real',
                    'address_connect',
                    'phone_connect',
                    'mail_who',
                    'head_company',
                    'head_company_address_jur',
                    'consignee',
                    'site_name',
                    'bik',
                    'corr_acc',
                    'pay_acc',
                    'bank_name',
                    'bank_city',
                    'bank_properties',
                    'admin_email'

                ],
                'default',
                'value' => ''
            ],
            [
                [
                    'id',
                    'super_id',
                    'contract_id',
                    'stamp',
                    'credit',
                    'voip_disabled',
                    'voip_credit_limit_day',
                    'voip_is_day_calc',
                    'voip_limit_mn_day',
                    'voip_is_mn_day_calc',
                    'is_with_consignee',
                    'is_upd_without_sign',
                    'is_agent',
                    'admin_contact_id',
                    'admin_is_active',
                    'anti_fraud_disabled',
                    'account_version',
                    'is_postpaid',
                    'type_of_bill',
                    'price_level',
                    'uu_tariff_status_id',
                    'exchange_group_id',
                    'exchange_status',
                    'settings_advance_invoice',
                    'upload_to_sales_book',
                    'show_in_lk',
                    'transfer_params_from',
                    'transfer_contract_id',
                ],
                'integer'
            ],
            [
                [
                    'stamp',
                    'credit',
                    'is_agent',
                    'voip_disabled',
                    'is_with_consignee',
                    'is_upd_without_sign',
                ],
                'default',
                'value' => 0
            ],
            [['voip_credit_limit_day'], 'default', 'value' => ClientAccount::DEFAULT_VOIP_CREDIT_LIMIT_DAY],
            [['voip_limit_mn_day'], 'default', 'value' => ClientAccount::DEFAULT_VOIP_MN_LIMIT_DAY],
            ['admin_email', 'email'],
            ['admin_email', 'checkEmailInCore'],
            ['credit', 'integer', 'min' => 0],
            ['voip_is_day_calc', 'default', 'value' => ClientAccount::DEFAULT_VOIP_IS_DAY_CALC],
            ['voip_is_mn_day_calc', 'default', 'value' => ClientAccount::DEFAULT_VOIP_IS_MN_DAY_CALC],
            ['currency', 'in', 'range' => array_keys(Currency::map())],
            ['form_type', 'in', 'range' => array_keys(ClientAccount::$formTypes)],
            ['region', 'in', 'range' => array_keys(Region::getList())],
            ['price_type', 'in', 'range' => array_keys(GoodPriceType::getList())],
            ['timezone_name', 'in', 'range' => array_keys(Timezone::getList())],
            ['status', 'in', 'range' => array_keys(ClientAccount::$statuses)],
            ['nal', 'in', 'range' => array_keys(ClientAccount::$nalTypes)],
            ['bill_rename1', 'in', 'range' => ['no', 'yes']],
            ['status', 'default', 'value' => ClientAccount::STATUS_INCOME],
            ['bik', BikValidator::class],
            ['lk_balance_view_mode', 'in', 'range' => array_keys(ClientAccount::$balanceViewMode)],
            [
                'options',
                'default',
                'value' => [ClientAccountOptions::OPTION_MAIL_DELIVERY => ClientAccountOptions::OPTION_MAIL_DELIVERY_DEFAULT_VALUE]
            ],
            [['options',], ArrayValidator::class],
            ['is_postpaid', 'in', 'range' => array_keys(ClientAccount::$paymentTypes)],
            ['account_version', 'default', 'value' => ClientAccount::VERSION_BILLER_UNIVERSAL],
            ['type_of_bill', 'default', 'value' => ClientAccount::TYPE_OF_BILL_DETAILED],
            ['pay_bill_until_days', 'integer', 'min' => 20, 'max' => 1000],
            ['settings_advance_invoice', 'default', 'value' => ClientAccountOptions::SETTINGS_ADVANCE_NOT_SET],
            [ClientAccountOptions::OPTION_UPLOAD_TO_SALES_BOOK, 'default', 'value' => ClientAccountOptions::getDefaultValue(ClientAccountOptions::OPTION_UPLOAD_TO_SALES_BOOK)],
            ['show_in_lk', 'default', 'value' => ClientAccount::SHOW_IN_LK_ALWAYS],
        ];
        return $rules;
    }

    /**
     * Название полей
     *
     * @return array
     */
    public function attributeLabels()
    {
        return ArrayHelper::merge(
            (new ClientAccount())->attributeLabels(),
            [
                'admin_email' => 'Email администратора',
                'transfer_params_from' => 'Перенести Реквизиты и Контакты с ЛС',
                'transfer_contract_id' => 'Перенести ЛС на договор',
                'trust_level_id' => 'Уровень доверия',

                'contractor_exchange_id' => 'Оператор ЭДО',
            ]
        );
    }

    /**
     * Получить модель
     *
     * @return ClientAccount
     */
    public function getModel()
    {
        return $this->clientM;
    }

    /**
     * Инициализация данных после загрузки
     *
     * @return bool
     */
    public function beforeValidate()
    {
        if (!$this->historyVersionStoredDate && $this->historyVersionStoredDateSelected) {
            $this->historyVersionStoredDate = $this->historyVersionStoredDateSelected;
        }

        return true;
    }

    /**
     * @inheritdoc
     * @throws Exception
     */
    public function init()
    {
        $this->currency = \Yii::$app->isRus() ? Currency::RUB : Currency::HUF;
        
        if ($this->id) {
            $this->clientM = ClientAccount::findOne($this->id);

            if ($this->clientM && $historyDate = $this->historyVersionRequestedDate) {
                $this->clientM->loadVersionOnDate($historyDate);
            }

            if ($this->clientM === null) {
                throw new Exception('Contract not found');
            }

            $this->setAttributes($this->clientM->getAttributes(), false);
        } elseif ($this->contract_id) {
            $contract = ClientContract::findOne($this->contract_id);
            $contragent = ClientContragent::findOne($contract->contragent_id);

            if (!$this->super_id) {
                $this->super_id = $contract->super_id;
            }

            $this->clientM = new ClientAccount();
            $this->clientM->contract_id = $this->contract_id;
            $this->clientM->super_id = $this->super_id;
            $this->clientM->country_id = $contragent->country_id;
            $this->clientM->currency = Currency::defaultCurrencyByCountryId($contragent->country_id);

            $this->setAttributes(
                array_filter(
                    $this->clientM->getAttributes(),
                    function ($value) {
                        return !is_null($value);
                    }
                ),
                false
            );

            $this->admin_contact_id = 0;
            $this->admin_is_active = 0;
            $this->voip_credit_limit_day = ClientAccount::DEFAULT_VOIP_CREDIT_LIMIT_DAY;
            $this->voip_is_day_calc = ClientAccount::DEFAULT_VOIP_IS_DAY_CALC;
            $this->voip_limit_mn_day = ClientAccount::DEFAULT_VOIP_MN_LIMIT_DAY;
            $this->voip_is_mn_day_calc = ClientAccount::DEFAULT_VOIP_IS_MN_DAY_CALC;
            $this->anti_fraud_disabled = 0;
            $this->is_postpaid = 0;
            $this->bill_rename1 = 'no';
        } else {
            $this->clientM = new ClientAccount();
        }

        $this->is_agent = ($this->is_agent == 'Y') ? 1 : 0;
        $this->effective_vat_rate .= "%";

        $options = [];
        foreach ($this->clientM->options as $element) {
            $options[$element->option]
                = !isset($options[$element->option]) ?
                $element->value :
                array_merge((array)$options[$element->option], (array)$element->value);
        }

        $this->options = ArrayHelper::merge($this->options, $options);
        $this->{ClientAccountOptions::OPTION_UPLOAD_TO_SALES_BOOK} =
            isset($this->options[ClientAccountOptions::OPTION_UPLOAD_TO_SALES_BOOK]) ? $this->options[ClientAccountOptions::OPTION_UPLOAD_TO_SALES_BOOK] : 1;

        if (!$this->clientM->isNewRecord) {
            $sbisContractor = SBISDataProvider::getSBISContractor($this->clientM);
            if ($sbisContractor) {
                $this->contractor_exchange_id = $sbisContractor->exchange_id;
            }
        }
    }

    /**
     * Проверка существтвания email'а на платформе
     *
     * @param string $attribute
     */
    public function checkEmailInCore($attribute)
    {
        if (ApiCore::isEmailExists($this->admin_email)) {
            $this->addError($attribute, 'E-mail уже присутствует на платформе как администратор');
        }
    }

    /**
     * Сохранение формы
     *
     * @return bool
     * @throws \Exception
     */
    public function save()
    {
        $transaction = Yii::$app->db->beginTransaction();

        try {

            if ($this->transfer_contract_id) {
                $this->_transferToContractId($this->transfer_contract_id);
            }

            if ($this->transfer_params_from) {
                $this->_saveFromAccount();
            } else {
                $this->_saveFromPost();
            }

            if ($this->contractor_exchange_id) {
                $this->_saveSbisContractorExchangeId();
            }

            $transaction->commit();
            ChangeClientStructureRegistratorDto::me()->registrChange(ChangeClientStructureRegistratorDto::ACCOUNT, $this->clientM->id); // any save account from interface => announce struct
            return true;
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * @return bool
     */
    public function getIsNewRecord()
    {
        return $this->id ? false : true;
    }

    /**
     * @return array
     */
    public function getCurrencyTypes()
    {
        return Currency::map();
    }

    /**
     * Показываем рядом стоящие аккаунты
     *
     * @return string[]
     */
    public function getNearAccounts()
    {
        $query = ClientAccount::find()
            ->select(['id'])
            ->where(['contract_id' => $this->contract_id])
            ->indexBy('id')
            ->orderBy(['id' => SORT_ASC]);

        $this->id && $query->andWhere(['NOT', ['id' => $this->id]]);

        return GetListTrait::getEmptyList(true) + $query->column();
    }

    public function getNearContracts()
    {
        if ($this->contract_id == ClientContract::ID_DANYCOM_TRASH) {
            $data = [];
            $option = ClientAccountOptions::findOne(['client_account_id' => $this->id, 'option' => ClientAccountOptions::OPTION_ORIGINAL_CONTRACT_ID]);
            if ($option) {
                $data = [$option->value => $option->value . ' (восставновить)'];
            }

            return GetListTrait::getEmptyList(true) + $data;
        }
        $query = ClientContract::find()
            ->select(['id'])
            ->where(['super_id' => $this->super_id])
            ->indexBy('id')
            ->orderBy(['id' => SORT_ASC]);

        $this->id && $query->andWhere(['NOT', ['id' => $this->contract_id]]);
        return GetListTrait::getEmptyList(true) + $query->column() + [ClientContract::ID_DANYCOM_TRASH => 'Удалить'];
    }

    /**
     * Получить информацию по ЭДО для контрагента
     *
     * @return ContractorInfo|null
     * @throws Exception
     */
    public function getContractorInfo() {
        if ($this->getIsNewRecord()) {
            throw new Exception('Client is not created!');
        }

        if (is_null($this->contractorInfo)) {
            $this->contractorInfo = ContractorInfo::get($this->getModel(), null, true);
        }

        return $this->contractorInfo;
    }

    /**
     * Получить ошибку интеграции с ЭДО
     *
     * @return string
     * @throws Exception
     */
    public function getExchangeGroupError()
    {
        if ($this->getIsNewRecord()) {
            return 'Интерацию со СБИС можно настроить через редактирование только после создания клиента ';
        }

        return $this->getContractorInfo()->getErrorText();
    }

    /**
     * Получить информацию об операторе ЭДО
     *
     * @return string
     * @throws Exception
     */
    public function getEdfOperatorHtml()
    {
        $result = '';

        $contractorInfo = $this->getContractorInfo();

        $operator = $contractorInfo->getOperator();
        if ($operator && $operator->isExternal()) {
            $roaming =
                $contractorInfo->isRoamingEnabled() ?
                    Html::tag(
                        'span',
                        '<i class="glyphicon glyphicon-ok"></i> роуминг включён',
                        ['class' => 'text-success']
                    ) :
                    Html::tag(
                        'span',
                        '<i class="glyphicon glyphicon-remove"></i> без роуминга',
                        ['class' => 'text-danger']
                    );

            $name = $contractorInfo->getOperator()->getName();
            if ($url = $contractorInfo->getOperator()->getUrl()) {
                $name = Html::tag('a',
                    $contractorInfo->getOperator()->getName(),
                    [
                        'href' => $url,
                        'target' => '_blank',
                    ]
                );
            }

            $result = Html::tag(
                'i',
                sprintf('Клиент в системе %s (%s)', $name, $roaming)
            );
        }

        return $result;
    }

    private function _saveFromPost()
    {
        $client = $this->clientM;

        if ($this->getIsNewRecord()) {
            $this->is_active = 0;
        }

        if ($this->credit < 0) {
            $this->credit = 0;
        }

        if ($this->site_name) {
            $client->site_name = $this->site_name;
        }

        $this->is_agent = $this->is_agent ? 'Y' : 'N';

        $exceptFields = ['historyVersionRequestedDate', 'id'];
        if (!\Yii::$app->user->can('clients.new')) {
            $exceptFields[] = 'credit';
            $exceptFields[] = 'voip_credit_limit_day';
            $exceptFields[] = 'voip_limit_mn_day';
        }

        $client->setAttributes($this->getAttributes(null, $exceptFields), false);
        if ($client && $this->historyVersionStoredDate) {
            $client->setHistoryVersionStoredDate($this->historyVersionStoredDate);
        }

        $contract = ClientContract::findOne($client->contract_id);
        $contragent = ClientContragent::findOne($contract->contragent_id);
        $client->country_id = $contragent->country_id;

        if (!$this->custom_properties) {
            if (
                !empty($this->bik) &&
                (empty($client->corr_acc) || empty($client->bank_name) || empty($client->bank_city))
            ) {
                $bik = Bik::findOne(['bik' => $this->bik]);

                if ($bik) {
                    $client->bik = $bik->bik;
                    $client->corr_acc = $bik->corr_acc;
                    $client->bank_name = $bik->bank_name;
                    $client->bank_city = $bik->bank_city;

                    $client->bank_properties = 'р/с ' . ($client->pay_acc ?: '') . "\n" .
                        $client->bank_name . ' ' . $client->bank_city .
                        ($client->corr_acc ? "\nк/с " . $client->corr_acc : '');
                }
            }
        }

        if (!$client->save()) {
            throw new ModelValidationException($client);
        }

        if (!$client->client) {
            $client->client = 'id' . $client->id;
            if (!$client->save()) {
                throw new ModelValidationException($client);
            }

        }

        if ($this->admin_email) {
            $contact = new ClientContact(["client_id" => $client->id]);
            $contact->addEmail($this->admin_email);
            $contact->is_official = 1;

            if (!$contact->save()) {
                throw new ModelValidationException($contact);
            }

            $client->admin_contact_id = $contact->id;
            if (!$client->save()) {
                throw new ModelValidationException($client);
            }
        }

        $this->setAttributes($client->getAttributes(), false);

        if (is_array($this->options)) {

            if (\Yii::$app->user->can('newaccounts_payments.delete')) {
                $this->options[ClientAccountOptions::OPTION_UPLOAD_TO_SALES_BOOK] = (string)(int)$this->{ClientAccountOptions::OPTION_UPLOAD_TO_SALES_BOOK};
            }

            $this->_saveOptions();

        }
    }

    /**
     * @throws ModelValidationException
     */
    private function _saveSbisContractorExchangeId()
    {
        $account = $this->clientM;
        $sbisContractor = SBISDataProvider::getSBISContractor($account);

        if (!$sbisContractor) {
            return;
        }

        if ($sbisContractor->exchange_id != $this->contractor_exchange_id) {
            $sbisContractor->exchange_id = $this->contractor_exchange_id;

            if (!$sbisContractor->save()) {
                throw new ModelValidationException($sbisContractor);
            }
        }
    }

    private function _saveFromAccount()
    {
        $client = $this->clientM;

        $fromAccount = ClientAccount::findOne(['id' => $this->transfer_params_from]);

        Assert::isObject($fromAccount);

        $client->setAttributes($fromAccount->getAttributes(null, ['id', 'client', 'created', 'is_active', 'account_version', 'balance', 'last_account_date', 'last_payed_voip_month', 'admin_contact_id', 'admin_is_active', 'is_blocked', 'is_closed', 'show_in_lk']), false);

        if ($this->getIsNewRecord()) {
            $client->is_active = 0;
        }


        if (!$client->save()) {
            throw new ModelValidationException($client);
        }

        if (!$client->client) {
            $client->client = 'id' . $client->id;
            if (!$client->save()) {
                throw new ModelValidationException($client);
            }
        }

        $this->setAttributes($client->getAttributes(), false);

        $optionsSaveForm = new ClientAccountOptionsSaveForm();

        /** @var ClientAccountOptions $option */
        foreach ($fromAccount->options as $option)
        {
            if (in_array($option->option, ClientAccountOptions::$infoOptions)) {
                continue;
            }

            $optionsSaveForm->addOptionForm(
                (new ClientAccountOptionsForm)
                ->setClientAccountId($client->id)
                ->setOption($option->option)
                ->setValue($option->value)
            );
        }

        $optionsSaveForm->save();

        ClientContact::deleteAll(['client_id' => $client->id]);

        foreach ($fromAccount->contacts as $contact) {
            $newContact = new ClientContact();
            $newContact->setAttributes($contact->getAttributes(null, ['client_id', 'ts', 'user_id']));
            $newContact->client_id = $client->id;

            if (!$newContact->save()) {
                throw new ModelValidationException($newContact);
            }
        }

    }

    public function _saveOptions()
    {
        $optionsSaveForm = new ClientAccountOptionsSaveForm();

        foreach ($this->options as $option => $values) {

            if (!is_array($values)) {
                $values = [$values];
            }

            foreach ($values as $value) {
                if ($value === '') {
                    continue;
                }

                $optionsSaveForm->addOptionForm(
                    (new ClientAccountOptionsForm)
                    ->setClientAccountId($this->clientM->id)
                    ->setOption($option)
                    ->setValue($value)
                );
            }
        }

        $optionsSaveForm->save();
    }

    private function _transferToContractId($toContractId)
    {
        $account = ClientAccount::findOne(['id' => $this->id]);
        Assert::isObject($account);

        $contract = ClientContract::findOne(['id' => $toContractId]);
        Assert::isObject($contract);

        if ($account->super_id != $contract->super_id) {

            if ($account->contract_id == ClientContract::ID_DANYCOM_TRASH) {
                $option = ClientAccountOptions::findOne([
                    'client_account_id' => $account->id,
                    'option' => ClientAccountOptions::OPTION_ORIGINAL_SUPER_ID
                ]);
                if ($option) {
                    $account->super_id = $option->value;
                }
            } else {
                $option = ClientAccountOptions::findOne([
                    'client_account_id' => $account->id,
                    'option' => ClientAccountOptions::OPTION_ORIGINAL_SUPER_ID
                ]);
                if (!$option) {
                    $option = new ClientAccountOptions();
                    $option->client_account_id = $account->id;
                    $option->option = ClientAccountOptions::OPTION_ORIGINAL_SUPER_ID;
                }

                $option->value = (string)$account->super_id;
                if (!$option->save()) {
                    throw new ModelValidationException($option);
                }

                $option = ClientAccountOptions::findOne([
                    'client_account_id' => $account->id,
                    'option' => ClientAccountOptions::OPTION_ORIGINAL_CONTRACT_ID
                ]);
                if (!$option) {
                    $option = new ClientAccountOptions();
                    $option->client_account_id = $account->id;
                    $option->option = ClientAccountOptions::OPTION_ORIGINAL_CONTRACT_ID;
                }

                $option->value = (string)$account->contract_id;
                if (!$option->save()) {
                    throw new ModelValidationException($option);
                }

                $account->super_id = $contract->super_id;
            }
        }

        $account->contract_id = $toContractId;

        if (!$account->save()) {
            throw new ModelValidationException($account);
        }
    }

    public function isShowTransferContract()
    {
        return $this->contract_id == ClientContract::ID_DANYCOM_TRASH
        || ($this->clientM->superClient && $this->clientM->superClient->entry_point_id == EntryPoint::ID_TRANSFER_ALLOWED_LC);
    }
}
