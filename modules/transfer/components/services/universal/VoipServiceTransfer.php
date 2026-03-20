<?php

namespace app\modules\transfer\components\services\universal;

use app\exceptions\ModelValidationException;
use app\modules\transfer\components\services\PreProcessor;
use app\modules\transfer\components\services\Processor;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\ServiceType;
use yii\base\InvalidCallException;
use yii\base\InvalidParamException;
use yii\base\InvalidValueException;

class VoipServiceTransfer extends BasicServiceTransfer
{

    /**
     * @return int
     */
    public function getServiceTypeId()
    {
        return ServiceType::ID_VOIP;
    }

    /**
     * @param PreProcessor $preProcessor
     * @throws ModelValidationException
     */
    public function finalizeClose(PreProcessor $preProcessor)
    {
        // Ничего не делать в случае переноса "Универсальная услуга" => "Универсальная услуга"
        // Для универсальных услуг метод переопределяет родительский, ОБЯЗАТЕЛЬНО ДОЛЖЕН НИЧЕГО НЕ ДЕЛАТЬ
    }

    /**
     * @param PreProcessor $preProcessor
     * @throws ModelValidationException
     * @throws InvalidValueException
     * @throws InvalidParamException
     * @throws InvalidCallException
     * @throws \yii\db\Exception
     * @throws \Exception
     */
    public function finalizeOpen(PreProcessor $preProcessor)
    {
        parent::finalizeOpen($preProcessor);

        // Try to process packages
        // Пакеты в данной реализации не переносятся.
//        $this->_packagesProcess($preProcessor);
    }

    /**
     * @param PreProcessor $preProcessor
     * @throws ModelValidationException
     * @throws \yii\db\Exception
     * @throws InvalidParamException
     * @throws InvalidValueException
     * @throws InvalidCallException
     * @throws \Exception
     */
    private function _packagesProcess(PreProcessor $preProcessor)
    {
        $packages = AccountTariff::find()
            ->where(['prev_account_tariff_id' => /*$preProcessor->targetServiceHandler->getService()->id*/$this->getService()->prev_usage_id])
            // Active packages only
            ->andWhere(['IS NOT', 'tariff_period_id', null]);

        if ($packages->count()) {
            /** @var AccountTariff $package */
            foreach ($packages->each() as $package) {
                // При выборе конкретного тарифа — переносить только ручные пакеты.
                // Дефолтные и бандл-пакеты будут созданы автоматически при применении нового тарифа.
                if ($package->tariffPeriod->tariff->is_default || $package->tariffPeriod->tariff->is_bundle) {
                    continue;
                }

                $preProcessor->processor->run(
                    (new PreProcessor)
                        ->setProcessor(new $preProcessor->processor)
                        ->setServiceType(Processor::SERVICE_PACKAGE)
                        ->setService(
                            $preProcessor->processor->getHandler(Processor::SERVICE_PACKAGE),
                            $package->id
                        )
                        ->setProcessedFromDate($preProcessor->activationDate)
                        ->setSourceClientAccount($preProcessor->clientAccount->id)
                        ->setTargetClientAccount($preProcessor->targetClientAccount->id)
                        ->setTariff($package->tariff_period_id)
                        ->setRelation('prev_account_tariff_id', $this->getService()->primaryKey)
                );
            }
        }
    }

}