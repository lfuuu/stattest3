<?php

namespace app\modules\sbisTenzor\classes;

use app\models\ClientAccount;
use app\models\ClientContragent;
use app\models\Organization;
use app\modules\sbisTenzor\helpers\SBISDataProvider;
use app\modules\sbisTenzor\helpers\SBISInfo;
use app\modules\sbisTenzor\models\SBISContractor;

class ContractorInfo
{
    /** @var ClientAccount */
    protected $client;
    /** @var Organization */
    protected $organization;
    /** @var bool */
    protected $isForce;

    /** @var string */
    protected $errorText = '';
    /** @var integer[] */
    protected $exchangeGroupIds = [];
    /** @var string */
    protected $legalType;
    /** @var string */
    protected $inn;
    /** @var string */
    protected $kpp;
    /** @var string */
    protected $edfId;
    /** @var bool */
    protected $isRoamingEnabled = false;
    /** @var EdfOperator */
    protected $operator;
    /** @var SBISContractor */
    public $contractor;

    /**
     * ContractorInfo constructor.
     *
     * @param ClientAccount $client
     * @param Organization|null $organization
     * @param bool $isForce
     */
    public function __construct(ClientAccount $client, Organization $organization = null, $isForce = false)
    {
        $this->client = $client;
        $this->organization = $organization;
        $this->isForce = $isForce;

        $this->errorText = $this->checkForError();
    }

    /**
     * @param ClientAccount $client
     * @param Organization|null $organization
     * @param bool $isForce
     * @return static
     */
    public static function get(ClientAccount $client, Organization $organization = null, $isForce = false)
    {
        return new static($client, $organization, $isForce);
    }

    /**
     * Проверка контрагента на ошибки интеграции с ЭДО СБИС
     *
     * @return string
     */
    protected function checkForError()
    {
        $client = $this->client;
        $organization = $this->organization;
        $isForce = $this->isForce;

        $sbisOrganization = SBISDataProvider::getSBISOrganizationByClient($client, $organization);
        if (!$sbisOrganization) {
            $organization = $organization ? : $client->organization;
            return sprintf('Обслуживающая организация %s не настроена для работы со СБИС', $organization->name);
        }

        $this->exchangeGroupIds = SBISInfo::getExchangeGroupsByClient($client, $sbisOrganization);
        if (empty($this->exchangeGroupIds)) {
            return sprintf('Для данного клиента нет подходящих документов для отправки в СБИС');
        }

        if ($result = $this->checkRequisites()) {
            return $result;
        }

        try {
            $contractor = SBISInfo::getPreparedContractor($client, $isForce);
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        $this->contractor = $contractor;

        if (!$contractor || !$contractor->getEdfId()) {
            return sprintf('Данный клиент не зарегистрирован ни в одной из систем документооборота');
        }

        $exchange = $contractor
            ->getExchanges()
            ->where([
                'exchange_id' => $contractor->getEdfId(),
                'is_deleted' => 0,
            ])
            ->orderBy([
                'is_main' => SORT_DESC,
                'id' => SORT_DESC,
            ])
            ->one();

        if (!$exchange) {
            return 'Маршрут до оператора ЭДО не доступен';
        }

        $this->edfId = $contractor->getEdfId();

        $code = substr($this->edfId, 0, 3);
        $this->operator = new EdfOperator($code);

        $this->isRoamingEnabled = $contractor->is_roaming || $this->operator->isAutoRoaming();

        return $result;
    }

    /**
     * Проверка реквизитов
     *
     * @return string
     */
    protected function checkRequisites()
    {
        $client = $this->client;

        $inn = $client->getInn();
        if ($client->contragent->legal_type === ClientContragent::PERSON_TYPE) {
            $person = $client->contragent->person;
            $inn = $person ? $person->inn : '';
        }

        if (!$inn) {
            return 'У контрагента данного клиента не заполнен ИНН!';
        }

        $this->legalType = $client->contragent->legal_type;
        switch ($client->contragent->legal_type) {
            case ClientContragent::LEGAL_TYPE:
                if (!@preg_match('/^(([0-9]{1}[1-9]{1}|[1-9]{1}[0-9]{1})[0-9]{8})$/', $inn)) {
                    return sprintf('У контрагента данного клиента ИНН не соответствует формату (10 цифр для ЮЛ): "%s"!', $inn);
                }

                $kpp = $client->getKpp();
                if (!$kpp) {
                    return 'У контрагента данного клиента не заполнен КПП!';
                }
                if (!@preg_match('/^(([0-9]{1}[1-9]{1}|[1-9]{1}[0-9]{1})([0-9]{2})([0-9A-Z]{2})([0-9]{3}))$/', $kpp)) {
                    return sprintf('У контрагента данного клиента КПП не соответствует формату (9 символов): "%s"!', $kpp);
                }
                $this->kpp = $kpp;

                break;

            case ClientContragent::IP_TYPE:
            case ClientContragent::PERSON_TYPE:
                if (!@preg_match('/^(([0-9]{1}[1-9]{1}|[1-9]{1}[0-9]{1})[0-9]{10})$/', $inn)) {
                    return sprintf('У контрагента данного клиента ИНН не соответствует формату (12 цифр для ИП/ФЛ): "%s"!', $inn);
                }

                break;
        }

        $this->inn = $inn;

        return '';
    }

    /**
     * Получить текст ошибки
     *
     * @return string
     */
    public function getErrorText()
    {
        return $this->errorText;
    }

    /**
     * Текст ошибки с проверкой на роуминг
     *
     * @return string
     */
    public function getFullErrorText()
    {
        if (!$this->errorText && !$this->isRoamingEnabled()) {
            switch ($this->legalType) {
                case ClientContragent::LEGAL_TYPE:
                    return sprintf('Роуминг в систему ЭДО "%s" не настроен для данного ЮЛ, ИНН/КПП: %s/%s', $this->operator->getName(), $this->inn, $this->kpp);
                case ClientContragent::IP_TYPE:
                    return sprintf('Роуминг в систему ЭДО "%s" не настроен для данного ИП, ИНН: %s', $this->operator->getName(), $this->inn);
                case ClientContragent::PERSON_TYPE:
                    return sprintf('Роуминг в систему ЭДО "%s" не настроен для данного ФЛ, ИНН: %s', $this->operator->getName(), $this->inn);
            }
        }

        return $this->errorText;
    }

    /**
     * @return bool
     */
    public function isValid()
    {
        return empty($this->errorText);
    }

    /**
     * @return string
     */
    public function getEdfId()
    {
        return $this->edfId;
    }

    /**
     * @return bool
     */
    public function isRoamingEnabled()
    {
        return $this->isRoamingEnabled;
    }

    /**
     * @return integer
     */
    public function getExchangeGroupIdDefault()
    {
        reset($this->exchangeGroupIds);
        return current($this->exchangeGroupIds);
    }

    /**
     * @return EdfOperator
     */
    public function getOperator()
    {
        return $this->operator;
    }
}
