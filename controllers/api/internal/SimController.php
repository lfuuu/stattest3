<?php

namespace app\controllers\api\internal;

use app\classes\ApiInternalController;
use app\exceptions\ModelValidationException;
use app\exceptions\web\NotImplementedHttpException;
use app\models\EventQueue;
use app\models\Number;
use app\modules\sim\models\Card;
use app\modules\sim\models\CardStatus;
use app\modules\sim\models\Imsi;
use app\modules\sim\models\ImsiStatus;
use app\modules\sim\models\RegionSettings;
use app\modules\uu\behaviors\AccountTariffCheckHlr;
use app\modules\uu\models\AccountTariff;
use Exception;
use Yii;
use yii\base\InvalidParamException;
use yii\db\Expression;
use yii\web\Response;

class SimController extends ApiInternalController
{
    const DEFAULT_LIMIT = 50;
    const MAX_LIMIT = 100;
    const EDIT_CARD_ERROR_CODE_WRONG_CARD = 1;
    const EDIT_CARD_ERROR_CODE_WRONG_IMSI = 2;
    const EDIT_CARD_ERROR_CODE_NUMBER_NOT_FOUND = 3;
    const EDIT_CARD_ERROR_CODE_WRONG_REGION = 4;
    const EDIT_CARD_ERROR_CODE_NUMBER_NOT_MCN = 5;
    const EDIT_CARD_ERROR_CODE_NUMBER_NO_DATA = 6;
    const EDIT_CARD_ERROR_CODE_NUMBER_OCCUPIED = 7;

    use IdNameRecordTrait;

    /**
     * @throws NotImplementedHttpException
     */
    public function actionIndex()
    {
        throw new NotImplementedHttpException;
    }

    /**
     * @SWG\Get(tags = {"SIM-card"}, path = "/internal/sim/get-card-statuses", summary = "Список статусов SIM-карт", operationId = "GetCardStatuses",
     *
     *   @SWG\Response(response = 200, description = "Список статусов SIM-карт",
     *     @SWG\Schema(type = "array", @SWG\Items(ref = "#/definitions/idNameRecord"))
     *   ),
     *   @SWG\Response(response = "default", description = "Ошибки",
     *     @SWG\Schema(ref = "#/definitions/error_result")
     *   )
     * )
     *
     * @return array
     */
    public function actionGetCardStatuses()
    {
        $query = CardStatus::find();
        $result = [];
        foreach ($query->each() as $model) {
            $result[] = $this->_getCardStatusRecord($model);
        }

        return $result;
    }

    /**
     * @SWG\Get(tags = {"SIM-card"}, path = "/internal/sim/get-imsi-statuses", summary = "Список статусов IMSI", operationId = "GetImsiStatuses",
     *
     *   @SWG\Response(response = 200, description = "Список статусов IMSI",
     *     @SWG\Schema(type = "array", @SWG\Items(ref = "#/definitions/idNameRecord"))
     *   ),
     *   @SWG\Response(response = "default", description = "Ошибки",
     *     @SWG\Schema(ref = "#/definitions/error_result")
     *   )
     * )
     *
     * @return array
     */
    public function actionGetImsiStatuses()
    {
        $query = ImsiStatus::find();
        $result = [];
        foreach ($query->each() as $model) {
            $result[] = $this->_getIdNameRecord($model);
        }

        return $result;
    }

