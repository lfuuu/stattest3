<?php

namespace app\forms\client;

use app\classes\validators\PassportNumberUniqValidator;
use app\classes\validators\PassportValuesValidator;
use app\classes\validators\InnValidator;
use yii\base\Exception;
use app\classes\Form;
use app\classes\traits\DoubleAttributeLabelTrait;
use app\models\ClientContragent;
use app\models\ClientContragentPerson;
use app\models\Country;
use app\models\Language;

class ContragentEditForm extends Form
{
    use DoubleAttributeLabelTrait;

    /**
     * @return string
     */
    protected function getLangCategory()
    {
        return 'contragent';
    }

    public $id;
    public $super_id;

    /** @var ClientContragentPerson */
    public $person = null;

    /** @var ClientContragent */
    public $contragent = null;

    public $historyVersionRequestedDate = null;
    public $historyVersionStoredDate = null;

    public $legal_type,
        $name,
        $name_full,
        $address_jur,
        $address_registration_ip,
        $post_address_filial,
        $inn,
        $inn_euro,
        $kpp,
        $branch_code,
        $tax_registration_reason,
        $position,
        $fio,
        $is_take_signatory,
        $signatory_position,
        $signatory_fio,
        $tax_regime,
        $opf_id,
        $okpo,
        $okvd,
        $ogrn,
        $country_id,
        $lang_code = Language::LANGUAGE_DEFAULT,
        $signer_passport,
        $comment,
        $sale_channel_id,
        $contragent_id,
        $first_name,
        $last_name,
        $middle_name,
        $inn_person,
        $passport_date_issued,
        $passport_serial,
        $passport_number,
        $passport_issued,
        $registration_address,
        $mother_maiden_name,
        $birthplace,
        $birthday,
        $other_document;

    /**
     * @return array
     */
    public function rules()
    {
        $rules = [
            [['legal_type', 'super_id'], 'required'],
            [
                [
                    'name',
                    'name_full',
                    'address_jur',
                    'address_registration_ip',
                    'post_address_filial',
                    'inn',
                    'inn_euro',
                    'kpp',
                    'branch_code',
                    'tax_registration_reason',
                    'position',
                    'fio',
                    'signatory_position',
                    'signatory_fio',
                    'okpo',
                    'okvd',
                    'ogrn',
                    'signer_passport',
                    'comment',
                    'tax_regime',
                ],
                'string'
            ],
            [
                [
                    'name',
                    'name_full',
                    'address_jur',
                    'address_registration_ip',
                    'post_address_filial',
                    'inn',
                    'inn_euro',
                    'kpp',
                    'branch_code',
                    'tax_registration_reason',
                    'position',
                    'fio',
                    'signatory_position',
                    'signatory_fio',
                    'okpo',
                    'okvd',
                    'ogrn',
                    'signer_passport',
                    'comment',
                ],
                'default',
                'value' => ''
            ],
            [
                [
                    'name',
                    'name_full',
                    'address_jur',
                    'address_registration_ip',
                    'post_address_filial',
                    'inn',
                    'inn_euro',
                    'kpp',
                    'branch_code',
                    'tax_registration_reason',
                    'position',
                    'fio',
                    'signatory_position',
                    'signatory_fio',
                    'okpo',
                    'okvd',
                    'ogrn',
                    'signer_passport',
                    'comment'
                ],
                'trim'
            ],

            [
                [
                    'first_name',
                    'last_name',
                    'middle_name',
                    'inn_person',
                    'passport_date_issued',
                    'passport_serial',
                    'passport_number',
                    'passport_issued',
                    'registration_address',
                    'historyVersionStoredDate',
                    'mother_maiden_name',
                    'birthplace',
                    'birthday',
                    'other_document',
                ],
                'string'
            ],
            [
                [
                    'first_name',
                    'last_name',
                    'middle_name',
                    'inn_person',
                    'passport_serial',
                    'passport_number',
                    'passport_issued',
                    'registration_address',
                    'mother_maiden_name',
                    'birthplace',
                    'birthday',
                    'other_document',
                ],
                'default',
                'value' => ''
            ],
            ['passport_date_issued', 'default', 'value' => '1970-01-01'],
            [['opf_id', 'sale_channel_id'], 'default', 'value' => 0],
            [['tax_regime'], 'default', 'value' => ClientContragent::TAX_REGTIME_UNDEFINED],
            ['country_id', 'default', 'value' => Country::RUSSIA],
            ['lang_code', 'default', 'value' => Language::LANGUAGE_DEFAULT],

            [
                'legal_type',
                'in',
                'range' => [ClientContragent::IP_TYPE, ClientContragent::PERSON_TYPE, ClientContragent::LEGAL_TYPE]
            ],
            [['super_id', 'country_id', 'opf_id', 'sale_channel_id', 'is_take_signatory'], 'integer'],
            ['lang_code', 'string'],
            [['inn_person'], InnValidator::class],
            [['passport_serial', 'passport_number'], PassportValuesValidator::class],
            [['passport_serial', 'passport_number'], PassportNumberUniqValidator::class],
            [['branch_code'], 'string', 'max' => 8],
            [['branch_code'], 'default', 'value' => null],
            [['is_take_signatory'], 'default', 'value' => 0],
        ];

        // Валидация КПП, состоящая из 9 цифр, для контрагентов юр.лиц. со страной Россия
        if ($this->contragent->country->code == Country::RUSSIA && $this->contragent->legal_type == ClientContragent::LEGAL_TYPE) {
            $rules[] = [['kpp'], 'match', 'pattern' => '/^[0-9]{9}$/', 'message' => 'КПП должен содержать 9 цифр.'];
        }

        return $rules;
    }

