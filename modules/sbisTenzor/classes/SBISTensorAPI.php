<?php

namespace app\modules\sbisTenzor\classes;

use app\classes\HttpClient;
use app\helpers\DateTimeZoneHelper;
use app\models\ClientAccount;
use app\models\ClientContragent;
use app\models\ClientContragentPerson;
use app\models\Organization;
use app\modules\sbisTenzor\classes\SBISTensorAPI\SBISDocumentInfo;
use app\modules\sbisTenzor\exceptions\SBISTensorException;
use app\modules\sbisTenzor\helpers\SBISInfo;
use app\modules\sbisTenzor\helpers\SBISUtils;
use app\modules\sbisTenzor\models\SBISAttachment;
use app\modules\sbisTenzor\models\SBISDocument;
use app\modules\sbisTenzor\models\SBISMchd;
use app\modules\sbisTenzor\models\SBISOrganization;
use DateTime;
use yii\httpclient\Response;
use yii\web\BadRequestHttpException;
use Yii;

/**
 * Взамодействие со СБИС API
 */
class SBISTensorAPI
{
    const CACHE_KEY_PREFIX = 'sbis_api_cache_';

    const METHOD_AUTH = 'СБИС.Аутентифицировать';
    const METHOD_LOG_OUT = 'СБИС.Выход';
    const METHOD_PROFILE = 'СБИС.ИнформацияОТекущемПользователе';
    const METHOD_CONTRACTOR_INFO = 'СБИС.ИнформацияОКонтрагенте';

    const METHOD_SAVE_DOCUMENT = 'СБИС.ЗаписатьДокумент';
    const METHOD_PREPARE_ACTION = 'СБИС.ПодготовитьДействие';
    const METHOD_EXECUTE_ACTION = 'СБИС.ВыполнитьДействие';
    const METHOD_READ_DOCUMENT = 'СБИС.ПрочитатьДокумент';
    const METHOD_CHANGE_LIST = 'СБИС.СписокИзменений';

    /** @var SBISOrganization */
    public $sbisOrganization;

    protected $authSid;

    /** @var Organization */
    protected $organizationFrom;

    protected $login = '';
    protected $password = '';
    protected $authUrl;
    protected $serviceUrl;
    protected $thumbprint;
    protected $needSign;
    public $signCommand;
    public $hashCommand;

    protected $requestId;

    /**
     * SBISTensorAPI constructor
     *
     * @param SBISOrganization $sbisOrganization
     * @throws \Exception
     */
    public function __construct(SBISOrganization $sbisOrganization)
    {
        $this->sbisOrganization = $sbisOrganization;

        $this->checkConfig();
        $this->init();
    }

    /**
     * Проверка конфига
     *
     * @throws \Exception
     */
    protected function checkConfig()
    {
        $this->organizationFrom = $this->sbisOrganization->getOrganization();
        if (!$this->organizationFrom) {
            throw new \Exception('SBISTensorApi host organization not found, sbisOrganization: ' . $this->sbisOrganization->id);
        }

        $fields = [
            'login',
            'password',
            'authUrl',
            'serviceUrl',
            'is_sign_needed',
            'thumbprint',
            'date_of_expire',
        ];
        foreach ($fields as $field) {
            if (empty($this->sbisOrganization->$field) && $field != 'is_sign_needed') { // boolean param can be FALSE
                throw new \Exception('SBISTensorApi config param not set: ' . $field);
            }
        }
    }

    /**
     * Init
     *
     */
    protected function init()
    {
        $this->login = $this->sbisOrganization->login;
        $this->password = $this->sbisOrganization->password;
        $this->authUrl = $this->sbisOrganization->authUrl;
        $this->serviceUrl = $this->sbisOrganization->serviceUrl;
        $this->needSign = $this->sbisOrganization->is_sign_needed;
        $this->thumbprint = $this->sbisOrganization->thumbprint;
        $this->signCommand = $this->sbisOrganization->signCommand;
        $this->hashCommand = $this->sbisOrganization->hashCommand;
    }