    /**
     * @SWG\Definition(definition = "simCardRecord", type = "object",
     *   @SWG\Property(property = "iccid", type = "integer", description = "ICCID"),
     *   @SWG\Property(property = "imei", type = "integer", description = "IMEI"),
     *   @SWG\Property(property = "is_active", type = "integer", description = "Вкл."),
     *   @SWG\Property(property = "status", type = "object", description = "Статус", ref = "#/definitions/idNameRecord"),
     *   @SWG\Property(property = "imsies", type = "array", description = "Массив IMSI", @SWG\Items(ref = "#/definitions/simImsiRecord"))
     * ),
     *
     * @SWG\Definition(definition = "simImsiRecord", type = "object",
     *   @SWG\Property(property = "imsi", type = "integer", description = "IMSI"),
     *   @SWG\Property(property = "msisdn", type = "integer", description = "MSISDN"),
     *   @SWG\Property(property = "did", type = "integer", description = "DID"),
     *   @SWG\Property(property = "is_anti_cli", type = "integer", description = "Анти-АОН"),
     *   @SWG\Property(property = "is_roaming", type = "integer", description = "Роуминг"),
     *   @SWG\Property(property = "is_active", type = "integer", description = "Вкл."),
     *   @SWG\Property(property = "is_default", type = "integer", description = "По-умолчанию"),
     *   @SWG\Property(property = "status", type = "object", description = "Статус", ref = "#/definitions/idNameRecord"),
     *   @SWG\Property(property = "profile", type = "object", description = "Статус", ref = "#/definitions/idNameRecord"),
     *   @SWG\Property(property = "actual_from", type = "string", description = "Действует с")
     * ),
     *
     * @SWG\Get(tags = {"SIM-card"}, path = "/internal/sim/get-cards", summary = "Список SIM-карт ЛС", operationId = "GetCards",
     *   @SWG\Parameter(name = "client_account_id", type = "integer", description = "ID ЛС", in = "query", required = true, default = ""),
     *   @SWG\Parameter(name = "iccid", type = "string", description = "ICCID", in = "query", required = false, default = ""),
     *   @SWG\Parameter(name = "like_iccid", type = "string", description = "like ICCID", in = "query", required = false, default = ""),
     *   @SWG\Parameter(name = "limit", type = "integer", description = "query limit", in = "query", required = false, default = ""),
     *   @SWG\Parameter(name = "offset", type = "integer", description = "query offset", in = "query", required = false, default = ""),
     *
     *   @SWG\Response(response = 200, description = "Список SIM-карт ЛС",
     *     @SWG\Schema(type = "array", @SWG\Items(ref = "#/definitions/simCardRecord"))
     *   ),
     *   @SWG\Response(response = "default", description = "Ошибки",
     *     @SWG\Schema(ref = "#/definitions/error_result")
     *   )
     * )
     *
     * @param int $client_account_id
     * @return array
     */
    public function actionGetCards(
        $client_account_id,
        $iccid = null,
        $like_iccid = null,
        $limit = null,
        $offset = null
    )
    {
        $query = $this->_getCardsQuery($client_account_id, $iccid, $like_iccid, $limit, $offset);
        $query->orderBy(['iccid' => SORT_ASC]);

        $result = [];
        foreach ($query->each() as $model) {
            $result[] = $this->simCardRecord($model);
        }

        return $result;
    }

    /**
     * @SWG\Definition(definition = "simCardRecordCount", type = "object",
     *   @SWG\Property(property = "count", type = "integer", description = "кол-во SIM-карт"),
     * ),
     *
     * @SWG\Get(tags = {"SIM-card"}, path = "/internal/sim/get-cards-count", summary = "Кол-во SIM-карт ЛС", operationId = "GetCardsCount",
     *   @SWG\Parameter(name = "client_account_id", type = "integer", description = "ID ЛС", in = "query", required = true, default = ""),
     *   @SWG\Parameter(name = "iccid", type = "string", description = "ICCID", in = "query", required = false, default = ""),
     *   @SWG\Parameter(name = "like_iccid", type = "string", description = "like ICCID", in = "query", required = false, default = ""),
     *   @SWG\Parameter(name = "limit", type = "integer", description = "query limit", in = "query", required = false, default = ""),
     *   @SWG\Parameter(name = "offset", type = "integer", description = "query offset", in = "query", required = false, default = ""),
     *
     *   @SWG\Response(response = 200, description = "Кол-во SIM-карт ЛС",
     *     @SWG\Schema(type = "array", @SWG\Items(ref = "#/definitions/simCardRecordCount"))
     *   ),
     *   @SWG\Response(response = "default", description = "Ошибки",
     *     @SWG\Schema(ref = "#/definitions/error_result")
     *   )
     * )
     *
     * @param int $client_account_id
     * @return array
     */
    public function actionGetCardsCount(
        $client_account_id,
        $iccid = null,
        $like_iccid = null,
        $limit = null,
        $offset = null
    )
    {
        $query = $this->_getCardsQuery(
            $client_account_id,
            $iccid,
            $like_iccid,
            $limit,
            $offset
        );

        return ['count' => $query->count()];
    }

