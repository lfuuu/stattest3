<?php

namespace app\classes\api;

use app\classes\Assert;
use app\classes\HttpClient;
use app\classes\Singleton;
use app\models\ClientAccount;
use app\models\Number;
use app\models\Region;
use app\models\UsageTrunk;
use app\modules\nnp\models\NumberRange;
use app\modules\uu\models\AccountTariff;
use Yii;
use yii\base\InvalidConfigException;

/**
 * Class ApiPhone
 *
 * @method static ApiPhone me($args = null)
 */
class ApiPhone extends Singleton
{
    const TYPE_LINE = 'line';
    const TYPE_VPBX = 'vpbx';

    /**
     * @return bool
     */
    public function isAvailable()
    {
        return (bool)$this->_getHost();
    }

    /**
     * @return string
     */
    private function _getHost()
    {
        return isset(Yii::$app->params['PHONE_SERVER']) ? Yii::$app->params['PHONE_SERVER'] : '';
    }

    /**
     * @return bool
     */
    private function _getApiUrl()
    {
        $phoneHost = $this->_getHost();
        return $phoneHost ? 'https://' . $phoneHost . '/phone/api/' : '';
    }

    /**
     * @return array
     */
    private function _getApiAuthorization()
    {
        return isset(Yii::$app->params['VPBX_API_AUTHORIZATION']) ? Yii::$app->params['VPBX_API_AUTHORIZATION'] : [];
    }

    /**
     * @param string $action
     * @param array $data
     * @return mixed
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidCallException
     */
    protected function _exec($action, $data)
    {
        if (defined('ats3_silent')) {
            return true;
        }

        if (!$this->isAvailable()) {
            throw new InvalidConfigException('API Phone was not configured');
        }

        return (new HttpClient)
            ->createJsonRequest()
            ->setMethod('post')
            ->setData($data)
            ->setUrl($this->_getApiUrl() . $action)
            ->auth($this->_getApiAuthorization())
            ->getResponseDataWithCheck();
    }

    /**
     * @return array
     */
    public function getMultitranks()
    {
        $data = [];
        try {
            foreach ($this->_exec('multitrunks', null) as $d) {
                $data[$d['id']] = $d['name'];
            }
        } catch (\Exception $e) {
            // так исторически сложилось
        }

        return $data;
    }

    /**
     * @param ClientAccount $client
     * @return array
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidCallException
     */
    public function getNumbersInfo(ClientAccount $client)
    {
        return $this->_exec('numbers_info', ['account_id' => $client->id]);
    }

    /**
     * @param int $clientAccountId
     * @param string $number
     * @param int $lines
     * @param int $region
     * @param bool $isNonumber
     * @param string $number7800
     * @param int $vpbxStatProductId
     * @param int $isCreateUser
     * @param string $requestId
     * @param bool $isGeoSubstitute
     * @param bool $isForSiptrunkOrVpbxOnly
     * @param bool $isCallRecord
     * @return array
     * @throws InvalidConfigException
     * @throws \yii\base\Exception
     * @throws \yii\web\BadRequestHttpException
     */
    public function addDid(
        $clientAccountId,
        $number,
        $lines,
        $region,
        $isNonumber,
        $number7800 = null,
        $vpbxStatProductId = null,
        $isCreateUser = null,
        $requestId = null,
        $isGeoSubstitute = null,
        $isForSiptrunkOrVpbxOnly = null,
        $isCallRecord = null
    ) {
        $accountClient = ClientAccount::findOne(['id' => $clientAccountId]);

        Assert::isObject($accountClient);

        $params = [
            'client_id' => $clientAccountId,
            'did' => $number,
            'cl' => (int)$lines,
            'region' => (int)$region,
            'timezone' => Region::getTimezoneByRegionId($region),
            'type' => self::TYPE_LINE,
            'sip_accounts' => (($lines == 0 || UsageTrunk::dao()->hasService($accountClient) || AccountTariff::hasTrunk($clientAccountId)) ? 0 : 1),
            'nonumber' => $isNonumber,
            'is_user_create' => $isCreateUser ?? 1,
        ] + ($requestId ? ['request_id' => $requestId] : []);

        if ($isNonumber && $number7800) {
            $params['nonumber_phone'] = $number7800;
        }

        if ($vpbxStatProductId) {
            $params['vpbx_stat_product_id'] = $vpbxStatProductId;
            $params['type'] = self::TYPE_VPBX;
        }

        if ($isGeoSubstitute !== null) {
            $params['is_geo_substitute'] = (int)$isGeoSubstitute;
        }

        if (strpos($number, '7800') === 0) {
            $params['tech_number_did'] = Number::find()
                ->where(['number' => $number])
                ->select('number_tech')
                ->scalar() ?: '';
        }

        $params['is_for_siptrunk_or_vpbx_only'] = (int)(bool)$isForSiptrunkOrVpbxOnly;
        $params['is_call_record'] = (int)(bool)$isCallRecord;

        $params = array_merge($params, NumberRange::getNumberInfo($number));

        return $this->_exec('add_did', $params);
    }