    /**
     * Получаем уникальный ключ учётной записи
     *
     * @return string
     */
    protected function getCacheKey()
    {
        return self::CACHE_KEY_PREFIX . md5($this->login . ':' . $this->password);
    }

    /**
     * Nice encoding
     *
     * @param mixed $input
     * @return false|string
     */
    protected function encode($input)
    {
        return json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Получить ID аутентифицированной сессии
     *
     * @return string
     */
    protected function getAuthSid()
    {
        if ($this->authSid) {
            return $this->authSid;
        }

        $cacheKey = $this->getCacheKey();
        if (Yii::$app->cache->exists($cacheKey) && ($value = Yii::$app->cache->get($cacheKey)) !== false) {
            $this->authSid = $value;
        }

        return $this->authSid;
    }

    /**
     * Сохранить ID аутентифицированной сессии
     *
     * @param string $authSid
     * @return $this
     */
    protected function setAuthSid($authSid)
    {
        Yii::$app->cache->set($this->getCacheKey(), $authSid, 0);
        $this->authSid = $authSid;

        return $this;
    }

    /**
     * Авторизация в СБИС
     *
     * @see https://online.sbis.ru/shared/disk/0f875088-6774-4c48-81e4-5c09e4189b93
     * @return array|string ID аутентифицированной сессии
     * @throws BadRequestHttpException
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     */
    protected function auth()
    {
        if ($authSid = $this->getAuthSid()) {
            return $authSid;
        }

        $authSid = $this->sendRequest($this->authUrl, self::METHOD_AUTH, [
            'Параметр' => [
                'Логин'     => $this->login,
                'Пароль'    => $this->password,
            ],
        ]);

        $this->setAuthSid($authSid);

        return $authSid;
    }

    /**
     * Отправка запроса в СБИС
     *
     * @param string $url
     * @param string $method
     * @param array $params
     * @param int $protocol
     * @param int $id
     * @return mixed
     * @throws BadRequestHttpException
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     */
    protected function sendRequest($url, $method, $params = [], $protocol = 4, $id = 0)
    {
        $data = [
            'jsonrpc'  => '2.0',
            'method'   => $method,
            'protocol' => $protocol,
            'params'   => $params,
            'id'       => $id,
        ];

        $headers = [
            'Content-Type'      => 'application/json-rpc;charset=utf-8',
            'User-Agent'        => 'PHP',
        ];

        $isAuthNeeded = $url !== $this->authUrl || ($method === self::METHOD_LOG_OUT);
        if ($isAuthNeeded && ($authSid = $this->auth())) {
            $headers['X-SBISSessionID'] = $authSid;
        }

        $request = [
            'headers'   => $headers,
            'data'      => $data,
        ];

        // log request
        $this->requestId = SBISUtils::generateUuid();
        Yii::info(sprintf('SBIS request (%s): %s', $this->requestId, $this->encode($request)), SBISDocument::LOG_CATEGORY);

        try {
            $response = (new HttpClient())
                ->createJsonRequest()
                ->setMethod('POST')
                ->setHeaders($headers)
                ->setData($data)
                ->setUrl($url)
                ->setIsCheckOk(false)
                ->send();
        } catch(\Exception $e) {
            Yii::error(sprintf('SBIS error while processing request (%s): %s', $this->requestId, $e->getMessage()), SBISDocument::LOG_CATEGORY);
            throw new SBISTensorException(
                sprintf(
                    'Неизвестная ошибка от СБИС при запросе по адресу %s с данными %s, ошибка: %s',
                    $url,
                    $this->encode($request),
                    $e->getMessage()
                ),
                500
            );
        }

        return $this->processResponse($response, $url, $request);
    }

    /**
     * Обработка ответа от СБИС
     *
     * @param Response $response
     * @param string $url
     * @param array $request
     * @return mixed
     * @throws SBISTensorException
     */
    protected function processResponse(Response $response, $url, array $request)
    {
        if (!$response) {
            Yii::error(sprintf('SBIS error while processing request (%s): Не получен ответ от СБИС по адресу %s', $this->requestId, $url), SBISDocument::LOG_CATEGORY);
            throw new SBISTensorException(
                sprintf(
                    'Не получен ответ от СБИС по адресу %s с запросом %s' ,
                    $url,
                    $this->encode($request)
                ),
                500
            );
        }

        $result = $response->getData();
        if (!$result || !is_array($result)) {
            Yii::error(sprintf('SBIS error while processing request (%s): Получен не JSON-ответ от СБИС по адресу %s', $this->requestId, $url), SBISDocument::LOG_CATEGORY);
            throw new SBISTensorException(
                sprintf(
                    'Получен не JSON-ответ от СБИС по адресу %s с запросом %s' ,
                    $url,
                    $this->encode($request)
                ),
                500
            );
        }

        // log response
        Yii::info(sprintf('SBIS response (%s): %s', $this->requestId, $this->encode($result)), SBISDocument::LOG_CATEGORY);

        if (isset($result['error']) && $result['error']) {
            if (
                !empty($result['id']) && $result['id'] === -1 &&
                is_string($result['error']) && $result['error'] === "Not authorized."
            ) {
                // Not authorized.
                $this->setAuthSid(false);
                return false;
            }

            $details = '';
            if (!empty($result['error']['details'])) {
                $details = $result['error']['details'];
            } elseif (is_string($result['error'])) {
                $details = $result['error'];
            }

            $code = 500;
            if (!empty($result['error']['code'])) {
                $code = $result['error']['code'];
            } elseif (!empty($result['id'])) {
                $code = $result['id'];
            }

            Yii::error(sprintf('SBIS error while processing request (%s): СБИС ответил ошибкой "%s" (%s) по адресу %s', $this->requestId, $details, $code, $url), SBISDocument::LOG_CATEGORY);
            throw new SBISTensorException(
                sprintf(
                    'СБИС ответил ошибкой "%s" (%s) по адресу %s с запросом %s, ответ: %s',
                    $details,
                    $code,
                    $url,
                    $this->encode($request),
                    $this->encode($result)
                ),
                $code
            );
        }

        if (!array_key_exists('result', $result)) {
            Yii::error(sprintf('SBIS error while processing request (%s): СБИС вернул неизвестный ответ по адресу %s', $this->requestId, $url), SBISDocument::LOG_CATEGORY);
            throw new SBISTensorException(
                sprintf(
                    'СБИС вернул неизвестный ответ по адресу %s с запросом %s, ответ: %s',
                    $url,
                    $this->encode($request),
                    $this->encode($result)
                ),
                500
            );
        }

        return $result['result'];
    }

    /**
     * Закрыть сессию
     *
     * @return bool
     * @throws BadRequestHttpException
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     */
    public function logOut()
    {
        $result = $this->sendRequest($this->authUrl, self::METHOD_LOG_OUT);

        return is_null($result);
    }

    /**
     * Получить информацию о текущем пользователе
     *
     * @return array
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     * @throws \yii\web\BadRequestHttpException
     */
    public function getProfileInfo()
    {
        $data = ['Параметр' => null];

        $result = $this->sendRequest($this->serviceUrl, self::METHOD_PROFILE, $data);

        return $result['Пользователь'];
    }

    /**
     * Получить информацию о контрагенте
     *
     * @param string $inn
     * @param string $kpp
     * @param string $branchCode
     * @return array
     * @throws BadRequestHttpException
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     */
    public function getContractorInfoLegal($inn, $kpp, $name = '', $branchCode = '')
    {
        $info = [
            'ИНН' => $inn,
            'КПП' => $kpp,
        ];

        if ($branchCode) {
            $info['КодФилиала'] = strval($branchCode);
        }

        if ($name) {
            $info['Название'] = $name;
        }

        $data = [
            'Участник' => [
                'СвЮЛ' => $info,
                'ДопПоля' => 'СписокИдентификаторов',
            ],
        ];

        $result = $this->sendRequest($this->serviceUrl, self::METHOD_CONTRACTOR_INFO, $data);

        return $result;
    }

    /**
     * Получить информацию о контрагенте
     *
     * @param string $inn
     * @param string $branchCode
     * @return array
     * @throws BadRequestHttpException
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     */
    public function getContractorInfoIp($inn, $branchCode = 0)
    {
        $data = [
            'Участник' => [
                'СвФЛ' => [
                    'ИНН' => $inn,
                ] + ($branchCode ? ['КодФилиала' => strval($branchCode)] : []),
                'ДопПоля' => 'СписокИдентификаторов',
            ],
        ];

        $result = $this->sendRequest($this->serviceUrl, self::METHOD_CONTRACTOR_INFO, $data);

        return $result;
    }

    /**
     * Получить информацию о контрагенте
     *
     * @param ClientContragentPerson|null $person
     * @param string $inila СНИЛС
     * @return array
     * @throws BadRequestHttpException
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     */
    public function getContractorInfoPerson(ClientContragentPerson $person = null, $inila = '')
    {
        $data = [
            'Участник' => [
                'СвФЛ' => [
                    'ЧастноеЛицо' => 'Да',
                ],
                'ДопПоля' => 'СписокИдентификаторов',
            ],
        ];

        if ($person && $person->inn) {
            $data['Участник']['СвФЛ']['ИНН'] = $person->inn;
        }
        if ($inila) {
            $data['Участник']['СвФЛ']['СНИЛС'] = $inila;
        }
        if ($person && $person->last_name) {
            $data['Участник']['СвФЛ']['Фамилия'] = $person->last_name;
        }
        if ($person && $person->first_name) {
            $data['Участник']['СвФЛ']['Имя'] = $person->first_name;
        }
        if ($person && $person->middle_name) {
            $data['Участник']['СвФЛ']['Отчество'] = $person->middle_name;
        }

        $result = $this->sendRequest($this->serviceUrl, self::METHOD_CONTRACTOR_INFO, $data);

        return $result;
    }

    /**
     * Получить информацию о контрагенте
     *
     * @param ClientAccount $client
     * @return array
     * @throws BadRequestHttpException
     * @throws SBISTensorException
     * @throws \Exception
     */
    public function getContractorInfo(ClientAccount $client)
    {
        switch ($client->contragent->legal_type) {
            case ClientContragent::PERSON_TYPE:
                $person = $client->contragent->person;
                $result = $this->getContractorInfoPerson($person);
                break;

            case ClientContragent::IP_TYPE:
                $result = $this->getContractorInfoIp($client->getInn(), $client->getBranchCode());
                break;

            case ClientContragent::LEGAL_TYPE:
                $result = $this->getContractorInfoLegal($client->getInn(), $client->getKpp(), $client->contract->contragent->name, $client->getBranchCode());
                break;

            default:
                return [];
        }

        return $this->processContractorInfoResponse($client, $result);
    }

    /**
     * Обработка информации по контрагенту с проверкой на совпадение реквизитов
     *
     * @param ClientAccount $client
     * @param array $result
     * @return array
     * @throws \Exception
     */
    protected function processContractorInfoResponse(ClientAccount $client, array $result)
    {
        // ИНН
        if (
            array_key_exists('СвЮЛ', $result) &&
            ($result['СвЮЛ']['ИНН'] !== $client->getInn())
        ) {
            throw new \LogicException(sprintf('ИНН ЮЛ %s не совпадает с ИНН ЮЛ в системе СБИС: %s, %s', $client->getInn(), $result['СвЮЛ']['ИНН'], $result['СвЮЛ']['Название']));
        } elseif (array_key_exists('СвФЛ', $result)) {
            $person = $client->contragent->person;
            $expectedInn = $person ? $person->inn : '';

            if (!empty($result['СвФЛ']['ИНН']) && $expectedInn && ($result['СвФЛ']['ИНН'] !== $expectedInn)) {
                $type = $result['СвФЛ']['ЧастноеЛицо'] === 'Да' ? 'ФЛ' : 'ИП';
                throw new \LogicException(
                    sprintf(
                        'ИНН %s %s не совпадает с ИНН %s в системе СБИС: %s, %s %s',
                        $type,
                        $expectedInn,
                        $type,
                        $result['СвФЛ']['ИНН'],
                        $type,
                        $result['СвФЛ']['Фамилия']
                    )
                );
            }
        }

        // КПП
        if (
            array_key_exists('СвЮЛ', $result) &&
            ($result['СвЮЛ']['КПП'] !== $client->getKpp())
        ) {
            throw new \LogicException(sprintf('КПП ЮЛ %s не совпадает с КПП ЮЛ в системе СБИС: %s, %s', $client->getKpp(), $result['СвЮЛ']['КПП'], $result['СвЮЛ']['Название']));
        }

        // Код филиала
        if (array_key_exists('СвЮЛ', $result)) {
            $branchCode = $client->getBranchCode();
            if (is_null($branchCode)) {
                // у нас Код филиала не заполнен - игнорируем, что пришло для целостности
                unset($result['СвЮЛ']['КодФилиала']);
            } else if ($result['СвЮЛ']['КодФилиала'] !== $client->getBranchCode()) {
                throw new \LogicException(
                    sprintf(
                        'Клиент #%s, %s: код филиала ЮЛ "%s" не совпадает с Кодом филиала ЮЛ в системе СБИС: "%s"',
                        $client->id,
                        $result['СвЮЛ']['Название'],
                        $client->getBranchCode(),
                        $result['СвЮЛ']['КодФилиала']
                    )
                );
            }
        }

        return $result;
    }

    /**
     * Получить информацию о клиенте
     *
     * @param string $inn
     * @param string|null $ogrn
     * @return array
     * @throws BadRequestHttpException
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     */
    public function getClientInfo($inn = '7712040126', $ogrn = null)
    {
        if (!$inn && !$ogrn) {
            throw new SBISTensorException('Нужно передать ИНН или ОГРН', 500);
        }
        $data = ['inn' => null, 'ogrn' => null];
        if ($inn) {
            $data['inn'] = $inn;
        }
        if ($ogrn) {
            $data['ogrn'] = $ogrn;
        }

        $result = $this->sendRequest($this->serviceUrl, 'SppAPI.Requisites', $data);

        return $result;
    }

    /**
     * СБИС.СписокИзменений - список последних изменений по фильтру
     *
     * @param int $typeId Тип документа
     * @param string $eventId идентификатор последнего обработанного события
     * @param \DateTimeImmutable $dateFrom
     * @param \DateTimeImmutable $dateTo
     * @param bool $fullSign Полный сертификат ЭП
     * @return array
     * @throws BadRequestHttpException
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     */
    public function changeList($typeId = null, $eventId = null, \DateTimeImmutable $dateFrom = null, \DateTimeImmutable $dateTo = null, $fullSign = false)
    {
        if ($eventId) {
            $filter['ИдентификаторСобытия'] = $eventId;
        }
        if ($dateFrom) {
            // в формате "ДД.ММ.ГГГГ ЧЧ.ММ.СС"
            $filter['ДатаВремяС'] = $dateFrom->format('d.m.Y H.i.s');
        }
        if ($dateTo) {
            // в формате "ДД.ММ.ГГГГ ЧЧ.ММ.СС"
            $filter['ДатаВремяПо'] = $dateTo->format('d.m.Y H.i.s');
        }

        if ($typeId) {
            $filter['Тип'] = SBISDocumentType::getById($typeId);
        }
        $filter['НашаОрганизация']['СвЮЛ'] = [
            'ИНН' => $this->organizationFrom->tax_registration_id,
            'КПП' => $this->organizationFrom->tax_registration_reason,
        ];
        $filter['ПолныйСертификатЭП'] = $fullSign ? 'Да' : 'Нет';

        $result = $this->sendRequest($this->serviceUrl, self::METHOD_CHANGE_LIST, ['Фильтр' => $filter]);

        return $result;
    }

    /**
     * Запросить последние изменения по документам
     *
     * @param string $lastEventId
     * @return array
     * @throws BadRequestHttpException
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     */
    public function fetchDocumentsInfo($lastEventId = null)
    {
        $dateFrom = null;
        $dateTo = null;

        if (is_null($lastEventId)) {
            $lastEventId = $this->sbisOrganization->last_event_id;
            if (!$lastEventId) {
                $dateFrom = new \DateTimeImmutable($this->sbisOrganization->last_fetched_at, new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
                $dateTo = $dateFrom->modify('+3 day');
                $lastEventId = null;
            }
        }

        $result = $this->changeList(SBISDocumentType::SHIPPED_OUT, $lastEventId, $dateFrom, $dateTo);

        $documentsInfo = [];
        if (!empty($result['Документ'])) {
            foreach ($result['Документ'] as $documentData) {
                $documentInfo = new SBISDocumentInfo($documentData);

                $documentsInfo[$documentInfo->externalId] = $documentInfo;
            }
        }

        return $documentsInfo;
    }

    /**
     * СБИС.ПрочитатьДокумент - информация по документу
     *
     * @param string $documentId
     * @return array
     * @throws BadRequestHttpException
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     */
    public function readDocument($documentId)
    {
        $document['Идентификатор'] = $documentId;

        $result = $this->sendRequest($this->serviceUrl, self::METHOD_READ_DOCUMENT, ['Документ' => $document]);

        return $result;
    }

    /**
     * СБИС.ЗаписатьДокумент - 1й (из 3х) шаг в отправке пакета документов контрагенту
     *
     * @param SBISDocument $document пакет документов
     * @return bool
     * @throws BadRequestHttpException
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     * @throws \Exception
     */
    public function saveDocument(SBISDocument $document)
    {
        $documentData['Идентификатор'] = $document->external_id;

        $documentData['Вложение'] = [];
        foreach ($document->attachments as $attachment) {
            $fileName = $attachment->stored_path;
            if (!file_exists($fileName)) {
                $errorText = sprintf(
                    'Файл "%s" не найден при отправке документа в СБИС (document id: %s, attachment id: %s)',
                    $fileName,
                    $document->id,
                    $attachment->id
                );
                Yii::error($errorText, SBISDocument::LOG_CATEGORY);
                throw new SBISTensorException(
                    $errorText,
                    500
                );
            }

            $attachStruct = [
                'Идентификатор' => $attachment->external_id,
                'Файл' => [
                    'ДвоичныеДанные' => base64_encode(file_get_contents($fileName)),
                    'Имя' => $attachment->file_name,
                ],
            ];

            SBISMchd::addMchd($document, $attachStruct);
            $documentData['Вложение'][] = $attachStruct;
        }


        $date = '';
        if ($document->date) {
            $dateTime = new DateTime($document->date);
            $date = $dateTime->format('d.m.Y');
        }
        $documentData['Дата'] = $date;
        $documentData['Номер'] = $document->number;

        $documentData['Контрагент']['Идентификатор'] = SBISInfo::getExchangeIntegrationId($document->clientAccount);

        $legalType = $document->clientAccount->contragent->legal_type;
        if ($legalType == ClientContragent::LEGAL_TYPE) {
            $documentData['Контрагент']['СвЮЛ'] = [
                'ИНН' => $document->clientAccount->contragent->inn,
                'КПП' => $document->clientAccount->contragent->kpp,
                'Название' => strval($document->clientAccount->contragent->name_full),
            ];

            if ($branchCode = $document->clientAccount->getBranchCode()) {
                $documentData['Контрагент']['СвЮЛ']['КодФилиала'] = $branchCode;
            }
        } else {
            $documentData['Контрагент']['СвФЛ'] = [
                'ИНН' => $document->clientAccount->contragent->inn,
            ];

            if ($legalType == ClientContragent::PERSON_TYPE) {
                $documentData['Контрагент']['СвФЛ']['ЧастноеЛицо'] = 'Да';
            }
        }

        $documentData['НашаОрганизация']['СвЮЛ'] = [
            'ИНН' => $this->organizationFrom->tax_registration_id,
            'КПП' => $this->organizationFrom->tax_registration_reason,
        ];

        $documentData['Примечание'] = $document->comment;
        $documentData['Редакция']['ПримечаниеИС'] = '';
        $documentData['Тип'] = $document->typeName;

        $result = $this->sendRequest($this->serviceUrl, self::METHOD_SAVE_DOCUMENT, ['Документ' => $documentData]);

        if (!empty($result['Вложение'])) {
            $attachments = $document->attachments;
            foreach ($result['Вложение'] as $i => $attachmentData) {
                $attachment = $attachments[$i + 1];

                $attachment->link = $attachmentData['Файл']['Ссылка'];
                $attachment->url_online = $attachmentData['СсылкаВКабинет'];
                $attachment->url_html = $attachmentData['СсылкаНаHTML'];
                $attachment->url_pdf = $attachmentData['СсылкаНаPDF'];
            }

            $document->setAttachments($attachments);
        }

        $documentInfo = new SBISDocumentInfo($result);
        $document->applyDocumentInfo($documentInfo);

        return $document->external_id == $documentInfo->externalId;
    }

    /**
     * СБИС.ЗаписатьДокумент - 2й (из 3х) шаг в отправке пакета документов контрагенту
     *
     * @param SBISDocument $document пакет документов
     * @return bool
     * @throws BadRequestHttpException
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     */
    public function prepareAction(SBISDocument $document)
    {
        $documentData['Идентификатор'] = $document->external_id;

        $documentData['Этап'] = [
            'Действие' => [
                'Название' => 'Отправить',
                'Сертификат' => [
                    'Отпечаток' => $this->thumbprint
                ],
            ],
            'Название' => 'Отправка',
        ];

        $result = $this->sendRequest($this->serviceUrl, self::METHOD_PREPARE_ACTION, ['Документ' => $documentData]);

        if (!empty($result['Этап'][0]['Вложение'])) {
            $attachments = $document->attachments;

            foreach ($result['Этап'][0]['Вложение'] as $i => $attachmentData) {
                $attachment = $attachments[$i + 1];

                $attachment->hash = $attachmentData['Файл']['Хеш'];
                $attachment->link = $attachmentData['Файл']['Ссылка'];

                $attachment->url_online = $attachmentData['СсылкаВКабинет'];
                $attachment->url_html = $attachmentData['СсылкаНаHTML'];
                $attachment->url_pdf = $attachmentData['СсылкаНаPDF'];

                if ($attachmentData['Модифицирован'] === 'Да') {
                    // original file modified
                    $this->processModifiedFile($attachment);
                }
            }

            $document->setAttachments($attachments);
        }

        $success = false;
        if (!empty($result['Идентификатор']) && $result['Идентификатор'] === $document->external_id) {
            $success = true;
        }

        return $success;
    }

    /**
     * СБИС.ВыполнитьДействие - 3й (из 3х) шаг в отправке пакета документов контрагенту
     *
     * @param SBISDocument $document пакет документов
     * @param string $comment Комментарий к действию
     * @return bool
     * @throws BadRequestHttpException
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     */
    public function executeAction(SBISDocument $document, $comment = '')
    {
        $documentData['Идентификатор'] = $document->external_id;

        $stage = [];
        if ($this->needSign) {
            $attachments = [];
            foreach ($document->attachments as $attachment) {
                $attachment->sign($this->signCommand, $this->hashCommand);

                $attachStruct = [
                    'Идентификатор' => $attachment->external_id,
                    'Подпись' => [
                        'Файл' => [
                            'ДвоичныеДанные' => strtr(file_get_contents($attachment->signature_stored_path), ["\r" => "", "\n" => ""]),
                            'Имя' => basename($attachment->signature_stored_path),
                        ],
                    ],
                ];

                SBISMchd::addMchd($document, $attachStruct['Подпись']);
                $attachments[] = $attachStruct;
            }

            $stage['Вложение'] = $attachments;
        }

        $stage['Действие'] = [
            'Комментарий' => $comment,
            'Название' => 'Отправить',
            'Сертификат' => [
                'Отпечаток' => $this->thumbprint
            ],
        ];
        $stage['Название'] = 'Отправка';

        $documentData['Этап'] = $stage;

        $result = $this->sendRequest($this->serviceUrl, self::METHOD_EXECUTE_ACTION, ['Документ' => $documentData]);

        $documentInfo = new SBISDocumentInfo($result);
        $document->applyDocumentInfo($documentInfo);

        return
            ($document->external_id == $documentInfo->externalId) &&
            in_array($documentInfo->externalState, [
                SBISDocumentStatus::EXTERNAL_SENT_INVITATION,
                SBISDocumentStatus::EXTERNAL_SENT,
            ])
            ;
    }

    /**
     * Отправка GET запроса в СБИС на получение изменённого файла
     *
     * @param SBISAttachment $attachment
     * @throws BadRequestHttpException
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     * @throws \yii\httpclient\Exception
     */
    public function processModifiedFile(SBISAttachment $attachment)
    {
        $url = $attachment->link;
        if (!$url) {
            $attachment->document->addErrorText(
                sprintf("SBIS modified file: no link, attachment #%s", $attachment->id)
            );

            return;
        }

        $headers = [
            'Content-Type' => 'application/json-rpc;charset=windows-1251',
        ];

        if ($authSid = $this->auth()) {
            $headers['X-SBISSessionID'] = $authSid;
        }

        $request = [
            'url' => $url,
            'headers' => $headers,
        ];

        // log request
        $this->requestId = SBISUtils::generateUuid();
        Yii::info(sprintf('SBIS GET request (%s): %s', $this->requestId, $this->encode($request)), SBISDocument::LOG_CATEGORY);

        try {
            $response = (new HttpClient())
                ->createRequest()
                ->setHeaders($headers)
                ->setUrl($url)
                ->setIsCheckOk(false)
                ->send();
        } catch (\Exception $e) {
            $attachment->document->addErrorText(
                sprintf(
                    'Неизвестная ошибка от СБИС при GET-запросе %s по адресу %s, ошибка: %s',
                    $this->requestId,
                    $url,
                    $e->getMessage()
                )
            );

            return;
        }

        $this->processModifiedFileResponse($attachment, $response, $url);
    }

    /**
     * Обработка ответа от GET-запроса к СБИС
     *
     * @param SBISAttachment $attachment
     * @param Response $response
     * @param string $url
     * @throws \yii\httpclient\Exception
     */
    protected function processModifiedFileResponse(SBISAttachment $attachment, Response $response, $url = '')
    {
        if (!$response) {
            $attachment->document->addErrorText(
                sprintf(
                    'Не получен ответ от СБИС при GET-запросе %s по адресу %s' ,
                    $this->requestId,
                    $url
                )
            );

            return;
        }

        if (!$response->getIsOk()) {
            $attachment->document->addErrorText(
                sprintf(
                    'Получен ошибочный ответ от СБИС при GET-запросе %s по адресу %s: %s' ,
                    $this->requestId,
                    $url,
                    $response->getStatusCode()
                )
            );

            return;
        }

        $content = $response->getContent();
        if (!$content) {
            $attachment->document->addErrorText(
                sprintf(
                    'Не получен тело файла от СБИС при GET-запросе %s по адресу %s, attachment #%s' ,
                    $this->requestId,
                    $url,
                    $attachment->id
                )
            );

            return;
        }

        $contentDisposition = $response->getHeaders()->get('content-disposition');
        if (!$contentDisposition) {
            $attachment->document->addErrorText(
                sprintf("SBIS modified file: empty content-disposition, attachment #%s", $attachment->id)
            );
        }

        // log response
        Yii::info(sprintf('SBIS response for content-disposition "%s" (%s): %s', $contentDisposition, $this->requestId, $this->encode($response->getData())), SBISDocument::LOG_CATEGORY);

        if (
            !preg_match_all("/[\w_-]+\.\w+/", $contentDisposition, $output) ||
            empty($output[0][0])
        ) {
            $attachment->document->addErrorText(
                sprintf("SBIS modified file: no file name in content-disposition, attachment #%s: %s", $attachment->id, $contentDisposition)
            );
        }

        $attachment->saveModifiedFile($output[0][0], $content);
    }
}