    /**
     * @param $client_account_id
     * @param $iccid
     * @param $like_iccid
     * @param $limit
     * @param $offset
     * @return \yii\db\ActiveQuery
     */
    private function _getCardsQuery($client_account_id, $iccid, $like_iccid, $limit, $offset): \yii\db\ActiveQuery
    {
        $query = Card::find()
            ->where(['client_account_id' => $client_account_id])
            ->with('status', 'imsies', 'imsies.profile', 'imsies.status');

        $iccid && $query->andWhere(['iccid' => $iccid]);
        if ($like_iccid) {
            $like_iccid = preg_replace('/\D+/', '', $like_iccid);
            if ($like_iccid) {
                $query->andWhere(new Expression('iccid::text like :exp', ['exp' => '%' . $like_iccid . '%']));
            }
        }
        is_numeric($limit) && $limit >= 0 && $query->limit($limit);
        is_numeric($offset) && $offset >= 0 && $query->offset($offset);

        return $query;
    }

    /**
     * @param Card $card
     * @return array
     */
    protected function simCardRecord(Card $card)
    {
        return [
            'iccid' => (string)$card->iccid,
            'imei' => (string)$card->imei,
            'is_active' => $card->is_active,
            'region' => $this->regionRecord($card->region_id),
            'status' => $this->_getCardStatusRecord($card->status),
            'imsies' => $this->simImsiesRecord($card->imsies),
            'sim_type' => $this->_getIdNameRecord($card->type),
        ];
    }

    /**
     * @param Imsi[] $imsies
     * @return array
     */
    protected function simImsiesRecord($imsies)
    {
        $records = [];
        foreach ($imsies as $imsi) {
            $records[] = [
                'imsi' => (string)$imsi->imsi,
                'msisdn' => (string)$imsi->msisdn,
                'did' => (string)$imsi->did,
                'is_anti_cli' => $imsi->is_anti_cli,
                'is_roaming' => $imsi->is_roaming,
                'is_active' => $imsi->is_active,
                'is_default' => $imsi->is_default,
                'status' => $this->_getIdNameRecord($imsi->status),
                'profile' => $this->_getIdNameRecord($imsi->profile),
                'actual_from' => $imsi->actual_from,
            ];
        }

        return $records;
    }

    /**
     * @param int $regionId
     * @return array
     */
    protected function regionRecord($regionId)
    {
        $record = [];

        if ($regionSettings = RegionSettings::findByRegionId($regionId)) {
            $record = [
                'id' => $regionSettings->region_id,
                'name' => $regionSettings->getRegionFullName(),
            ];
        }

        return $record;
    }

    /**
     * @param ?CardStatus $model
     * @return array
     */
    protected function _getCardStatusRecord(?CardStatus $model): array
    {
        return [
            'id' => $model->id,
            'name' => (string)$model->name,
            'is_virtual' => $model->is_virtual,
        ];
    }

