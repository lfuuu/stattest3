<?php

namespace app\modules\atol\classes;

use app\classes\HttpClient;
use app\classes\Singleton;
use app\models\Payment;
use kartik\base\Config;
use kartik\base\Module;
use yii\base\InvalidConfigException;
use yii\helpers\Json;
use yii\web\BadRequestHttpException;

/**
 * @method static Api me($args = null)
 */
class Api extends Singleton
{
    /** @var Module */
    private $_module = null;

    private $_token = [];

    const RESPONSE_TOKEN_CODE_OK_NEW = 0; // Выдан новый токен
    const RESPONSE_TOKEN_CODE_OK_OLD = 1; // Выдан старый токен
    const RESPONSE_TOKEN_CODE_ERROR = 2; // Этот и все последующие коды - ошибка

    const OPERATION_SELL = 'sell'; // Приход
    const OPERATION_SELL_REFUND = 'sell_refund'; // Возврат прихода
    const OPERATION_SELL_CORRECTION = 'sell_correction'; // Коррекция прихода
    const OPERATION_BUY = 'buy'; // Расход
    const OPERATION_BUY_REFUND = 'buy_refund'; // Возврат расхода
    const OPERATION_BUY_CORRECTION = 'buy_correction'; // Коррекция расхода

    const TIMESTAMP_FORMAT = 'd.m.Y H:i:s'; // Формат timestamp

    const PAYMENT_TYPE_BANK = 1;

    // Устанавливает номер налога в ККТ
    const TAX_NONE = 'none'; // без НДС
    const TAX_VAT0 = 'vat0'; // НДС по ставке 0%
    const TAX_VAT5 = 'vat5'; // НДС чека по ставке 5%
    const TAX_VAT10 = 'vat10'; // НДС чека по ставке 10%
    const TAX_VAT18 = 'vat18'; // НДС чека по ставке 18%
    const TAX_VAT20 = 'vat20'; // НДС чека по ставке 20%
    const TAX_VAT22 = 'vat22'; // НДС чека по ставке 22%
    const TAX_VAT105 = 'vat105'; // НДС чека по расчетной ставке 5/105
    const TAX_VAT110 = 'vat110'; // НДС чека по расчетной ставке 10/110
    const TAX_VAT118 = 'vat118'; // НДС чека по расчетной ставке 18/118
    const TAX_VAT120 = 'vat120'; // НДС чека по расчетной ставке 18/120
    const TAX_VAT122 = 'vat122'; // НДС чека по расчетной ставке 22/122

    const RESPONSE_STATUS_WAIT = 'wait';
    const RESPONSE_STATUS_FAIL = 'fail';
    const RESPONSE_STATUS_DONE = 'done';

    /**
     * Инициализация
     */
    public function init()
    {
        if (!$this->_module) {
            $this->_module = Config::getModule('atol');
        }
    }

    /**
     * @return bool
     */
    public function isAvailable()
    {
        $isEnabled = $this->_module->params['isEnabled'];

        if (!$isEnabled) {
            return false;
        }

        $apiVersion = $this->_module->params['apiVersion'];
        $params = $this->_module->params['buyOrSell'];

        $access = reset($this->_module->params['access']);

        $url = $params['url'];
        $groupCode = $access['groupCode'];
        $inn = $access['inn'];
        $paymentAddress = $params['paymentAddress'];
        $itemName = $params['itemName'];

        return $apiVersion && $url && $groupCode && $inn && $paymentAddress && $itemName;
    }

