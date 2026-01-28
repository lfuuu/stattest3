<?php

namespace app\controllers\api\internal;

use app\classes\Assert;
use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\billing\DataRaw;
use app\models\billing\SmscRaw;
use app\models\ClientAccount;
use app\models\EventQueue;
use app\models\filter\SmsFilter;
use app\models\Number;
use app\models\voip\Source;
use app\modules\nnp\models\NdcType;
use app\modules\nnp\models\NumberRange;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\ServiceType;
use Yii;
use DateTime;
use app\exceptions\web\NotImplementedHttpException;
use app\exceptions\api\internal\ExceptionValidationForm;
use app\classes\ApiInternalController;
use app\classes\DynamicModel;
use app\classes\validators\AccountIdValidator;
use app\classes\validators\UsageVoipValidator;
use yii\base\InvalidParamException;
use app\models\billing\CallsRaw;
use yii\db\Expression;

class VoipController extends ApiInternalController
{

    /**
     * @throws NotImplementedHttpException
     */
    public function actionIndex()
    {
        throw new NotImplementedHttpException;
    }

    /**
     * @SWG\Definition(
     *   definition="call",
     *   type="object",
     *   required={"id", "connect_time", "src_number", "dst_number", "direction", "length", "cost", "rate"},
     *   @SWG\Property(property="id",type="integer",description="идентификатор звонка"),
     *   @SWG\Property(property="connect_time",type="date",description="дата начала звонка"),
     *   @SWG\Property(property="src_number",type="string",description="номер А"),
     *   @SWG\Property(property="dst_number",type="string",description="номер Б"),
     *   @SWG\Property(property="direction",type="string",description="направление"),
     *   @SWG\Property(property="length",type="integer",description="длительность звонка"),
     *   @SWG\Property(property="cost",type="number",description="стоимость звонка"),
     *   @SWG\Property(property="rate",type="number",description="стоимость минуты разговора")
     * ),
     * @SWG\Post(
     *   tags={"Статистика"},
     *   path="/internal/voip/calls/",
     *   summary="Получение списка звонков",
     *   operationId="Получение списка звонков",
     *   @SWG\Parameter(name="account_id",type="integer",description="идентификатор лицевого счёта",in="formData",default=""),
     *   @SWG\Parameter(name="number",type="string",description="номер телефона",in="formData",default=""),
     *   @SWG\Parameter(name="year",type="integer",description="год, за который выбирать звонки",in="formData",default=""),
     *   @SWG\Parameter(name="month",type="integer",description="месяц, за который выбирать звонки",in="formData",default=""),
     *   @SWG\Parameter(name="day",type="integer",description="день, за который выбирать звонки",in="formData",default=""),
     *   @SWG\Parameter(name="offset",type="integer",description="сдвиг в выборке записей",in="formData",default="0"),
     *   @SWG\Parameter(name="limit",type="integer",description="размер выборки",in="formData",maximum="10000",default="100"),
     *   @SWG\Parameter(name="is_with_nnp_info",type="integer",description="с NNP информацией",in="formData",default="0"),
     *   @SWG\Parameter(name="from_datetime",type="string",description="Время начала (по TZ-клиента) дата или дата-время",in="formData",default=""),
     *   @SWG\Parameter(name="to_datetime",type="string",description="Время окончания (по TZ-клиента)  дата или дата-время",in="formData",default=""),
     *   @SWG\Parameter(name="is_in_utc",type="string",description="Дата в параметрах и данных в UTC, иначе в TZ клиента",in="formData",default="1"),
     *   @SWG\Parameter(name="is_with_general_info",type="string",description="Подказывать общую информацию",in="formData",default="0"),
     *   @SWG\Parameter(name="is_with_nnp_info",type="string",description="Добавить ННП информацию",in="formData",default="0"),
     *   @SWG\Parameter(name="is_with_tariff_info",type="string",description="Добавить информацию о тарифе",in="formData",default="0"),
     *   @SWG\Response(
     *     response=200,
     *     description="данные о клиентах партнёра",
     *     @SWG\Schema(
     *       type="array",
     *       @SWG\Items(
     *         ref="#/definitions/call"
     *       )
     *     )
     *   ),
     *   @SWG\Response(
     *     response="default",
     *     description="Ошибки",
     *     @SWG\Schema(
     *       ref="#/definitions/error_result"
     *     )
     *   )
     * )
     * @throws \yii\base\InvalidConfigException
     */
    public function actionCalls()
    {
        $requestData = $this->requestData;

        $dateTimeRegexp = '/^(\d{4}-\d{2}-\d{2})( \d{2}:\d{2}:\d{2})?$/';
        $dateTimeStrongRegexp = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

        $model = DynamicModel::validateData(
            $requestData,
            [
                [['account_id', 'offset', 'limit', 'year', 'month', 'day', 'is_with_nnp_info', 'is_in_utc', 'is_with_general_info', 'is_with_nnp_info', 'is_with_tariff_info'], 'integer'],
                ['number', 'trim'],
                ['year', 'default', 'value' => (new DateTime())->format('Y')],
                ['month', 'default', 'value' => (new DateTime())->format('m')],
                ['offset', 'default', 'value' => 0],
                ['limit', 'default', 'value' => 1000000],
                ['is_in_utc', 'default', 'value' => 1],
                ['is_with_general_info', 'default', 'value' => 0],
                ['is_with_tariff_info', 'default', 'value' => 0],
                ['from_datetime', 'match', 'pattern' => $dateTimeRegexp],
                ['to_datetime', 'match', 'pattern' => $dateTimeRegexp],
                ['account_id', AccountIdValidator::class],
                ['number', UsageVoipValidator::class, 'account_id_field' => 'account_id', 'skipOnEmpty' => true],
            ]
        );

        if ($model->hasErrors()) {
            throw new ExceptionValidationForm($model);
        }

        if (($model->from_datetime || $model->to_datetime) && (!$model->from_datetime || !$model->to_datetime)) {
            throw new InvalidParamException('fields from_datetime and to_datetime must be filled');
        }

        $clientAccount = ClientAccount::findOne(['id' => $model->account_id]);
        Assert::isObject($clientAccount, 'ClientAccount#' . $model->account_id);

        if (!$model->offset || $model->offset < 0) {
            $model->offset = 0;
        }
        $model->offset = (int)$model->offset;

        if ($model->limit && $model->limit < 0) {
            $model->limit = 0;
        }
        $model->limit = (int)$model->limit;


        $utcTz = (new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
        $tz = $model->is_in_utc ? $utcTz : $clientAccount->timezone;

        if ($model->from_datetime && $model->to_datetime) {

            if (!preg_match($dateTimeStrongRegexp, $model->from_datetime)) {
                $model->from_datetime .= ' 00:00:00';
            }

            if (!preg_match($dateTimeStrongRegexp, $model->to_datetime)) {
                $model->to_datetime = (new \DateTimeImmutable($model->to_datetime))
                    ->modify('+1 day')
                    ->setTime(0, 0, 0)
                    ->format(DateTimeZoneHelper::DATETIME_FORMAT);
            }

            $firstDayOfDate = (new \DateTimeImmutable($model->from_datetime, $tz));
            $lastDayOfDate = (new \DateTimeImmutable($model->to_datetime, $tz));
        } else {
            $firstDayOfDate = (new \DateTimeImmutable('now', $tz))
                ->setDate($model->year, $model->month, $model->day ?: 1)
                ->setTime(0, 0, 0);

            $lastDayOfDate = $firstDayOfDate->modify('+1 day');

            !$model->day && $lastDayOfDate = $lastDayOfDate->modify('last day of this month');
        }

        $diff = $firstDayOfDate->diff($lastDayOfDate);

        if ($diff->y > 0 || $diff->m > 3 || ($diff->m > 1 && $diff->d > 2)) {
            throw new \InvalidArgumentException('DATETIME_RANGE_LIMIT', -10);
        }

        $callsData = CallsRaw::statisticsDao()->getCalls($clientAccount, $model, $firstDayOfDate, $lastDayOfDate);

        return $model->is_with_general_info ? ['info' => $callsData['generalInfo'], 'calls' => $callsData['result']] : $callsData['result'];
    }

    /**
     * @SWG\Get(tags = {"Numbers"}, path = "/internal/voip/number-info/", summary = "Получение информации о номере", operationId = "voip_number_info",
     *   @SWG\Parameter(name = "number", type = "string", description = "номер телефона", in = "query", default = "", required = true),
     *   @SWG\Response(response = 200, description = "Получение информации о номере",
     *     @SWG\Schema(type = "object", required = {"account_balance", "account_balance_available", "subaccount_balance"},
     *       @SWG\Property(property = "number", type = "string"),
     *       @SWG\Property(property = "ndc", type = "number"),
     *       @SWG\Property(property = "ndc_type", type = "number"),
     *       @SWG\Property(property = "country_name", type = "string"),
     *       @SWG\Property(property = "region_name", type = "string"),
     *       @SWG\Property(property = "city_name", type = "string"),
     *       @SWG\Property(property = "operator_name", type = "string"),
     *       @SWG\Property(property = "number_length", type = "number")
     *       )
     *     )
     *   )
     * )
     */
    public function actionNumberInfo()
    {
        $requestData = Yii::$app->request->get();

        $model = DynamicModel::validateData(
            $requestData,
            [
                ['number', 'string'],
                ['number', 'trim'],
                ['number', 'required'],
            ]
        );

        if ($model->hasErrors()) {
            throw new ExceptionValidationForm($model);
        }

        $number = Number::findOne(['number' => $model['number']]);

        if (!$number) {
            throw new InvalidParamException('Номер не найден');
        }

        return array_merge(
            ['number' => $number->number],
            NumberRange::getNumberInfo($number)
        );
    }

    /**
     * @SWG\Get(tags = {"Numbers"}, path = "/internal/voip/get-mvno-number-list/", summary = "Получение списка MVNO номеров", operationId = "get-mvno-number-list",
     *   @SWG\Parameter(name = "is_active", type = "integer", description = "Только активные (или все)", in = "query", default = 0, required = false),
     *   @SWG\Response(response = 200, description = "данные о клиентах партнёра",
     *     @SWG\Schema(type = "array", @SWG\Items(type = "string", description = "Номер телефона"))
     *   ),
     *   @SWG\Response(response = "default", description = "Ошибки", @SWG\Schema(ref = "#/definitions/error_result"))
     * )
     */
    public function actionGetMvnoNumberList()
    {
        $requestData = Yii::$app->request->get();

        $isActive = isset($requestData['is_active']) ? (bool)(int)$requestData['is_active'] : false;

        $query = Number::find()
            ->where(['ndc_type_id' => NdcType::ID_MOBILE])
            ->select('number')
            ->orderBy(['number' => SORT_ASC]);

        $isActive && $query->andWhere(['status' => Number::$statusGroup[Number::STATUS_GROUP_ACTIVE]]);

        return $query
            ->asArray()
            ->column();
    }


    /**
     * @SWG\Definition(
     *   definition="data",
     *   type="object",
     *   required={"id", "connect_time", "src_number", "dst_number", "direction", "length", "cost", "rate"},
     *   @SWG\Property(property="id",type="integer",description="идентификатор звонка"),
     *   @SWG\Property(property="connect_time",type="date",description="дата начала звонка"),
     *   @SWG\Property(property="src_number",type="string",description="номер А"),
     *   @SWG\Property(property="dst_number",type="string",description="номер Б"),
     *   @SWG\Property(property="direction",type="string",description="направление"),
     *   @SWG\Property(property="length",type="integer",description="длительность звонка"),
     *   @SWG\Property(property="cost",type="number",description="стоимость звонка"),
     *   @SWG\Property(property="rate",type="number",description="стоимость минуты разговора")
     * ),
     * @SWG\Post(
     *   tags={"Статистика"},
     *   path="/internal/voip/data/",
     *   summary="Мобильный интернет",
     *   operationId="Мобильный интернет",
     *   @SWG\Parameter(name="account_id",type="integer",description="идентификатор лицевого счёта",in="formData",default=""),
     *   @SWG\Parameter(name="number",type="string",description="номер телефона",in="formData",default=""),
     *   @SWG\Parameter(name="from_datetime",type="string",description="Время начала (по TZ-клиента) дата или дата-время",in="formData",default=""),
     *   @SWG\Parameter(name="to_datetime",type="string",description="Время окончания (по TZ-клиента)  дата или дата-время",in="formData",default=""),
     *   @SWG\Parameter(name="is_in_utc",type="string",description="Дата в параметрах и данных в UTC, иначе в TZ клиента",in="formData",default="1"),
     *   @SWG\Parameter(name="is_with_general_info",type="string",description="Подказывать общую информацию",in="formData",default="0"),
     *   @SWG\Parameter(name="group_by",type="string",description="Групировать по",in="formData",default="none",enum={"none", "number", "year", "month", "day", "hour"}),
     *   @SWG\Parameter(name="offset",type="integer",description="сдвиг в выборке записей",in="formData",default="0"),
     *   @SWG\Parameter(name="limit",type="integer",description="размер выборки",in="formData",maximum="10000",default="100"),
     *   @SWG\Response(
     *     response=200,
     *     description="данные о клиентах партнёра",
     *     @SWG\Schema(
     *       type="array",
     *       @SWG\Items(
     *         ref="#/definitions/data"
     *       )
     *     )
     *   ),
     *   @SWG\Response(
     *     response="default",
     *     description="Ошибки",
     *     @SWG\Schema(
     *       ref="#/definitions/error_result"
     *     )
     *   )
     * )
     * @throws \yii\base\InvalidConfigException
     */
    public function actionData()
    {

        $requestData = $this->requestData;

        $dateTimeRegexp = '/^(\d{4}-\d{2}-\d{2})( \d{2}:\d{2}:\d{2})?$/';
        $dateTimeStrongRegexp = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

        $model = DynamicModel::validateData(
            $requestData,
            [
                [['account_id', 'offset', 'limit', 'is_in_utc'], 'integer'],
                ['number', 'trim'],
                ['offset', 'default', 'value' => 0],
                ['limit', 'default', 'value' => 1000],
                ['is_in_utc', 'default', 'value' => 1],
                ['is_with_general_info', 'default', 'value' => 0],
                ['from_datetime', 'match', 'pattern' => $dateTimeRegexp],
                ['to_datetime', 'match', 'pattern' => $dateTimeRegexp],
                ['account_id', AccountIdValidator::class],
                ['number', UsageVoipValidator::class, 'account_id_field' => 'account_id', 'skipOnEmpty' => true],
                ['group_by', 'in', 'range' => ['', 'none', 'number', 'year', 'month', 'day', 'hour', '__country']],
            ]
        );

        if ($model->hasErrors()) {
            throw new ExceptionValidationForm($model);
        }

        if (($model->from_datetime || $model->to_datetime) && (!$model->from_datetime || !$model->to_datetime)) {
            throw new \InvalidArgumentException('fields from_datetime and to_datetime must be filled');
        }

        $clientAccount = ClientAccount::findOne(['id' => $model->account_id]);

        $utcTz = (new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
        $tz = $model->is_in_utc ? $utcTz : $clientAccount->timezone;


        if (!preg_match($dateTimeStrongRegexp, $model->from_datetime)) {
            $model->from_datetime .= ' 00:00:00';
        }

        if (!preg_match($dateTimeStrongRegexp, $model->to_datetime)) {
            $model->to_datetime = (new \DateTimeImmutable($model->to_datetime))
                ->modify('+1 day')
                ->setTime(0, 0, 0)
                ->format(DateTimeZoneHelper::DATETIME_FORMAT);
        }

        $firstDayOfDate = (new \DateTimeImmutable($model->from_datetime, $tz));
        $lastDayOfDate = (new \DateTimeImmutable($model->to_datetime, $tz));

        $diff = $firstDayOfDate->diff($lastDayOfDate);

        if ($diff->y > 0 || $diff->m > 2 || ($diff->m > 1 && $diff->d > 2)) {
            throw new \InvalidArgumentException('DATETIME_RANGE_LIMIT', -10);
        }

        $query = DataRaw::dao()->getData(
            $clientAccount,
            $model->number,
            $firstDayOfDate,
            $lastDayOfDate,
            $model->group_by
        );

        $generalInfo = [];
        if ($model->is_with_general_info) {
            $sumQuery = clone $query;
            $sumQuery->select([
                'sum' => new Expression('SUM(-cost)::decimal(12,2)'),
                'count' => new Expression('COUNT(*)'),
                'quantity' => new Expression('SUM(quantity)')
            ]);
            $sumQuery->orderBy(null)->groupBy(null);

            $generalInfo = $sumQuery->one(DataRaw::getDb());
            $generalInfo['count'] = (int)$generalInfo['count'];
            $generalInfo['sum'] = (float)$generalInfo['sum'];
            $generalInfo['offset'] = $model->offset;
            $generalInfo['limit'] = $model->limit;
        }

        if ($model->offset) {
            $query->offset($model->offset);
        }

        $query->limit($model->limit);

        $result = [];
        foreach ($query->each(100, DataRaw::getDb()) as $data) {
            $data['cost'] = (double)$data['cost'];

            if (isset($data['rate'])) {
                $data['rate'] = (double)$data['rate'];
            }

            $result[] = $data;
        }

        return $model->is_with_general_info ? ['info' => $generalInfo, 'result' => $result] : $result;
    }


    /**
     * @SWG\Definition(
     *   definition="sms",
     *   type="object",
     *   required={"id", "setup_time", "src_number", "dst_number", "cost", "rate", "parts", "count"},
     *   @SWG\Property(property="id",type="integer",description="идентификатор"),
     *   @SWG\Property(property="setup_time",type="date",description="дата отправки SMS"),
     *   @SWG\Property(property="src_number",type="string",description="номер А"),
     *   @SWG\Property(property="dst_number",type="string",description="номер Б"),
     *   @SWG\Property(property="cost",type="number",description="стоимость"),
     *   @SWG\Property(property="rate",type="number",description="ставка"),
     *   @SWG\Property(property="parts",type="number",description="кол-во частй в SMS"),
     *   @SWG\Property(property="count",type="integer",description="кол-во SMS")
     * ),
     * @SWG\Post(
     *   tags={"Статистика"},
     *   path="/internal/voip/sms/",
     *   summary="SMS",
     *   operationId="SMS",
     *   @SWG\Parameter(name="account_id",type="integer",description="идентификатор лицевого счёта",in="formData",default=""),
     *   @SWG\Parameter(name="number",type="string",description="номер телефона",in="formData",default=""),
     *   @SWG\Parameter(name="from_datetime",type="string",description="Время начала (по TZ-клиента) дата или дата-время",in="formData",default=""),
     *   @SWG\Parameter(name="to_datetime",type="string",description="Время окончания (по TZ-клиента)  дата или дата-время",in="formData",default=""),
     *   @SWG\Parameter(name="is_in_utc",type="string",description="Дата в параметрах и данных в UTC, иначе в TZ клиента",in="formData",default="1"),
     *   @SWG\Parameter(name="is_with_general_info",type="string",description="Подказывать общую информацию",in="formData",default="0"),
     *   @SWG\Parameter(name="group_by",type="string",description="Групировать по",in="formData",default="none",enum={"none", "year", "month", "day", "hour"}),
     *   @SWG\Parameter(name="offset",type="integer",description="сдвиг в выборке записей",in="formData",default="0"),
     *   @SWG\Parameter(name="limit",type="integer",description="размер выборки",in="formData",maximum="10000",default="100"),
     *   @SWG\Response(
     *     response=200,
     *     description="данные о SMS",
     *     @SWG\Schema(
     *       type="array",
     *       @SWG\Items(
     *         ref="#/definitions/sms"
     *       )
     *     )
     *   ),
     *   @SWG\Response(
     *     response="default",
     *     description="Ошибки",
     *     @SWG\Schema(
     *       ref="#/definitions/error_result"
     *     )
     *   )
     * )
     * @throws \yii\base\InvalidConfigException
     */
    public function actionSms()
    {
        $requestData = $this->requestData;

        $searchModel = new SmsFilter();
        $searchModel->load($requestData, '') && $searchModel->validate();

        if ($searchModel->hasErrors()) {
            throw new ExceptionValidationForm($searchModel);
        }

        $query = $searchModel->search();

        $generalInfo = [];
        if ($searchModel->is_with_general_info) {
            $sumQuery = clone $query;
            $sumQuery->select([
                'sum' => new Expression('SUM(-cost)::decimal(12,2)'),
                'count' => new Expression('COUNT(*)')
            ]);
            $sumQuery->orderBy(null);

            $generalInfo = $sumQuery->one(DataRaw::getDb());
            $generalInfo['count'] = (int)$generalInfo['count'];
            $generalInfo['sum'] = (float)$generalInfo['sum'];
            $generalInfo['offset'] = $searchModel->offset;
            $generalInfo['limit'] = $searchModel->limit;
        }

        if ($searchModel->offset) {
            $query->offset($searchModel->offset);
        }

        $searchModel->limit && $query->limit($searchModel->limit);

        $result = [];
        foreach ($query->each(100, SmscRaw::getDb()) as $data) {
            $data['cost'] = abs((double)$data['cost']);

            if (isset($data['rate'])) {
                $data['rate'] = abs((double)$data['rate']);
            }

            $result[] = $data;
        }

        return $searchModel->is_with_general_info ? ['info' => $generalInfo, 'result' => $result] : $result;
    }


    /**
     * @SWG\Definition(definition = "voipSourceRecord", type = "object",
     *   @SWG\Property(property = "code", type = "integer", description = "Код источника"),
     *   @SWG\Property(property = "name", type = "string", description = "Название"),
     *   @SWG\Property(property = "is_service", type = "string", description = "Служебный?"),
     * ),
     *
     * @SWG\Get(tags = {"Dictionaries"}, path = "/internal/voip/get-sources", summary = "Получение источников номера",
     *   @SWG\Parameter(name  =  "is_service", type="integer", description="Служебный?", in="query", default="0"),
     *
     *   @SWG\Response(response = 200, description = "Список источников номера", @SWG\Schema(type = "array", @SWG\Items(ref = "#/definitions/voipSourceRecord"))),
     *   @SWG\Response(response = "default", description = "Ошибки", @SWG\Schema(ref = "#/definitions/error_result"))
     * )
     *
     * @param int $country_id
     * @param int $client_account_id
     * @return array
     * @throws \InvalidArgumentException
     */

    public function actionGetSources($is_service = null)
    {
        return Source::getList(false, false, $is_service);
    }


    /**
     * @SWG\Get(tags = {"Numbers"}, path = "/internal/voip/get-numbers-in-status-not-verfied/", summary = "Получение номеров в статусе 'не верифицирован'", operationId = "get-numbers-in-status-not-verfied",
     *   @SWG\Response(response = 200, description = "Получение информации о номере",
     *   )
     * )
     */

    public function actionGetNumbersInStatusNotVerfied()
    {
        return Number::find()
            ->select('number')
            ->where([
                'status' => Number::STATUS_NOT_VERFIED,
                'is_verified' => 0
            ])->column();
    }


    /**
     * @SWG\Post(tags = {"Numbers"}, path = "/internal/voip/set-number-as-verfied/", summary = "Установка статуса у номера, что верифиукация пройдена", operationId = "set-number-as-verfied",
     *   @SWG\Parameter(name="number",type="string",description="номер телефона",in="formData",default=""),
     *   @SWG\Response(response = 200, description = "Получение информации о номере",
     *   )
     * )
     */

    public function actionSetNumberAsVerfied()
    {
        $requestData = Yii::$app->request->post() ?: [];

        $model = DynamicModel::validateData(
            $requestData,
            [
                ['number', 'string'],
                ['number', 'trim'],
                ['number', 'required'],
            ]
        );

        if ($model->hasErrors()) {
            $errors = $model->getFirstErrors();
            throw new \InvalidArgumentException(reset($errors));
        }

        $number = Number::findOne(['number' => $model['number']]);

        if (!$number) {
            throw new InvalidParamException('Number not found');
        }

        if ($number->is_verified === 0) {
            $number->is_verified = 1;

            $transaction = Number::getDb()->beginTransaction();
            try {

                $inFuture = false;
                if ($accountTariff = Number::dao()->getActiveAccountTariffByNumber($number, $inFuture)) {
                    $accountTariff->is_verified = $number->is_verified;
                }

                if (!$number->save()) {
                    throw new ModelValidationException($number);
                }

                if (!$accountTariff->save()) {
                    throw new ModelValidationException($number);
                }

                Number::dao()->actualizeStatus($number);
                $transaction->commit();
            } catch (\Exception $e) {
                Yii::error($e);
                $transaction->rollBack();
                throw $e;
            }
            $number->refresh();
        }

        return ['current_status' => $number->status];
    }

    /**
     * @SWG\Post(tags = {"Numbers"}, path = "/internal/voip/set-number-to-verfied/", summary = "Установка статуса у номера, что необходима верефикация", operationId = "set-number-to-verfied",
     *   @SWG\Parameter(name="number",type="string",description="номер телефона",in="formData",default=""),
     *   @SWG\Response(response = 200, description = "Получение информации о номере",
     *   )
     * )
     */
    public function actionSetNumberToVerfied()
    {
        $requestData = Yii::$app->request->post() ?: [];

        $model = DynamicModel::validateData(
            $requestData,
            [
                ['number', 'string'],
                ['number', 'trim'],
                ['number', 'required'],
            ]
        );

        if ($model->hasErrors()) {
            $errors = $model->getFirstErrors();
            throw new \InvalidArgumentException(reset($errors));
        }

        $number = Number::findOne(['number' => $model['number']]);

        if (!$number) {
            throw new InvalidParamException('Number not found');
        }

        if (!in_array($number->status, Number::$statusGroup[Number::STATUS_GROUP_ACTIVE])) {
            throw new \InvalidArgumentException('Number not in active status');
        }

        if ($number->status == Number::STATUS_NOT_VERFIED) {
            return ['current_status' => $number->status];
        }

        $transaction = Number::getDb()->beginTransaction();
        try {
            $number->is_verified = 0;

            $inFuture = false;
            if ($accountTariff = Number::dao()->getActiveAccountTariffByNumber($number, $inFuture)) {
                $accountTariff->is_verified = $number->is_verified;
            }

            if (!$number->save()) {
                throw new ModelValidationException($number);
            }

            if (!$accountTariff->save()) {
                throw new ModelValidationException($number);
            }

            Number::dao()->actualizeStatus($number);
            $transaction->commit();
        } catch (\Exception $e) {
            Yii::error($e);
            $transaction->rollBack();
            throw $e;
        }

        $number->refresh();

        return ['current_status' => $number->status];
    }


    /**
     * @SWG\Get(tags = {"Numbers"}, path = "/internal/voip/get-worked-numbers/", summary = "Получение списка работающих услуг/номеров", operationId = "get-worked-numbers",
     *   @SWG\Response(response = 200, description = "Получение информации о номере/услуге",
     *   )
     * )
     */

    public function actionGetWorkedNumbers()
    {
        return AccountTariff::find()
            ->select(['voip_number', 'client_account_id', 'id'])
            ->where([
                'NOT', ['tariff_period_id' => null],
            ])
            ->andWhere(['service_type_id' => ServiceType::ID_VOIP])
            ->asArray()
            ->all();
    }


    /**
     * @SWG\Post(tags = {"Numbers"}, path = "/internal/voip/set-number-status/",
     *   summary = "Установка статуса номера",
     *   operationId = "set-number-status",
     *   @SWG\Parameter(name="number", type="string", description="номер телефона", in="formData", required=true, default=""),
     *   @SWG\Parameter(name="status", type="string",
     *     description="статус номера: blocked_by_subscriber - заблокирован абонентом, blocked_by_operator - заблокирован оператором, not_verfied - не верифицирован, active или пустой - сброс статуса",
     *     in="formData",
     *     enum={"blocked_by_subscriber", "blocked_by_operator", "not_verfied", "active", ""}
     *   ),
     *   @SWG\Response(response = 200, description = "Результат операции")
     * )
     */
    public function actionSetNumberStatus()
    {
        $requestData = Yii::$app->request->post() ?: [];

        $model = DynamicModel::validateData(
            $requestData,
            [
                ['number', 'string'],
                ['number', 'trim'],
                ['number', 'required'],
                ['status', 'string'],
                ['status', 'in', 'range' => ['', 'active', 'blocked_by_subscriber', 'blocked_by_operator', 'not_verfied']],
            ]
        );

        if ($model->hasErrors()) {
            $errors = $model->getFirstErrors();
            throw new \InvalidArgumentException(reset($errors));
        }

        $lockKey = 'number_status_' . $model['number'];
        if (!Yii::$app->mutex->acquire($lockKey, self::DEFAULT_TIMEOUT)) {
            throw new \RuntimeException("Can't get number lock", 500);
        }

        try {
            $number = Number::findOne(['number' => $model['number']]);

            if (!$number) {
                throw new InvalidParamException('Number not found');
            }

            if (!in_array($number->status, Number::$statusGroup[Number::STATUS_GROUP_ACTIVE])) {
                throw new \InvalidArgumentException('Number not in active status');
            }

            $status = $model['status'] ?? '';

            // Определяем новые значения
            $newForcedStatus = null;
            $newIsVerified = $number->is_verified;

            switch ($status) {
                case Number::STATUS_BLOCKED_BY_SUBSCRIBER:
                    $newForcedStatus = Number::STATUS_BLOCKED_BY_SUBSCRIBER;
                    break;
                case Number::STATUS_BLOCKED_BY_OPERATOR:
                    $newForcedStatus = Number::STATUS_BLOCKED_BY_OPERATOR;
                    break;
                case Number::STATUS_NOT_VERFIED:
                    $newForcedStatus = Number::STATUS_NOT_VERFIED;
                    $newIsVerified = 0;
                    break;
                default:
                    $newIsVerified = 1;
                    break;
            }

            // Проверяем, изменились ли значения
            if ($number->forced_status === $newForcedStatus && $number->is_verified === $newIsVerified) {
                return [
                    'number' => $model['number'],
                    'current_status' => $number->status
                ];
            }

            $transaction = Number::getDb()->beginTransaction();
            try {
                $number->forced_status = $newForcedStatus;
                $number->is_verified = $newIsVerified;

                if (!$number->save()) {
                    throw new ModelValidationException($number);
                }

                Number::dao()->actualizeStatus($number);
                $transaction->commit();
            } catch (\Exception $e) {
                Yii::error($e);
                $transaction->rollBack();
                throw $e;
            }

            $number->refresh();

            return [
                'number' => $model['number'],
                'current_status' => $number->status
            ];
        } finally {
            Yii::$app->mutex->release($lockKey);
        }
    }


    /**
     * @SWG\Get(
     *   tags={"Статистика"},
     *   path="/internal/voip/ksim-start-generating-statstic/",
     *   summary="КСИМ. Запуск формирования статсистики.",
     *   operationId="КСИМ. Запуск формирования статсистики.",
     *   @SWG\Parameter(name="phone",type="integer",description="идентификатор телефонного номера",in="query",default=""),
     *   @SWG\Response(
     *     response=200,
     *     description="статистика",
     *   ),
     *   @SWG\Response(
     *     response="default",
     *     description="Ошибки",
     *     @SWG\Schema(
     *       ref="#/definitions/error_result"
     *     )
     *   )
     * )
     * @throws \yii\base\InvalidConfigException
     */
    public function actionKsimStartGeneratingStatstic($phone)
    {
        if (!$phone) {
            throw new \InvalidArgumentException('Phone number not set');
        }

        $number = Number::findOne(['number' => $phone]);

        if (!$number) {
            throw new \InvalidArgumentException('Phone number not found');
        }

        $event = EventQueue::go(EventQueue::KSIM_GET_STATISTIC, ['number' => $phone]);

        return ['request_id' => $event->id];
    }

    /**
     * @SWG\Get(
     *   tags={"Статистика"},
     *   path="/internal/voip/ksim-get-statstic/",
     *   summary="КСИМ. Запуск формирования статсистики.",
     *   operationId="КСИМ. Запуск формирования статсистики.",
     *   @SWG\Parameter(name="request_id",type="integer",description="идентификатор запроса запуска статистики",in="query",default=""),
     *   @SWG\Response(
     *     response=200,
     *     description="статистика",
     *   ),
     *   @SWG\Response(
     *     response="default",
     *     description="Ошибки",
     *     @SWG\Schema(
     *       ref="#/definitions/error_result"
     *     )
     *   )
     * )
     * @throws \yii\base\InvalidConfigException
     */
    public function actionKsimGetStatstic($request_id)
    {
        $event = EventQueue::findOne(['id' => $request_id, 'event' => EventQueue::KSIM_GET_STATISTIC]);

        if (!$event) {
            throw new \InvalidArgumentException('Data by request id #' . $request_id . ' not found');
        }

        if ($event->status != EventQueue::STATUS_OK) {
            throw new \InvalidArgumentException('request id #' . $request_id . ' not ready');
        }

        return ['result' => $event->trace];
    }

}