    /**
     * @SWG\Put(tags = {"SIM-card"}, path = "/internal/sim/edit-card", summary = "Редактировать SIM-карту", operationId = "EditSimCard",
     *   @SWG\Parameter(name = "client_account_id", type = "integer", description = "ID ЛС", in = "query", required = true, default = ""),
     *   @SWG\Parameter(name = "iccid", type = "integer", description = "ICCID", in = "query", required = true, default = ""),
     *   @SWG\Parameter(name = "imsi", type = "integer", description = "IMSI", in = "query", required = true, default = ""),
     *
     *   @SWG\Parameter(name = "did", type = "integer", description = "Новое значение DID (пустое значегние - NULL)", in = "formData", default = ""),
     *   @SWG\Parameter(name = "msisdn", type = "integer", description = "Новое значение MSISDN (пустое значегние - NULL)", in = "formData", default = ""),
     *   @SWG\Parameter(name = "is_anti_cli", type = "integer", description = "Новое значение Анти-АОН", in = "formData", default = ""),
     *   @SWG\Parameter(name = "is_roaming", type = "integer", description = "Новое значение Роуминг", in = "formData", default = ""),
     *   @SWG\Parameter(name = "is_active", type = "integer", description = "Новое значение Вкл.", in = "formData", default = ""),
     *   @SWG\Parameter(name = "is_default", type = "integer", description = "По-умолчанию", in = "formData", default = ""),
     *
     *   @SWG\Response(response = 200, description = "SIM-карта отредактирована",
     *     @SWG\Schema(type = "boolean", description = "true - успешно")
     *   ),
     *   @SWG\Response(response = "default", description = "Ошибки",
     *     @SWG\Schema(ref = "#/definitions/error_result")
     *   )
     * )
     *
     * @param int $client_account_id
     * @param int $iccid
     * @param int $imsi
     * @return mixed
     * @throws \Exception
     */
    public function actionEditCard($client_account_id, $iccid, $imsi)
    {
        $card = Card::findOne(['iccid' => $iccid, 'client_account_id' => $client_account_id]);
        if (!$card) {
            throw new \InvalidArgumentException('Не найдена карта по iccid и client_account_id', self::EDIT_CARD_ERROR_CODE_WRONG_CARD);
        }

        $lockKey = "sim_card_edit_" . $card->iccid;
        if (!\Yii::$app->mutex->acquire($lockKey, self::DEFAULT_TIMEOUT)) {
            throw new \RuntimeException("Can't get carf lock", 500);
        }


        $imsies = $card->imsies;
        if (!isset($imsies[$imsi])) {
            throw new \InvalidArgumentException('Неправильные параметры imsi - нет такой imsi для данного iccid', self::EDIT_CARD_ERROR_CODE_WRONG_IMSI);
        }

        $post = Yii::$app->request->post();
        $post = array_map(function ($value) {
            return
                $value === 'NULL' || $value === '' ?
                    NULL :
                    (is_bool($value) ? (int)$value : $value);
        }, $post);

        /** @var ?Imsi $imsiExists */
        $imsiExists = null;
        $imsiObject = $imsies[$imsi];
        $msisdn = $post['msisdn'];
        $msisdnFromImsi = $imsiObject->msisdn;

        if ($msisdn == $msisdnFromImsi) {
            \Yii::$app->mutex->release($lockKey);
            return true;
        }

        if (!empty($msisdn)) {
            $number = Number::findOne(['number' => $msisdn]);
            if (!$number) {
                throw new \InvalidArgumentException('Неверный msisdn, не найден в voip_numbers', self::EDIT_CARD_ERROR_CODE_NUMBER_NOT_FOUND);
            }

            if (!RegionSettings::checkIfRegionsEqual($number->region, $card->region_id)) {
                throw new \InvalidArgumentException('Регион номера и регион SIM-карты не совместимы', self::EDIT_CARD_ERROR_CODE_WRONG_REGION);
            }

            try {
                $isMcnNumber = $number->isMcnNumber();
            } catch (Exception $e) {
                throw new \InvalidArgumentException('Не удалось получить данные по номеру', self::EDIT_CARD_ERROR_CODE_NUMBER_NO_DATA);
            }

            if (!$isMcnNumber) {
                // временно отключаем, так как данные о переезде к нам приходят с запозданием из БДПН (первоисточник)
                //throw new InvalidParamException('Выбранный номер не принадлежит МСН Телеком', self::EDIT_CARD_ERROR_CODE_NUMBER_NOT_MCN);
            }

            $imsiExists = Imsi::find()
                ->andWhere(['msisdn' => $msisdn])
                ->andWhere(['not', ['iccid' => $iccid]])
                ->one();

            if ($imsiExists && !$imsiExists->card->status->is_virtual) {
                throw new \InvalidArgumentException('Данный номер уже связан с другой SIM картой', self::EDIT_CARD_ERROR_CODE_NUMBER_OCCUPIED);
            }
        }


        $transaction = Yii::$app->db->beginTransaction();
        $transactionSim = Card::getDb()->beginTransaction();
        try {

            // выключаем виртуальную карту на которой сейчас номер
            if ($imsiExists && $imsiExists->card->status->is_virtual) {
                array_walk($imsiExists->card->imsies, function(Imsi $imsi) {
                    $imsi->msisdn = '';
                    if (!$imsi->save()) {
                        throw new ModelValidationException($imsi);
                    }
                });

                $imsiExists->card->client_account_id = null;
                if (!$imsiExists->card->save()) {
                    throw new ModelValidationException($imsiExists->card);
                }
            }

            if ($msisdn) { // set
                $accountTariff = AccountTariff::find()
                    ->where(['voip_number' => $msisdn ?: -1 ])
                    ->andWhere(['not', ['tariff_period_id' => null]])
                    ->one();
                if (!$accountTariff) {
                    throw new \InvalidArgumentException('Неверный msisdn, не найдена включенная услуга с этим номером', self::EDIT_CARD_ERROR_CODE_NUMBER_NOT_FOUND);
                }

                $iccid = AccountTariffCheckHlr::reservImsi([
                    'account_tariff_id' => $accountTariff->id,
                    'card' => $card,
                ]);
            } else if ($imsiObject->msisdn) { // unset
                array_walk($imsiObject->card->imsies, function(Imsi $imsi) {
                    $imsi->msisdn = '';
                    if (!$imsi->save()) {
                        throw new ModelValidationException($imsi);
                    }
                });
            }

            $imsiObject->refresh();

            if (!$msisdn && $msisdnFromImsi) {
                $accountTariff = AccountTariff::find()
                    ->where(['voip_number' => $msisdnFromImsi])
                    ->andWhere(['not', ['tariff_period_id' => null]])
                    ->one();
                if ($accountTariff) {
                    $imsiExists = Imsi::find()
                        ->andWhere(['msisdn' => $msisdnFromImsi])
                        ->one();
                    if (!$imsiExists) {
                        $iccid = AccountTariffCheckHlr::reservImsi([
                            'account_tariff_id' => $accountTariff->id,
                        ]);
                    }
                }
            }

            $transaction->commit();
            $transactionSim->commit();
            \Yii::$app->mutex->release($lockKey);

            return true;
        } catch (Exception $e) {
            $transactionSim->rollBack();

            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            \Yii::$app->mutex->release($lockKey);

            \Yii::error($e);
            throw $e;
        }
    }


