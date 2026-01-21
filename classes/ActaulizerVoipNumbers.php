<?php

namespace app\classes;

use app\classes\api\ApiPhone;
use app\models\ActualNumber;
use app\models\ClientAccount;
use app\models\EventQueue;
use app\models\UsageVoip;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\ResourceModel;
use app\modules\uu\models\ServiceType;

/**
 * Class ActaulizerVoipNumbers
 *
 * @method static ActaulizerVoipNumbers me($args = null)
 */
class ActaulizerVoipNumbers extends Singleton
{

    private $_phoneApi = null;

    /**
     * Получение объекта доступа к Api телфонии
     *
     * @return ApiPhone
     */
    private function _getPhoneApi()
    {
        if (!$this->_phoneApi) {
            $this->setPhoneApi(ApiPhone::me());
        }

        return $this->_phoneApi;
    }

    /**
     * Установка объекта доступа к Api телефонии
     *
     * @param ApiPhone $api
     */
    public function setPhoneApi(ApiPhone $api)
    {
        if (!$this->_phoneApi) {
            $this->_phoneApi = $api;
        }
    }

    /**
     * @param string $number
     * @param int $accountTariffId
     */
    public function actualizeByNumber($number, $accountTariffId = null)
    {
        if ($this->_check7800($number)) {
            return true;
        }

        return $this->_checkSync($number, null, $accountTariffId);
    }

    /**
     * @param int $clientId
     */
    public function actualizeByClientId($clientId)
    {
        $this->_checkSync(null, $clientId);
    }

    /**
     * Актуализировать всё
     */
    public function actualizeAll()
    {
        $this->_checkSync();
    }

    /**
     * @param string $number
     * @param int $clientId
     * @param int $accountTariffId
     */
    private function _checkSync($number = null, $clientId = null, $accountTariffId = null)
    {
        if (
        $diff = $this->_checkDiff(
            ActualNumber::dao()->loadSaved($number, $clientId),
            ActualNumber::dao()->collectFromUsages($number, $clientId)
        )
        ) {
            $this->_diffToSync($diff, $accountTariffId);
        }

    }

    /**
     * @param string $number
     * @throws \Exception
     */
    public function sync($number = null)
    {
        if (!$number) {
            return;
        }

        if (
        $diff = $this->_diff(
            ActualNumber::dao()->loadSaved($number),
            ActualNumber::dao()->collectFromUsages($number)
        )
        ) {
            $transaction = ActualNumber::getDb()->beginTransaction();
            try {

                $this->_diffApply($diff);

                $transaction->commit();
            } catch (\Exception $e) {
                $transaction->rollBack();
                throw $e;
            }
        }
    }