    /**
     * @throws Exception
     */
    public function init()
    {
        if ($this->id) {
            $this->contragent = ClientContragent::findOne($this->id);
            if ($this->contragent && $historyDate = $this->historyVersionRequestedDate) {
                $this->contragent->loadVersionOnDate($historyDate);
            }

            if ($this->contragent === null) {
                throw new Exception('Contragent not found');
            }

            $this->person = ClientContragentPerson::findOne(['contragent_id' => $this->contragent->id]);
            if ($this->person) {
                if ($historyDate = $this->historyVersionRequestedDate) {
                    $this->person->loadVersionOnDate($historyDate);
                }
            } else {
                $this->person = new ClientContragentPerson();
            }

            $this->setAttributes($this->contragent->getAttributes() + $this->person->getAttributes(), false);
            $this->inn_person = $this->person->inn;
        } else {
            $this->contragent = new ClientContragent();
            $this->person = new ClientContragentPerson();
        }
    }

    /**
     * @return bool
     */
    public function save()
    {
        $this->_fillContragentNameByLegalType();
        $this->_fillContragent();
        if ($this->contragent && $this->historyVersionStoredDate) {
            $this->contragent->setHistoryVersionStoredDate($this->historyVersionStoredDate);
        }

        $contragent = $this->contragent;
        if ($contragent->save()) {
            $this->setAttributes($contragent->getAttributes(), false);
            if ($contragent->legal_type == 'ip' || $contragent->legal_type == 'person') {
                $this->_fillPerson();
                if ($this->historyVersionStoredDate) {
                    $this->person->setHistoryVersionStoredDate($this->historyVersionStoredDate);
                }

                $person = $this->person;
                if (!$person->contragent_id) {
                    $person->contragent_id = $contragent->id;
                }

                if ($person->save()) {
                    $contragent->refresh();
                    return true;
                } else {
                    $this->addErrors($person->getErrors());
                    $contragent->delete();
                }
            }

            return true;
        } else {
            $this->addErrors($contragent->getErrors());
        }

        return false;
    }

    /**
     * @param string[] $attributeNames
     * @param bool $clearErrors
     * @return bool
     */
    public function validate($attributeNames = null, $clearErrors = false)
    {
        $this->_fillContragent();
        $contragent = $this->contragent;
        $contragent->validate() || $this->addErrors($contragent->getErrors());

        if ($contragent->legal_type == ClientContragent::IP_TYPE || $contragent->legal_type == ClientContragent::PERSON_TYPE) {
            $this->_fillPerson();
            $person = $this->person;
            $person->validate() || $person->getErrors();
        }

        return parent::validate($attributeNames, $clearErrors);
    }

