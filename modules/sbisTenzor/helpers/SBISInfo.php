<?php

namespace app\modules\sbisTenzor\helpers;

use app\classes\Connection;
use app\exceptions\ModelValidationException;
use app\models\ClientAccount;
use app\models\ClientContragent;
use app\modules\sbisTenzor\exceptions\SBISTensorException;
use app\modules\sbisTenzor\models\SBISContractor;
use app\modules\sbisTenzor\classes\SBISTensorAPI;
use app\modules\sbisTenzor\models\SBISContractorExchange;
use app\modules\sbisTenzor\models\SBISExchangeGroup;
use app\modules\sbisTenzor\models\SBISOrganization;
use app\modules\sbisTenzor\Module;
use kartik\base\Config;
use yii\db\Expression;

class SBISInfo
{
    /**
     * Получить доступные группы обмена первичными документами
     *
     * @param ClientAccount $client
     * @param SBISOrganization|null $sbisOrganization
     * @return array
     */
    public static function getExchangeGroupsByClient(ClientAccount $client, SBISOrganization $sbisOrganization = null)
    {
        $sbisOrganization = $sbisOrganization ? : SBISDataProvider::getSBISOrganizationByClient($client);

        // список документов по организации
        $groupIds1 = [];
        switch ($sbisOrganization->id) {
            case SBISOrganization::ID_MCN_TELECOM:
            case SBISOrganization::ID_VOICE_CONNECT:
            case SBISOrganization::ID_AB_SERVICE_MARCOMNET:
                $groupIds1 = [
                    SBISExchangeGroup::UPD_2023,
                    SBISExchangeGroup::ACT_AND_INVOICE_2025,
                    SBISExchangeGroup::ACT,
                ];
                break;

            case SBISOrganization::ID_MCN_TELECOM_SERVICE:
                $groupIds1 = [
                    SBISExchangeGroup::ACT,
                ];
                break;
        }

        // список документов по форме регистрации
        $groupIds2 = [];
        switch ($client->contragent->legal_type) {
            case ClientContragent::LEGAL_TYPE:
            case ClientContragent::IP_TYPE:
                $groupIds2 = [
                    SBISExchangeGroup::ACT,
                    SBISExchangeGroup::ACT_AND_INVOICE_2016,
                    SBISExchangeGroup::UPD_2023,
                    SBISExchangeGroup::ACT_AND_INVOICE_2025,
                ];
                break;

            case ClientContragent::PERSON_TYPE:
                $groupIds2 = [
                    SBISExchangeGroup::ACT,
                    SBISExchangeGroup::UPD_2023,
                ];
                break;
        }

        return array_intersect($groupIds1, $groupIds2);
    }

    /**
     * Получить Идентификатор в ЭДО
     *
     * @param ClientAccount $client
     * @param bool $isForce
     * @return string|null
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     * @throws \yii\web\BadRequestHttpException
     * @throws \Exception
     */
    public static function getExchangeIntegrationId(ClientAccount $client, $isForce = false)
    {
        return self::getPreparedContractor($client, $isForce)->getEdfId();
    }

    /**
     * Получить данные по контрагенту
     *
     * @param ClientAccount $client
     * @param bool $isForce
     * @return SBISContractor
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     * @throws \yii\web\BadRequestHttpException
     * @throws \Exception
     */
    public static function getPreparedContractor(ClientAccount $client, $isForce = false)
    {
        $sbisContractor = SBISDataProvider::getSBISContractor($client);
        if (!$sbisContractor) {
            $isForce = true;
            $sbisContractor = new SBISContractor();
            $sbisContractor->is_roaming = false;
        }

        if ($isForce) {
            $sbisContractor->account_id = $client->id;
            $sbisContractor->addAccountId($client->id);

            /** @var Module $module */
            $module = Config::getModule('sbisTenzor');
            if ($params = $module->getParams()) {
                $sbisOrganization = array_shift($params);
                $api = new SBISTensorAPI($sbisOrganization);

                $result = $api->getContractorInfo($client);
                self::prepareAndSaveContractor($sbisContractor, $result);
            }
        }

        return $sbisContractor;
    }