    /**
     * @param string $number
     * @return bool
     */
    private function _check7800($number)
    {
        // вместо номера 7800 мы синхронизируем ассоциированную линию
        if ($this->_is7800($number)) {
            $n = UsageVoip::find()->phone($number)->actual()->one();
            if ($n && $n->line7800_id) {
                $line = UsageVoip::findOne(['id' => $n->line7800_id]);

                if ($line) {
                    EventQueue::go(EventQueue::ACTUALIZE_NUMBER, ['number' => $line->E164]);
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array $saved
     * @param array $actual
     * @return array
     */
    private function _checkDiff($saved, $actual)
    {
        $d = [];

        foreach (array_diff(array_keys($saved), array_keys($actual)) as $l) {
            $d[$l] = ['action' => 'del'] + $saved[$l];
        }

        foreach (array_diff(array_keys($actual), array_keys($saved)) as $l) {
            $d[$l] = ['action' => 'add'] + $actual[$l];
        }


        foreach ([
                     'client_id',
                     'region',
                     'call_count',
                     'number_type',
                     'number7800',
                     'is_blocked',
                     'is_disabled'
                 ] as $field) {
            foreach ($actual as $number => $l) {
                if (isset($saved[$number]) && $saved[$number][$field] != $l[$field] && !isset($d[$number])) {
                    $d[$number] = ['action' => 'update'] + $l;
                }
            }
        }

        return $d;
    }

    /**
     * @param array $diff
     * @param int $accountTariffId
     */
    private function _diffToSync($diff, $accountTariffId = null)
    {
        foreach ($diff as $data) {
            // $accountTariffId && $data['account_tariff_id'] = $accountTariffId;
            EventQueue::go(EventQueue::ATS3__SYNC, $data);
        }
    }

    /**
     * @param array $saved
     * @param array $actual
     * @return array|bool
     */
    private function _diff($saved, $actual)
    {
        $d = [
            'added' => [],
            'deleted' => [],
            'changed' => [],
        ];

        foreach (array_diff(array_keys($saved), array_keys($actual)) as $l) {
            $d['deleted'][$l] = $saved[$l];
        }

        foreach (array_diff(array_keys($actual), array_keys($saved)) as $l) {
            $d['added'][$l] = $actual[$l];
        }


        foreach ($actual as $number => $l) {
            foreach ([
                         'client_id',
                         'region',
                         'call_count',
                         'number_type',
                         'number7800',
                         'is_blocked',
                         'is_disabled'
                     ] as $field) {

                if (isset($saved[$number]) && $saved[$number][$field] != $l[$field]) {
                    if (!isset($d['changed'][$number]['changed_fields'])) {
                        $d['changed'][$number]['data_new'] = $l;
                        $d['changed'][$number]['data_old'] = $saved[$number];
                    }

                    $d['changed'][$number]['changed_fields'][$field] = $l[$field];
                }
            }
        }

        foreach ($d as $k => $v) {
            if ($v) {
                return $d;
            }
        }

        return false;
    }

    /**
     * @param array $diff
     */
    private function _diffApply($diff)
    {
        if ($diff['added']) {
            $this->_applyAdd($diff['added']);
        }

        if ($diff['deleted']) {
            $this->_applyDeleted($diff['deleted']);
        }

        if ($diff['changed']) {
            $this->_applyChanged($diff['changed']);
        }
    }

    /**
     * @param array $numbers
     */
    private function _applyAdd($numbers)
    {
        foreach ($numbers as $numberData) {
            $n = new ActualNumber();
            $n->setAttributes($numberData, false);
            $n->save();

            $this->_addEvent($numberData);
        }
    }

    /**
     * @param array $numbers
     */
    private function _applyDeleted($numbers)
    {
        foreach ($numbers as $numberData) {
            ActualNumber::findOne(['number' => $numberData['number']])->delete();
            $this->_delEvent($numberData);
        }
    }

    /**
     * @param array $numbers
     */
    private function _applyChanged($numbers)
    {
        foreach ($numbers as $number => $data) {
            $n = ActualNumber::findOne(['number' => $number]);

            if ($n) {
                $n->setAttributes($data['changed_fields'], false);
                $n->save();

                $this->_changeEvent($number, $data);
            }
        }
    }

    /**
     * @param array $data
     */
    private function _addEvent($data)
    {
        /** @var UsageVoip $usage */
        $usage = UsageVoip::find()
            ->phone($data['number'])
            ->actual()
            ->one();

        $params = [];
        if ($usage) {
            $params = json_decode($usage->create_params, true) ?: [];
        }

        $paramIsGeoSubstitute = null;
        $isForSiptrunkOrVpbxOnly = null;
        $isCallRecord = null;
        if (!$usage) {
            /** @var AccountTariff $usage */
            $usage = AccountTariff::find()
                ->where(['voip_number' => $data['number']])
                ->andWhere(['IS NOT', 'tariff_period_id', null])
                ->one();

            if ($usage) {
                $paramIsGeoSubstitute = $usage->getResourceValue(ResourceModel::ID_VOIP_GEO_REPLACE);
                $isForSiptrunkOrVpbxOnly = $usage->getResourceValue(ResourceModel::ID_VOIP_ONLY_FOR_TRUNK_VATS);
                $isCallRecord = $usage->getResourceValue(ResourceModel::ID_VOIP_CALL_RECORDING);
                if ($usage->calltracking_params) {
                    $params = json_decode($usage->calltracking_params, true) ?: [];
                }
            }
        }

        if (!$usage) {
            throw new \LogicException('Услуга не найдена. Как-так?');
        }

        if ($usage->prev_usage_id) {
            throw new \LogicException('Услуга установлена на перенос!');
        }

        $this->_getPhoneApi()->addDid(
            (int)$data['client_id'],
            $data['number'],
            (int)$data['call_count'],
            (int)$data['region'],
            (bool)$this->_isNonumber($data['number']),
            $data['number7800'],
            $params['vpbx_stat_product_id'] ?? null,
            $params['is_create_user'] ?? null,
            $params['request_id'] ?? null,
            $paramIsGeoSubstitute,
            $isForSiptrunkOrVpbxOnly,
            $isCallRecord
        );
    }

    /**
     * @param array $data
     * @throws \yii\base\InvalidCallException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\BadRequestHttpException
     */
    private function _delEvent($data)
    {
        /** @var ClientAccount $account */
        $account = ClientAccount::findOne(['id' => $data['client_id']]);
        if (!$account) {
            throw new \LogicException('ЛС не найден');
        }

        /** @var UsageVoip $usage */
        $usage = UsageVoip::find()
            ->where([
                'E164' => $data['number'],
                'client' => $account->client
            ])
            ->orderBy(['actual_from' => SORT_DESC])
            ->one();

        if (!$usage) {
            $usage = AccountTariff::find()
                ->where([
                    'voip_number' => $data['number'],
                    'client_account_id' => $account->id
                ])
                ->orderBy(['id' => SORT_DESC])
                ->one();
        }

        $where = ['prev_usage_id' => $usage->id];
        $usage = UsageVoip::findOne($where) ?: AccountTariff::findOne($where);
        if ($usage) {
            throw new \LogicException('Удаление услуги. Услуга установлена на перенос!');
        }

        $this->_getPhoneApi()->disableDid(
            (int)$data['client_id'],
            $data['number']
        );
    }

    /**
     * @param string $number
     * @param array $data
     * @throws \yii\base\InvalidCallException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\BadRequestHttpException
     * @throws \InvalidArgumentException
     */
    private function _changeEvent($number, $data)
    {
        $old = $data['data_old'];
        $new = $data['data_new'];
        $changedFields = $data['changed_fields'];

        // change client_id
        if (isset($changedFields['client_id'])) {

            $isMoved = false;
            $usage = UsageVoip::find()
                ->phone($number)
                ->actual()
                ->one();

            if (!$usage) {
                $usage = AccountTariff::find()
                    ->where(['voip_number' => $number])
                    ->andWhere(['IS NOT', 'tariff_period_id', null])
                    ->one();
            }

            if ($usage) {
                $isMoved = $usage->prev_usage_id;
            }

            if ($isMoved) {
                $this->_getPhoneApi()->editClientId(
                    (int)$old['client_id'],
                    (int)$new['client_id'],
                    $number
                );

                unset($changedFields['client_id']);
            } else {
                $this->_delEvent($old);
                $this->_addEvent($new);
                return;
            }
        }

        // номер заблокирован (есть только входящая связь)
        if (isset($changedFields['is_blocked'])) {

            if ($new['is_blocked']) {
                EventQueue::go(EventQueue::ATS3__BLOCKED, $new);
            } else {
                EventQueue::go(EventQueue::ATS3__UNBLOCKED, $new);
            }

            unset($changedFields['is_blocked']);
        }

        // номер временно отключен (отключение и входящей и исходящей связи)
        if (isset($changedFields['is_disabled'])) {
            $s = [
                'client_id' => (int)$new['client_id'],
                'number' => $number
            ];

            EventQueue::go(EventQueue::ATS3__DISABLED_NUMBER, $s);

            unset($changedFields['is_disabled']);
        }

        // change fields
        if ($changedFields) {

            /** @var AccountTariff $accountTariff */
            $accountTariff = AccountTariff::find()
                ->where([
                    'service_type_id' => ServiceType::ID_VOIP,
                    'voip_number' => $number,
                ])
                ->andWhere(['NOT', ['tariff_period_id' => null]])
                ->one();

            $isRobocallEnabled = $accountTariff && $accountTariff->tariff_period_id && $accountTariff->tariffPeriod->tariff->isAutodial();
            $isGeoSubstitute = null;
            $isForSiptrunkOrVpbxOnly = null;
            $isCallRecord = null;
            if ($accountTariff) {
                $isGeoSubstitute = $accountTariff->getResourceValue(ResourceModel::ID_VOIP_GEO_REPLACE);
                $isForSiptrunkOrVpbxOnly = $accountTariff->getResourceValue(ResourceModel::ID_VOIP_ONLY_FOR_TRUNK_VATS);
                $isCallRecord = $accountTariff->getResourceValue(ResourceModel::ID_VOIP_CALL_RECORDING);
            }

            $this->_getPhoneApi()->editDid(
                (int)$new['client_id'],
                $number,
                (int)$new['call_count'],
                null,
                null,
                null,
                null,
                isset($changedFields['region']) ? (int)$changedFields['region'] : null,
                isset($changedFields['number7800']) ? $changedFields['number7800'] : null,
                $isRobocallEnabled,
                $isGeoSubstitute,
                $isForSiptrunkOrVpbxOnly,
                $isCallRecord
            );
        }
    }

    /**
     * @param string $number
     * @return bool
     */
    private function _isNonumber($number)
    {
        return (strlen($number) < 6);
    }

    /**
     * @param string $number
     * @return bool
     */
    private function _is7800($number)
    {
        return (strpos($number, '7800') === 0);
    }

    /**
     * @param string $number
     * @param int $toClientId
     */
    public static function transferNumberWithVpbx($number, $toClientId)
    {
        $actual = ActualNumber::findOne(['number' => $number]);
        if ($actual) {
            $actual->client_id = $toClientId;
            $actual->save();
        }
    }
}