    /**
     * Отправить данные
     *
     * @param int $externalId
     * @param string $email
     * @param string $phone
     * @param float $itemPrice
     * @param $organizationId
     * @return string[] [$uuid, $log]
     *
     * @throws InvalidConfigException
     * @throws \Exception
     * @link https://online.atol.ru/
     */
    public function sendSell($externalId, $email, $phone, $itemPrice, $organizationId)
    {
//        $responseData= ['uuid' => 'xxx-xxxx-xxxx-xxx'];
//        return [$responseData['uuid'], Json::encode($responseData)];

        $phone = str_replace('+7', '', $phone);
        if (!$email && $phone && !preg_match('/\d{10}/', $phone)) {
            throw new \LogicException('Указан неправильный номер телефона ');
        }

        if (!$email && !$phone) {
            throw new \LogicException('Не указаны контакты клиента');
        }

        if (!isset($this->_module->params['access']['organization_' . $organizationId])) {
            throw new InvalidConfigException('Не настроен организации ' . $organizationId . ' в конфиге Атол');
        }

        $access = $this->_module->params['access']['organization_' . $organizationId];

        $apiVersion = $this->_module->params['apiVersion'];
        $callbackUrl = $this->_module->params['callbackUrl']; // можно пустое
        $params = $this->_module->params['buyOrSell'];

        $url = $params['url'];
        $groupCode = $access['groupCode'];
        $inn = $access['inn'];
        $paymentAddress = $params['paymentAddress'];
        $sno = isset($access['sno']) ? $access['sno'] : $params['sno']; // можно пустое
        $itemName = $params['itemName'];

        $payment = Payment::findOne($externalId);
        $taxRate = (int)$payment->client->getTaxRate();
        $taxType = self::vatTypeByRate($taxRate);

        if (!$this->isAvailable()) {
            throw new InvalidConfigException('Не настроен конфиг Атол');
        }

        $itemTaxSum = 0;

        if ($taxRate) {
            $itemTaxSum = round($taxRate / (100.0 + $taxRate) * $itemPrice, 2);
        }

        $token = $this->_getToken($access);

        $url = strtr($url, [
            '<api_version>' => $apiVersion,
            '<group_code>' => $groupCode,
            '<operation>' => self::OPERATION_SELL,
        ]);

        $timestamp = date(self::TIMESTAMP_FORMAT);
        $itemQuantity = 1;
        $paymentSum = $total = $itemSum = $itemPrice * $itemQuantity;
        $paymentType = self::PAYMENT_TYPE_BANK;

        $data = [
            // Идентификатор документа внешней системы, уникальный среди всех документов, отправляемых в данную группу ККТ. Тип данных – строка.
            // Предназначен для защиты от потери документов при разрывах связи – всегда можно подать повторно чек с таким же external_ID.
            // Если данный external_id известен системе будет возвращен UUID, ранее присвоенный этому чеку, иначе чек добавится в систему с присвоением нового UUID.
            // Максимальная длина строки – 256 символов.
            // Например, '17052917561851307'
            'external_id' => (string)$externalId,

            'receipt' => [

                'client' => [

                    // Электронная почта покупателя.
                    // Максимальная длина строки – 64 символа.
                    // В запросе обязательно должно быть заполнено заполнено одно одно из полей: email или phone.
                    'email' => (string)$email,

                    // Телефон покупателя.
                    // Передается без префикса «+7». Максимальная длина строки – 64 символа.
                    'phone' => (string)$phone,

                ],
                'company' => [
                    'email' => '',

                    // Система налогообложения.
                    // «osn» – общая СН;
                    // «usn_income» – упрощенная СН (доходы);
                    // «usn_income_outcome» – упрощенная СН (доходы минус расходы);
                    // «envd» – единый налог на вмененный доход;
                    // «esn» – единый сельскохозяйственный налог;
                    // «patent» – патентная СН.
                    // Поле необязательно, если у организации один тип налогообложения.
                    // Например, 'osn'
                    'sno' => (string)$sno,


                    // ИНН организации.
                    // Используется для предотвращения ошибочных регистраций чеков на ККТ зарегистрированных с другим ИНН (сравнивается со значением в ФН).
                    // Допустимое количество символов 10 или 12.
                    // Например, '331122667723'
                    'inn' => (string)$inn,


                    // Адрес места расчетов.
                    // Используется для предотвращения ошибочных регистраций чеков на ККТ зарегистрированных с другим адресом места расчёта (сравнивается со значением в ФН).
                    // Максимальная длина строки – 256 символов.
                    // Например, 'magazin.ru'
                    'payment_address' => (string)$paymentAddress,
                ],

                // Ограничение по количеству от 1 до 100.
                'items' => [
                    [
                        // Наименование товара.
                        // Максимальная длина строки – 64 символа.
                        // Например, 'Название товара 1'
                        'name' => (string)$itemName,

                        // Цена в рублях:
                        // целая часть не более 8 знаков;
                        // дробная часть не более 2 знаков.
                        // Например, 5000.0
                        'price' => (float)$itemPrice,

                        // Количество/вес:
                        // целая часть не более 8 знаков;
                        // дробная часть не более 3 знаков.
                        // Например, 1.0
                        'quantity' => (float)$itemQuantity,

                        // Сумма позиции в рублях:
                        // целая часть не более 8 знаков;
                        // дробная часть не более 2 знаков.
                        // Если значение sum меньше/больше значения (price*quantity), то разница является скидкой/надбавкой на позицию соответственно.
                        // В этих случаях происходит перерасчёт поля price для равномерного распределения скидки/надбавки по позициям.
                        // Например, 5000.0
                        'sum' => (float)$itemSum,

//                        "measurement_unit" => "кг",
                        "payment_method" => "full_payment",
                        "payment_object" => "service",
                        "vat" => [
                            "type" => (string)$taxType
                        ]
                    ],
                ],

                // Оплата. Ограничение по количеству от 1 до 10.
                'payments' => [
                    [
                        // Сумма к оплате в рублях:
                        // целая часть не более 8 знаков;
                        // дробная часть не более 2 знаков.
                        // Например, 5000.0
                        'sum' => (float)$paymentSum,

                        // Вид оплаты. Возможные значения:
                        //
                        // «1» – безналичный;
                        // «2» – предварительная оплата (зачет аванса и
                        //   (или) предыдущих платежей);
                        // «3» – постоплата (кредит);
                        // «4» – иная форма оплаты (встречное предоставление);
                        // «5» – «9» – расширенные виды оплаты. Для
                        // каждого фискального типа оплаты можно
                        // указать расширенный вид оплаты.
                        'type' => (int)$paymentType,
                    ],
                ],
                'vats' => [
                    [
                        // Устанавливает номер налога в ККТ.
                        // «none» – без НДС;
                        // «vat0» – НДС по ставке 0%;
                        // «vat10» – НДС чека по ставке 10%;
                        // «vat18» – НДС чека по ставке 18%;
                        // «vat110» – НДС чека по расчетной ставке 10/110;
                        // «vat118» – НДС чека по расчетной ставке 18/118.
                        // «vat120» – НДС чека по расчетной ставке 20/120.
                        // Например, 'vat10'
                        'type' => (string)$taxType,


                        // Сумма налога позиции в рублях:
                        // целая часть не более 8 знаков;
                        // дробная часть не более 2 знаков.
                        // Например, 454.55
                        'sum' => (float)$itemTaxSum
                    ]
                ],

                // Итоговая сумма чека в рублях с заданным в CMS округлением:
                // целая часть не более 8 знаков;
                // дробная часть не более 2 знаков.
                // При регистрации в ККТ происходит расчёт фактической суммы: суммирование значений sum позиций. Допустимо значение total в диапазоне от целой части фактической суммы до точного значения фактической суммы.
                // Например, 5000.0
                'total' => (float)$total,
            ],
            'service' => [
                // URL, на который необходимо ответить после обработки документа.
                // Если поле заполнено, то после обработки документа (успешной или не успешной фискализации в ККТ), ответ будет отправлен POST запросом по URL указанному в данном поле.
                // Максимальная длина строки – 256 символов.
                'callback_url' => $callbackUrl,
            ],

            // Дата и время документа внешней системы в формате: «dd.mm.yyyy HH:MM:SS»
            // dd – День месяца. Формат DD. Возможные значения от «01» до «31».
            // mm – Месяц. Формат MM. Возможные значения от «01» до «12».
            // yyyy – Год. Формат YYYY. Допустимое количество символов – четыре.
            // HH – Часы. Формат HH. Возможные значения от «00» до «24».
            // MM – Минуты. Формат MM. Возможные значения от «00» до «59».
            // SS – Секунды. Формат SS. Возможные значения от «00» до «59».
            // Например, '29.05.2017 17:56:18'
            'timestamp' => (string)$timestamp,
        ];

        if (!$email) {
            unset($data['receipt']['client']['email']);
        }

        if (!$phone) {
            unset($data['receipt']['client']['phone']);
        }

        $query = (new HttpClient)
            ->createJsonRequest()
            ->setUrl($url)
            ->setMethod('post')
            ->setData($data)
            ->addHeaders(['Token' => $token])
            ->setIsCheckOk(false);// если первый запрос обработался, но упал, то повторный отвечает ошибкой, но с нужными данными

        $response = $query->send(\app\modules\atol\Module::LOG_CATEGORY);

        $responseData = $response->data;
        if (is_array($responseData)
            && isset($responseData['uuid'])
            && $responseData['uuid']
        ) {
            // всё хорошо
            return [$responseData['uuid'], Json::encode($responseData)];
        }

        // ошибка
        if (is_array($responseData)
            && isset($responseData['error'], $responseData['error']['text'])
            && $responseData['error']['text']
        ) {
            throw new \Exception($responseData['error']['text']);
        }

        throw new \Exception('Неизвестный ответ сервера. ' . Json::encode($responseData));
    }