    /**
     * Сохранить запись о контрагенте
     *
     * @param SBISContractor $sbisContractor
     * @param array $result
     * @throws \Exception
     */
    protected static function prepareAndSaveContractor(SBISContractor $sbisContractor, $result)
    {
        if (!array_key_exists('Идентификатор', $result)) {
            return;
        }

        $fieldsMap = [
//            'exchange_id' => 'Идентификатор',
            'exchange_id_is' => 'ИдентификаторИС',
            'exchange_id_spp' => 'ИдентификаторСПП',
            'email' => 'Email',
            'phone' => 'Телефон',
        ];
        foreach ($fieldsMap as $field => $property) {
            if (!empty($result[$property])) {
                $sbisContractor->$field = $result[$property];
            }
        }
//        $sbisContractor->exchange_id = $sbisContractor->getMainExchangeId($result);
        $sbisContractor->is_private = '0';

        if (!empty($result['СвЮЛ'])) {
            $sbisContractor->tin = $result['СвЮЛ']['ИНН'];
            $sbisContractor->iec = $result['СвЮЛ']['КПП'];
            $sbisContractor->country_code = $result['СвЮЛ']['КодСтраны'];
            $sbisContractor->full_name = $result['СвЮЛ']['Название'];
            if (!empty($result['СвЮЛ']['КодФилиала'])) {
                $sbisContractor->branch_code = $result['СвЮЛ']['КодФилиала'];
            }
        }

        if (!empty($result['СвФЛ'])) {
            $sbisContractor->itn = $result['СвФЛ']['ИНН'];
            $sbisContractor->inila = $result['СвФЛ']['СНИЛС'];
            $sbisContractor->last_name = $result['СвФЛ']['Фамилия'];
            $sbisContractor->first_name = $result['СвФЛ']['Имя'];
            $sbisContractor->middle_name = $result['СвФЛ']['Отчество'];
            $sbisContractor->is_private = ($result['СвФЛ']['ЧастноеЛицо'] === 'Да' ? 1 : 0);

            $sbisContractor->full_name =
                ($sbisContractor->is_private ? '' : 'ИП ') .
                implode(' ', [
                    $result['СвФЛ']['Фамилия'],
                    $result['СвФЛ']['Имя'],
                    $result['СвФЛ']['Отчество'],
                ]);
        }

        if (!$sbisContractor->save()) {
            throw new ModelValidationException($sbisContractor);
        }

        self::saveContractorExchanges($sbisContractor, $result);

    }

    private static function saveContractorExchanges(SBISContractor $contractor, $result)
    {
        \Yii::$app->db->transaction(function (Connection $db) use ($contractor, $result) {

            $resultExchanges = [];
            foreach ($result['Идентификатор'] as $row) {
                $resultExchanges[$row['ИдентификаторУчастника']] = [
                    'contractor_id' => $contractor->id,
                    'exchange_id' => $row['ИдентификаторУчастника'],
                    'operator_name' => $row['Оператор']['Название'],
                    'is_main' => (int)($row['Основной'] == 'Да'),
                    'is_roaming' => (int)($row['Роуминг'] == 'Да'),
                    'exchange_state_code' => $row['СостояниеПодключения']['Код'],
                    'exchange_state_code_description' => $row['СостояниеПодключения']['Описание'],
                    'is_deleted' => 0,
                    'deleted_at' => null,
                ];
            }

            $contractorExchanges = SBISContractorExchange::find()->where(['contractor_id' => $contractor->id])->indexBy('exchange_id')->asArray()->all();

            $toAdd = array_diff_assoc($resultExchanges, $contractorExchanges);
            $toDel = array_diff_assoc($contractorExchanges, $resultExchanges);

            $toDel = array_filter($toDel, fn($row) => !(bool)(int)$row['is_deleted']);
            $delIds = array_map(fn($row) => $row['id'], $toDel);

            array_walk($delIds, function ($id) {
                $ex = SBISContractorExchange::find()->where(['id' => $id])->one();
                if (!$ex) {
                    return;
                }

                $ex->is_deleted = 1;
                $ex->deleted_at = new Expression('UTC_TIMESTAMP()');
                if (!$ex->save()) {
                    throw new ModelValidationException($ex);
                }
            });

            if ($toAdd) {
                array_walk($toAdd, function ($row) {
                    $ex = new SBISContractorExchange;
                    $ex->setAttributes($row);
                    if (!$ex->save()) {
                        throw new ModelValidationException($ex);
                    }
                });
            }

            $intersected = array_intersect_assoc($contractorExchanges, $resultExchanges);
            foreach ($intersected as $exchangeId => $contractorEx) {
                $resultContractorEx = $resultExchanges[$exchangeId];


                $diff = array_diff_assoc($resultContractorEx, $contractorEx);
                if (!$diff) {
                    continue;
                }

                $ex = SBISContractorExchange::find()->where(['id' => $contractorEx['id']])->one();
                if (!$ex) {
                    continue;
                }

                $ex->setAttributes($diff);
                if (!$ex->save()) {
                    throw new ModelValidationException($ex);
                }
            }
        });
    }
}
