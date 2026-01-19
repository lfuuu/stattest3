<?php

namespace app\modules\uu\models_light;

use app\classes\Html;
use app\helpers\MediaFileHelper;
use app\models\ClientAccount;
use app\models\ClientContract;
use app\models\Organization;
use app\models\OrganizationSettlementAccount;
use yii\base\Component;

class InvoiceSellerLight extends Component implements InvoiceLightInterface
{

    public
        $name,
        $legal_address,
        $post_address,
        $country,
        $tax_registration_id,
        $tax_registration_reason,
        $contact_email,
        $contact_phone,
        $contact_fax,
        $director,
        $accountant,
        $bank,
        $stamp,
        $stamp_file_name,
        $logo_image = '';

    /**
     * @param string $language
     * @param Organization $organization
     * @param ClientAccount $clientAccount
     */
    public function __construct($language, Organization $organization, ClientAccount $clientAccount)
    {
        parent::__construct();

        $dao = ClientContract::dao();
        $dao->getEffectiveVATRate($clientAccount->contract);

        $this->name = $organization->name;
        $this->legal_address = $organization->legal_address;
        $this->post_address = $organization->post_address;
        $this->country = $organization->country->name;
        $this->tax_registration_id = $organization->tax_registration_id;
        $this->tax_registration_reason = $organization->tax_registration_reason;
        $this->contact_email = $organization->contact_email;
        $this->contact_phone = $organization->contact_phone;
        $this->contact_fax = $organization->contact_fax;
        $this->director = (array)(new InvoicePersonLight($organization->director->setLanguage($language)));
        $this->accountant = (array)(new InvoicePersonLight($organization->accountant->setLanguage($language)));
        $this->bank = (array)(new InvoiceBankLight(
            $dao->settlementAccountTypeId != OrganizationSettlementAccount::SETTLEMENT_ACCOUNT_TYPE_RUSSIA
                ? $organization->getSettlementAccount($dao->settlementAccountTypeId)
                : $organization->settlementAccount,
            $clientAccount->currency
        )
        );

        if (MediaFileHelper::checkExists('ORGANIZATION_LOGO_DIR', $organization->logo_file_name)) {
            $this->logo_image = Html::img(
                MediaFileHelper::getFile('ORGANIZATION_LOGO_DIR', $organization->logo_file_name),
                [
                    'width' => 180,
                    'border' => 0,
                ]
            );
        }

        $this->stamp = '';

        if (MediaFileHelper::checkExists('STAMP_DIR', $organization->stamp_file_name)) {
            $image_options = [
                'width' => 200,
                'border' => 0,
                'style' => ['position' => 'absolute', 'left' => '10px', 'top' => '-140px', 'z-index' => '-10'],
            ];

            //if ($inline_img) {
            //    echo Html::inlineImg(MediaFileHelper::getFile('STAMP_DIR', $organization->stamp_file_name), $image_options);
            //} else {

            $this->stamp_file_name = $organization->stamp_file_name;
            $this->stamp = Html::tag('div',
                Html::img(MediaFileHelper::getFile('STAMP_DIR', $organization->stamp_file_name), $image_options),
                ['style' => ['position' => 'relative', 'display' => 'block', 'width' => 0, 'height' => 0]]
            );
        }
    }


    /**
     * @return string
     */
    public static function getKey()
    {
        return 'seller';
    }

    /**
     * @return string
     */
    public static function getTitle()
    {
        return 'Компания поставщик';
    }

    /**
     * @return array
     */
    public static function attributeLabels()
    {
        return [
            'name' => 'Наименование',
            'legal_address' => 'Юридический адрес',
            'post_address' => 'Почтовый адрес',
            'country' => 'Название страны',
            'tax_registration_id' => 'ИНН',
            'tax_registration_reason' => 'КПП',
            'contact_email' => 'Контактный e-mail',
            'contact_phone' => 'Контактный номер телефона',
            'contact_fax' => 'Контактный номер факса',
            'director' => InvoicePersonLight::attributeLabels(),
            'accountant' => InvoicePersonLight::attributeLabels(),
            'bank' => InvoiceBankLight::attributeLabels(),
            'logo_image' => 'Логотип компании',
//            'include_signature_stamp' => 'Печать и подпись',
            'stamp' => 'печать организации',
        ];
    }

}