    /**
     * Запросить статус
     *
     * @param int $uuid
     * @param string $organizationId
     * @return string[] [$status, $log]
     *
     * @throws InvalidConfigException
     * @throws \Exception
     * @link https://online.atol.ru/
     */
    public function getStatus($uuid, $organizationId)
    {
        if (!$uuid) {
            throw new \InvalidArgumentException();
        }

        $apiVersion = $this->_module->params['apiVersion'];
        $params = $this->_module->params['report'];
        $accesses = $this->_module->params['access'];

        if (!isset($accesses['organization_'.$organizationId])) {
            throw new InvalidConfigException('Не настроен организации ' . $organizationId . ' в конфиге Атол');
        }

        $access = $accesses['organization_'.$organizationId];
        $groupCode = $access['groupCode'];

        $url = $params['url'];

        if (!$apiVersion || !$url || !$groupCode) {
            throw new InvalidConfigException('Не настроен конфиг Атол');
        }

        $token = $this->_getToken($access);

        $url = strtr($url, [
            '<api_version>' => $apiVersion,
            '<group_code>' => $groupCode,
            '<uuid>' => $uuid,
        ]);

        $response = (new HttpClient)
            ->setResponseFormat(HttpClient::FORMAT_JSON)
            ->createRequest()
            ->setUrl($url)
            ->setMethod('get')
            ->addHeaders(['Token' => $token])
            ->send(\app\modules\atol\Module::LOG_CATEGORY);

        $responseData = $response->data;
        if (is_array($responseData)
            && isset($responseData['status'])
        ) {
            // всё хорошо
            return [$responseData['status'], Json::encode($responseData)];
        }

        // ошибка
        if (is_array($responseData)
            && isset($responseData['error'], $responseData['error']['text'])
            && $responseData['error']['text']
        ) {
            throw new \Exception($responseData['error']['text'] . '. ' . Json::encode($responseData));
        }

        throw new \Exception('Неизвестный ответ сервера. ' . Json::encode($responseData));
    }