    /**
     * @SWG\Get(tags = {"SIM-card"}, path = "/internal/sim/get-subscriber-status", summary = "Получить статус SIM-карты", operationId = "getSubscriberStatus",
     *   @SWG\Parameter(name = "imsi", type = "integer", description = "IMSI", in = "query", required = true, default = ""),
     *
     *   @SWG\Response(response = 200, description = "Статус SIM-карты",
     *     @SWG\Schema(type = "array", @SWG\Items(ref = "#/definitions/idNameRecord"))
     *   ),
     *   @SWG\Response(response = "default", description = "Ошибки",
     *     @SWG\Schema(ref = "#/definitions/error_result")
     *   )
     * )
     *
     * @return array
     */
    public function actionGetSubscriberStatus($imsi)
    {
        return Imsi::dao()->getSubscriberStatus($imsi);
    }

    private function _checkMsisdn($msisdn)
    {
        if (!$msisdn || !preg_match('/^7\d{10}/', $msisdn)) {
            throw new \InvalidArgumentException('bad MSISDN');
        }
    }


    /**
     * @SWG\Get(tags = {"SIM-card"}, path = "/internal/sim/add-call-forwarding-on-not-reachable", summary = "Установить переадресацию при недоступности", operationId = "addCallForwardingOnNotReachable",
     *   @SWG\Parameter(name = "imsi", type = "integer", description = "IMSI", in = "query", required = true, default = ""),
     *   @SWG\Parameter(name = "msisdn", type = "integer", description = "IMSI", in = "query", required = true, default = ""),
     *
     *   @SWG\Response(response = 200, description = "Статус SIM-карты",
     *     @SWG\Schema(type = "array", @SWG\Items(ref = "#/definitions/idNameRecord"))
     *   ),
     *   @SWG\Response(response = "default", description = "Ошибки",
     *     @SWG\Schema(ref = "#/definitions/error_result")
     *   )
     * )
     *
     * @return array
     */
    public function actionAddCallForwardingOnNotReachable($imsi, $msisdn)
    {
        $this->_checkImsi($imsi);
        $this->_checkMSISDN($msisdn);

        $event = EventQueue::go(EventQueue::SYNC_TELE2_SET_CFNRC, ['imsi' => $imsi, 'msisdn' => $msisdn]);

        $result = $this->_waitEvent($event);

        $json = json_decode($result, true);

        if ($json) {
            return $json;
        }

        throw new \BadMethodCallException('answer error: ' . var_export($result, true));
    }


    /**
     * @SWG\Get(tags = {"SIM-card"}, path = "/internal/sim/remove-call-forwarding-on-not-reachable", summary = "Снять переадресацию при недоступности", operationId = "removeCallForwardingOnNotReachable",
     *   @SWG\Parameter(name = "imsi", type = "integer", description = "IMSI", in = "query", required = true, default = ""),
     *
     *   @SWG\Response(response = 200, description = "Статус SIM-карты",
     *     @SWG\Schema(type = "array", @SWG\Items(ref = "#/definitions/idNameRecord"))
     *   ),
     *   @SWG\Response(response = "default", description = "Ошибки",
     *     @SWG\Schema(ref = "#/definitions/error_result")
     *   )
     * )
     *
     * @return array
     */
    public function actionRemoveCallForwardingOnNotReachable($imsi)
    {
        $this->_checkImsi($imsi);

        $event = EventQueue::go(EventQueue::SYNC_TELE2_UNSET_CFNRC, ['imsi' => $imsi]);

        $result = $this->_waitEvent($event);

        $json = json_decode($result, true);

        if ($json) {
            return $json;
        }

        throw new \BadMethodCallException('answer error: ' . var_export($result, true));
    }