    /**
     * @param int $clientAccountId
     * @param string $number
     * @param int $lines
     * @param int $isFmcActive
     * @param int $isFmcEditable
     * @param int $isMobileOutboundActive
     * @param int $isMobileOutboundEditable
     * @param int $region
     * @param string $number7800
     * @param bool $isRobocallEnabled
     * @param bool $isSmart
     * @param bool $isGeoSubstitute
     * @param bool $isForSiptrunkOrVpbxOnly
     * @param bool $isCallRecord
     * @return array
     */
    public function editDid(
        $clientAccountId,
        $number,
        $lines,
        $isFmcActive = null,
        $isFmcEditable = null,
        $isMobileOutboundActive = null,
        $isMobileOutboundEditable = null,
        $region = null,
        $number7800 = null,
        $isRobocallEnabled = false,
        $isSmart = false,
        $isGeoSubstitute = null,
        $isForSiptrunkOrVpbxOnly = null,
        $isCallRecord = null
    ) {
        $params = [
            'client_id' => $clientAccountId,
            'did' => $number,
            'cl' => (int)$lines,
        ];

        if ($isFmcActive !== null) {
            $params['is_fmc_active'] = (int)$isFmcActive;
        }

        if ($isFmcEditable !== null) {
            $params['is_fmc_editable'] = (int)$isFmcEditable;
        }

        if ($isMobileOutboundActive !== null) {
            $params['is_mobile_outbound_active'] = (int)$isMobileOutboundActive;
        }

        if ($isMobileOutboundEditable !== null) {
            $params['is_mobile_outbound_editable'] = (int)$isMobileOutboundEditable;
        }

        if ($region) {
            $params['region'] = (int)$region;
        }

        if ($number7800) {
            $params['nonumber_phone'] = $number7800;
        }

        if ($isGeoSubstitute !== null) {
            $params['is_geo_substitute'] = (int)$isGeoSubstitute;
        }

        if ($isSmart !== null) {
            $params['is_smart'] = (int)$isSmart;
        }

        if (strpos($number, '7800') === 0) {
            $params['tech_number_did'] = Number::find()
                ->where(['number' => $number])
                ->select('number_tech')
                ->scalar() ?: '';
        }

        $params['is_robocall_enabled'] = (int)$isRobocallEnabled;

        $params['is_for_siptrunk_or_vpbx_only'] = (int)(bool)$isForSiptrunkOrVpbxOnly;
        $params['is_call_record'] = (int)(bool)$isCallRecord;

        $params = array_merge($params, NumberRange::getNumberInfo($number));

        return $this->_exec('edit_did', $params);
    }

    /**
     * @param int $clientAccountId
     * @param string $number
     * @return array
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\base\InvalidCallException
     */
    public function disableDid($clientAccountId, $number)
    {
        $params = [
            'client_id' => $clientAccountId,
            'did' => $number,
        ];

        return $this->_exec('disable_did', $params);
    }

    /**
     * @param int $oldClientAccountId
     * @param int $newClientAccountId
     * @param string $number
     * @return array
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\base\InvalidCallException
     */
    public function editClientId($oldClientAccountId, $newClientAccountId, $number)
    {
        $params = [
            'old_client_id' => $oldClientAccountId,
            'new_client_id' => $newClientAccountId,
            'did' => $number,
        ];

        return $this->_exec('edit_client_id', $params);
    }

    /**
     * @param int $clientAccountId
     * @return array
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\base\InvalidCallException
     */
    public function numbersState($clientAccountId)
    {
        $params = [
            'account_id' => $clientAccountId,
        ];

        return $this->_exec('numbers_state', $params);
    }

    /**
     * @param string $login_did phone mobile number
     * @return string
     * @throws \yii\web\BadRequestHttpException
     */
    public function flashCall($login_did)
    {
        $params = [
            'login_did' => $login_did,
        ];

        $result = $this->_exec('flash_call', $params);

        if (!$result || (is_array($result) && !$result['success'])) {
            throw new \BadMethodCallException('API Phone/flash_call error: '. var_export($result, true));
        }

        return substr($result['result']['from'], -4);
    }
}
