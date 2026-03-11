<?php

namespace app\modules\sbisTenzor\commands;

use app\exceptions\ModelValidationException;
use app\models\ClientAccount;
use app\models\ClientContragent;
use app\modules\sbisTenzor\classes\ContractorInfo;
use app\modules\sbisTenzor\classes\SBISTensorAPI;
use app\modules\sbisTenzor\exceptions\SBISTensorException;
use app\modules\sbisTenzor\helpers\SBISInfo;
use app\modules\sbisTenzor\models\SBISDocument;
use app\modules\sbisTenzor\models\SBISOrganization;
use app\modules\sbisTenzor\Module;
use app\widgets\ConsoleProgress;
use kartik\base\Config;
use Yii;
use yii\base\InvalidArgumentException;
use yii\console\Controller;

class ContractorController extends Controller
{
    /**
     * Запрос на получение списка клиентов, работающих с ЭДО
     *
     * @return \app\queries\ClientAccountQuery
     */
    protected function getClientsToUpdate()
    {
        return ClientAccount::find()
            ->active()
            ->with('clientContractModel.clientContragent')
            ->andWhere(['NOT', ['exchange_group_id' => null]]);
    }

    /**
     * Запрос на получение списка клиентов, которые могут работать с ЭДО
     *
     * @return \app\queries\ClientAccountQuery
     */
    protected function getClientsToFetch()
    {
        return ClientAccount::find()
            ->from(['client' => ClientAccount::tableName()])
            ->active()
            //->with('clientContractModel.clientContragent')
            ->innerJoinWith('clientContractModel cc', false)
            ->where(['cc.organization_id' => SBISOrganization::find()
                ->select('organization_id')
                ->where(['is_active' => true])])
            ->orderBy(['id' => SORT_ASC]);
    }

    /**
     * Обновить информацию по контрагентам
     */
    public function actionUpdate()
    {
        foreach ($this->getClientsToUpdate()->each() as $client) {
            /** @var ClientAccount $client */
            try {
                SBISInfo::getExchangeIntegrationId($client, true);
            } catch (\Exception $e) {
                Yii::error($e);
                $errorText = sprintf(
                    'Ошибка обновления данных по контрагенту (client id: %s): %s',
                    $client->id,
                    $e->getMessage()
                );
                Yii::error($errorText, SBISDocument::LOG_CATEGORY);

                echo $errorText . PHP_EOL;
            }
        }
    }

    /**
     * Обновить информацию по контрагентам
     *
     * @param int $updateClientExchangeGroup
     */
    public function actionFetch($updateClientExchangeGroup = 0)
    {
        $count = $this->getClientsToFetch()->count();
        $progress = new ConsoleProgress($count, function ($string) {
            echo $string;
        });

        $allErrors = [];
        foreach ($this->getClientsToFetch()->each() as $client) {
            $progress->nextStep();

            /** @var ClientAccount $client */
            try {
                $contractorInfo = ContractorInfo::get($client, null, true);

                if (
                    //!$client->exchange_group_id &&
                    $updateClientExchangeGroup &&
                    $contractorInfo->isRoamingEnabled()
                ) {
                    $client->exchange_group_id = $contractorInfo->getExchangeGroupIdDefault();
                    if (!$client->save()) {
                        throw new ModelValidationException($client);
                    }
                }
            } catch (\Exception $e) {
                Yii::error($e);
                $errorText = sprintf(
                    'Ошибка при получении данных по контрагенту (client id: %s): %s',
                    $client->id,
                    $e->getMessage()
                );
                Yii::error($errorText, SBISDocument::LOG_CATEGORY);

                $allErrors[] = $errorText;
            }
        }
        echo PHP_EOL;

        if ($allErrors) {
            echo 'Errors:' . PHP_EOL;
            echo implode(PHP_EOL, $allErrors);
            echo PHP_EOL;
        }
    }

    /**
     * Запросить информацию по контрагенту
     *
     * @param int $clientId
     * @param string $branchCode
     * @param int $kpp
     * @throws SBISTensorException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionContractorInfo($clientId = 0, $branchCode = '', $kpp = 0)
    {
        if (!$clientId) {
            throw new InvalidArgumentException('No client');
        }

        $client = ClientAccount::findOne(['id' => $clientId]);

        if (!$client) {
            throw new InvalidArgumentException('Client not found');
        }

        $branchCode = $branchCode ? : $client->getBranchCode();

        echo '--------------------------------' . PHP_EOL;
        echo 'Client id: ' . $clientId . PHP_EOL;
        echo 'Client full name: ' . $client->contragent->name . PHP_EOL;
        echo 'Client inn: ' . $client->getInn() . PHP_EOL;

        $result = [];

        /** @var Module $module */
        $module = Config::getModule('sbisTenzor');
        if ($params = $module->getParams()) {
            $sbisOrganization = array_shift($params);
            $api = new SBISTensorAPI($sbisOrganization);

            switch ($client->contragent->legal_type) {
                case ClientContragent::PERSON_TYPE:
                    $result = $api->getContractorInfoPerson($client->contragent->person);
                    break;

                case ClientContragent::IP_TYPE:
                    $result = $api->getContractorInfoIp($client->getInn(), $branchCode);
                    break;

                case ClientContragent::LEGAL_TYPE:
                    $kpp = $kpp ? $kpp : $client->getKpp();

                    echo 'Client kpp: ' . $kpp . PHP_EOL;
                    if ($branchCode) {
                        echo 'Branch code: ' . $branchCode . PHP_EOL;
                    }
                    $result = $api->getContractorInfoLegal($client->getInn(), $kpp, $client->contract->contragent->name, $branchCode);
                    break;
            }
        }

        echo '--------------------------------' . PHP_EOL;
        echo 'Result: ' . PHP_EOL;
        var_dump($result);
    }
}