    /**
     * @SWG\Get(tags = {"SIM-card"}, path = "/internal/sim/get-imsi-token-qrcode", summary = "Получение токена QR-кода токена IMSI", operationId = "GetImsiTokenQrCode",
     *   @SWG\Parameter(name = "imsi", type = "integer", description = "IMSI", in = "query", required = true, default = ""),
     *   @SWG\Parameter(name = "asImage", type = "integer", description = "as Image", in = "query", required = false, default = "0"),
     *
     *   @SWG\Response(response = 200, description = "Получение токена QR-кода токена IMSI",
     *     @SWG\Schema(type = "array", @SWG\Items(ref = "#/definitions/idNameRecord"))
     *   ),
     *   @SWG\Response(response = "default", description = "Ошибки",
     *     @SWG\Schema(ref = "#/definitions/error_result")
     *   )
     * )
     *
     * @return array
     */
    public function actionGetImsiTokenQrcode($imsi, $asImage = false)
    {
        /** @var Imsi $imsiModel */
        $imsiModel = Imsi::find()->where(['imsi' => $imsi])->one();

        if (!$imsiModel) {
            throw new \InvalidArgumentException('IMSI not found');
        }

        $imsiToken = $imsiModel->token;

        if (!$imsiToken) {
            throw new \InvalidArgumentException('IMSI token not found');
        }

        $data = $imsiToken->getTokenQrCode();

        if (!$asImage) {
            return $data;
        }

        // out as image
        \Yii::$app->response->content = base64_decode($data['image']);
        \Yii::$app->response->format = Response::FORMAT_RAW;
        \Yii::$app->response->headers->set('Content-Type', $data['mime_type']);
        \Yii::$app->response->send();

        \Yii::$app->end();
    }

    /**
     * @SWG\Definition(definition = "esimStockRecord", type = "object",
     *   @SWG\Property(property = "status_id", type = "integer", description = "ID статуса-склада"),
     *   @SWG\Property(property = "status_name", type = "string", description = "Название статуса-склада"),
     *   @SWG\Property(property = "total", type = "integer", description = "Всего карт на складе"),
     *   @SWG\Property(property = "free", type = "integer", description = "Свободных карт (без ЛС)")
     * ),
     *
     * @SWG\Get(tags = {"SIM-card"}, path = "/internal/sim/get-esim-stock", summary = "Остатки eSIM по складам", operationId = "GetEsimStock",
     *
     *   @SWG\Response(response = 200, description = "Остатки eSIM по складам",
     *     @SWG\Schema(type = "array", @SWG\Items(ref = "#/definitions/esimStockRecord"))
     *   ),
     *   @SWG\Response(response = "default", description = "Ошибки",
     *     @SWG\Schema(ref = "#/definitions/error_result")
     *   )
     * )
     *
     * @return array
     */
    public function actionGetEsimStock()
    {
        $cardTable = Card::tableName();
        $statusTable = CardStatus::tableName();

        $rows = Card::find()
            ->select([
                'status_id' => $statusTable . '.id',
                'status_name' => $statusTable . '.name',
                'total' => new Expression('COUNT(*)'),
                'free' => new Expression('COUNT(*) FILTER (WHERE ' . $cardTable . '.client_account_id IS NULL)'),
            ])
            ->innerJoin($statusTable, $statusTable . '.id = ' . $cardTable . '.status_id')
            ->andWhere(new Expression('LOWER(' . $statusTable . '.name) LIKE :prefix', [':prefix' => 'esim%']))
            ->groupBy([$statusTable . '.id', $statusTable . '.name'])
            ->orderBy([$statusTable . '.id' => SORT_ASC])
            ->asArray()
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'status_id' => (int)$row['status_id'],
                'status_name' => (string)$row['status_name'],
                'total' => (int)$row['total'],
                'free' => (int)$row['free'],
            ];
        }

        return $result;
    }
}
