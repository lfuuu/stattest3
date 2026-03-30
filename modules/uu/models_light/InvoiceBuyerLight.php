<?php

namespace app\modules\uu\models_light;

use app\models\ClientAccount;
use yii\base\Component;

class InvoiceBuyerLight extends Component implements InvoiceLightInterface
{

    public
        $name,
        $address,
        $tax_registration_id,
        $euro_tax_registration_id,
        $tax_registration_reason,
        $consignee,
        $currency,
        $currency_symbol,
        $registration_address,
        $post_nominative,
        $name_nominative
    ;

    /**
     * @param ClientAccount $clientAccount
     */
    public function __construct(ClientAccount $clientAccount)
    {
        parent::__construct();

        $contragent = $clientAccount->contragent;

        $this->name = ($clientAccount->head_company ?: $clientAccount->company_full);
        $this->address = ($clientAccount->head_company_address_jur ?: $clientAccount->address_jur);
        $this->tax_registration_id = $contragent->inn;
        $this->euro_tax_registration_id = $contragent->inn_euro;
        $this->tax_registration_reason = $contragent->kpp ?: $contragent->tax_registration_reason;
        $this->consignee = ($clientAccount->is_with_consignee && $clientAccount->consignee) ? $clientAccount->consignee : '';
        $this->currency = $clientAccount->currencyModel->name;
        $this->currency_symbol = $clientAccount->currencyModel->symbol;
        $this->registration_address = $contragent->person ? $contragent->person->registration_address : '';

        $this->post_nominative = $contragent->signer_position;
        $this->name_nominative = $contragent->signer_fio;
    }

    /**
     * @return string
     */
    public static function getKey()
    {
        return 'buyer';
    }

    /**
     * @return string
     */
    public static function getTitle()
    {
        return 'Компания заказчик';
    }

    /**
     * @return array
     */
    public static function attributeLabels()
    {
        return [
            'name' => 'Наименование',
            'address' => 'Адрес',
            'tax_registration_id' => 'ИНН',
            'euro_tax_registration_id' => 'ЕвроИНН',
            'tax_registration_reason' => 'КПП',
            'consignee' => 'Грузополучатель',
            'currency' => 'Наименование валюты',
            'currency_symbol' => 'Символ валюты',
            'registration_address' => 'Адрес регистрации',
            'post_nominative' => 'Позиция подписанта',
            'name_nominative' => 'ФИО подписанта',
        ];
    }

}