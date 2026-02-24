<?php

namespace app\modules\transfer\components\services\universal;

use app\classes\Assert;
use app\classes\model\ActiveRecord;
use app\exceptions\ModelValidationException;
use app\models\ClientAccount;
use app\modules\transfer\components\services\PreProcessor;
use app\modules\transfer\components\services\ServiceTransfer;
use app\modules\transfer\forms\services\decorators\UniversalServiceDecorator;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\AccountTariffLog;
use app\modules\uu\models\AccountTariffResourceLog;
use yii\base\InvalidParamException;
use yii\base\InvalidValueException;

/**
 * @property-read ActiveRecord[] $possibleToTransfer
 * @property-read AccountTariff $service
 * @property-read AccountTariffResourceLog[] $resources
 */
abstract class BasicServiceTransfer extends ServiceTransfer
{

    /** @var AccountTariff */
    private $_service;

    /**
     * @param ClientAccount $clientAccount
     * @return ActiveRecord[]
     */
    public function getPossibleToTransfer(ClientAccount $clientAccount)
    {
        return AccountTariff::find()
            ->where(['IS NOT', 'tariff_period_id', null])
            ->andWhere(['client_account_id' => $clientAccount->id])
            ->andWhere(['service_type_id' => $this->getServiceTypeId()])
            ->orderBy(['id' => SORT_DESC])
            ->all();
    }

    /**
     * @param ActiveRecord $service
     * @return UniversalServiceDecorator
     */
    public function getServiceDecorator($service)
    {
        return new UniversalServiceDecorator(['service' => $service]);
    }

    /**
     * @return array
     */
    public function getAttributes()
    {
        $primaryKey = $this->getService()->primaryKey();

        $attributes = $this->getService()->getOldAttributes();
        unset($attributes[reset($primaryKey)], $attributes['tariff_period_id']);

        return $attributes;
    }

    /**
     * @return array
     */
    public function getBaseAttributes()
    {
        return $this->getService()->getAttributes(null, ['id']);
    }

    /**
     * @param int $serviceId
     * @return $this
     * @throws \yii\base\Exception
     */
    public function setServiceById($serviceId)
    {
        $this->_service = AccountTariff::findOne(['id' => $serviceId]);
        Assert::isObject($this->_service, 'Missing AccountTariff #' . $serviceId);

        return $this;
    }

    /**
     * @return AccountTariff
     */
    public function getService()
    {
        return $this->_service;
    }

    /**
     * @param AccountTariff $service
     */
    public function setService($service)
    {
        $this->_service = $service;
    }

    /**
     * @return AccountTariffResourceLog[]
     * @throws InvalidValueException
     */
    public function getResources()
    {
        /** @var AccountTariff $service */
        $service = $this->getService();
        $result = [];

        foreach ($service->resources as $resource) {
            if (!$resource->isOption()) {
                continue;
            }

            $accountTariffResourceLog = $service->getAccountTariffResourceLogs($resource->id)->one();

            if ($accountTariffResourceLog === null) {
                throw new InvalidValueException('AccountTariffResourceLog not found for resource #' . $resource->id);
            }

            $result[] = $accountTariffResourceLog;
        }

        return $result;
    }

    /**
     * @param PreProcessor $preProcessor
     * @throws ModelValidationException
     */
    public function closeService(PreProcessor $preProcessor)
    {
        $accountTariffLog = new AccountTariffLog;
        $accountTariffLog->setAttributes([
            'account_tariff_id' => $this->getService()->primaryKey,
            'tariff_period_id' => null,
            'actual_from' => $preProcessor->activationDate,
        ]);

        $accountTariffLog->detachBehavior('ReferentialPackageControl');
        $accountTariffLog->detachBehavior('AccountTariffAddDefaultPackage');
        $accountTariffLog->accountTariff->isTransfer = true;

        if (!$accountTariffLog->save()) {
            throw new ModelValidationException($accountTariffLog);
        }
    }

    /**
     * @param PreProcessor $preProcessor
     * @throws InvalidParamException
     */
    public function openService(PreProcessor $preProcessor)
    {
        $accountTariff = new AccountTariff;
        $accountTariff->scenario = 'serviceTransfer';
        $accountTariff->setAttributes($preProcessor->sourceServiceHandler->getBaseAttributes(), false);
        $accountTariff->setAttribute('client_account_id', $preProcessor->targetClientAccount->id);
        $accountTariff->tariff_period_id = null; // новая услуга должна быть не включена

        $this->setService($accountTariff);
    }

    /**
     * @param PreProcessor $preProcessor
     * @throws ModelValidationException
     * @throws InvalidParamException
     * @throws InvalidValueException
     */
    public function finalizeClose(PreProcessor $preProcessor)
    {
        // $service <= $preProcessor->sourceServiceHandler->getService()
        $service = $this->getService();
        $service->setAttribute('next_usage_id', $preProcessor->targetServiceHandler->getService()->primaryKey);

        if (!$service->save()) {
            throw new ModelValidationException($service);
        }
    }

    /**
     * @param PreProcessor $preProcessor
     * @throws ModelValidationException
     * @throws InvalidValueException
     * @throws InvalidParamException
     */
    public function finalizeOpen(PreProcessor $preProcessor)
    {
        // $service <= $preProcessor->targetServiceHandler->getService()
        $service = $this->getService();
        $service->setAttribute('prev_usage_id', $preProcessor->sourceServiceHandler->getService()->primaryKey);

        if (!$service->save()) {
            throw new ModelValidationException($service);
        }

        // Create account tariff log
        $accountTariffLog = new AccountTariffLog;
        $accountTariffLog->setAttributes([
            'account_tariff_id' => $service->primaryKey,
            'tariff_period_id' => $preProcessor->tariffId ?: $preProcessor->sourceServiceHandler->getService()->tariff_period_id,
            'actual_from' => $preProcessor->activationDate,
        ]);
        $accountTariffLog->accountTariff->isTransfer = true;

        if (!$accountTariffLog->save()) {
            throw new ModelValidationException($accountTariffLog);
        }

        // Create resources
        $accountTariffResourceLogs = $preProcessor->sourceServiceHandler->getResources();

        foreach ($accountTariffResourceLogs as $accountTariffResourceLog) {
            /** @var AccountTariffResourceLog $accountTariffResourceLog */

            // Skip if this is resource not an option
            if (!$accountTariffResourceLog->resource->isOption()) {
                continue;
            }

            // Try to find exists account tariff resource log
            $targetAccountTariffResourceLog = AccountTariffResourceLog::findOne([
                'account_tariff_id' => $service->id,
                'resource_id' => $accountTariffResourceLog->resource_id,
            ]);

            if (!$targetAccountTariffResourceLog) {
                // Create new account tariff resource log
                $targetAccountTariffResourceLog = new AccountTariffResourceLog;
                $targetAccountTariffResourceLog->setAttributes($accountTariffResourceLog->getAttributes(null, ['id']));
            }

            $targetAccountTariffResourceLog->setAttributes([
                'account_tariff_id' => $service->primaryKey,
                'actual_from' => $preProcessor->activationDate,
                'amount' => $accountTariffResourceLog->amount,
            ]);

            if (!$targetAccountTariffResourceLog->save()) {
                throw new ModelValidationException($targetAccountTariffResourceLog);
            }
        }
    }

}