    /**
     * Получить токен
     *
     * @return string
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    private function _getToken($access)
    {
        $key = md5(var_export($access, true));
        if ($this->_token[$key] ?? false) {
            return $this->_token[$key];
        }

        $apiVersion = $this->_module->params['apiVersion'];
        $params = $this->_module->params['getToken'];
        $url = $params['url'];
        $method = $params['method'];

        $login = $access['login'];
        $password = $access['password'];

        if (!$apiVersion || !$url || !$method || !$login || !$password) {
            throw new InvalidConfigException('Не настроен конфиг Атол');
        }

        $url = strtr($url, [
            '<api_version>' => $apiVersion,
        ]);

        $data = [
            'login' => $login,
            'pass' => $password,
        ];

        $response = (new HttpClient)
            ->createJsonRequest()
            ->setUrl($url)
            ->setMethod($method)
            ->setData($data)
            ->send(\app\modules\atol\Module::LOG_CATEGORY);

        $responseData = $response->data;
        $debugInfoResponse = sprintf('Response = %s', Json::encode($response->data)) . PHP_EOL;

        if (!is_array($responseData) || !isset($responseData['token'])) {
            throw new BadRequestHttpException('Ошибка получения токена.' . PHP_EOL . PHP_EOL . $debugInfoResponse);
        }

        if ((isset($responseData['error']) && $responseData['error']) || !$responseData['token']) {
            throw new BadRequestHttpException('Ошибка получения токена. ' . $responseData['text'] . PHP_EOL . PHP_EOL . $debugInfoResponse);
        }

        return $this->_token[$key] = $responseData['token'];
    }

    /**
     * @param int $rate
     * @return string
     * @throws InvalidConfigException
     */
    public static function vatTypeByRate($rate)
    {
        $map = [
            0 => self::TAX_NONE,
            5 => self::TAX_VAT5,
            10 => self::TAX_VAT10,
            18 => self::TAX_VAT18,
            20 => self::TAX_VAT20,
            22 => self::TAX_VAT22,
        ];

        if (!isset($map[$rate])) {
            throw new InvalidConfigException('Неизвестная ставка НДС: ' . $rate);
        }

        return $map[$rate];
    }

}