    /**
     * @return bool
     */
    public function getIsNewRecord()
    {
        return !$this->id;
    }

    /**
     * @return bool
     */
    public function getPersonId()
    {
        if ($this->person) {
            return $this->person->id;
        }

        return false;
    }

    /**
     * @return ClientContragent
     */
    public function getContragentModel()
    {
        return $this->contragent;
    }

    /**
     * @inheritdoc
     */
    private function _fillContragentNameByLegalType()
    {
        switch ($this->legal_type) {
            case ClientContragent::LEGAL_TYPE:
                if (empty($this->name) && !empty($this->name_full)) {
                    $this->name = $this->name_full;
                } elseif (empty($this->name_full) && !empty($this->name)) {
                    $this->name_full = $this->name;
                }
                break;
            case ClientContragent::IP_TYPE:
                $name = $this->last_name . " " . $this->first_name . ($this->country_id == Country::RUSSIA && $this->middle_name ? " " . $this->middle_name : '');
                $this->name = 'ИП ' . $name;
                $this->name_full = 'ИП ' . $name;
                break;
            case ClientContragent::PERSON_TYPE:
                $name = $this->last_name . " " . $this->first_name . ($this->country_id == Country::RUSSIA && $this->middle_name ? " " . $this->middle_name : '');
                $this->name = $this->name_full = $name;
                break;
        }
    }

    /**
     * @inheritdoc
     */
    private function _fillContragent()
    {
        /** @var ClientContragent $contragent */
        $contragent = &$this->contragent;
        $contragent->super_id = $this->super_id;
        $contragent->legal_type = $this->legal_type;
        $contragent->name = $this->name;
        $contragent->name_full = $this->name_full;
        $contragent->address_jur = $this->address_jur;
        $contragent->address_registration_ip = $this->address_registration_ip;
        $contragent->post_address_filial = $this->post_address_filial;
        $contragent->inn = $this->inn;
        $contragent->inn_euro = $this->inn_euro;
        $contragent->kpp = $this->kpp;
        $contragent->branch_code = $this->branch_code;
        $contragent->tax_registration_reason = $this->tax_registration_reason;
        $contragent->position = $this->position;
        $contragent->fio = $this->fio;
        $contragent->is_take_signatory = $this->is_take_signatory;
        $contragent->signatory_position = $this->signatory_position;
        $contragent->signatory_fio = $this->signatory_fio;
        $contragent->comment = $this->comment;

        if ($contragent->legal_type == 'person') {
            $contragent->tax_regime = ClientContragent::TAX_REGTIME_UNDEFINED;
        } else {
            $contragent->tax_regime = $this->tax_regime;
        }

        $contragent->opf_id = $this->opf_id;
        $contragent->okpo = $this->okpo;
        $contragent->okvd = $this->okvd;
        $contragent->ogrn = $this->ogrn;
        $contragent->country_id = $this->country_id;
        $contragent->lang_code = $this->lang_code;
        $contragent->sale_channel_id = $this->sale_channel_id;
    }

    /**
     * @inheritdoc
     */
    private function _fillPerson()
    {
        /** @var ClientContragentPerson $person */
        $person = &$this->person;
        $person->first_name = $this->first_name;
        $person->last_name = $this->last_name;
        $person->middle_name = $this->middle_name;
        $person->inn = $this->inn_person;
        $person->passport_date_issued = $this->passport_date_issued;
        $person->passport_serial = $this->passport_serial;
        $person->passport_number = $this->passport_number;
        $person->passport_issued = $this->passport_issued;
        $person->registration_address = $this->registration_address;
        $person->birthplace = $this->birthplace;
        $person->birthday = $this->birthday;
        $person->mother_maiden_name = $this->mother_maiden_name;
    }

}
