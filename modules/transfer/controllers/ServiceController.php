<?php

namespace app\modules\transfer\controllers;

use app\classes\Assert;
use app\classes\BaseController;
use app\classes\ReturnFormatted;
use app\classes\traits\AddClientAccountFilterTraits;
use app\exceptions\ModelValidationException;
use app\models\ClientAccount;
use app\models\DidGroup;
use app\models\Number;
use app\modules\nnp\models\NumberRange;
use app\modules\transfer\forms\services\BaseForm;
use app\modules\uu\models\ServiceType;
use app\modules\uu\models\TariffPeriod;
use kartik\base\Config;
use Yii;
use yii\base\ExitException;
use yii\base\InvalidCallException;
use yii\base\InvalidParamException;
use yii\base\InvalidValueException;
use yii\db\Expression;
use yii\web\Response;

class ServiceController extends BaseController
{

    use AddClientAccountFilterTraits;

    /**
     * @return string
     * @throws \yii\base\Exception
     * @throws InvalidParamException
     * @throws InvalidCallException
     * @throws InvalidValueException
     * @throws ModelValidationException
     * @throws \yii\db\Exception
     * @throws \Exception
     */
    public function actionIndex()
    {
        $clientAccount = $this->_getCurrentClientAccount();
        if ($clientAccount === null) {
            return $this->redirect('/');
        }

        /** @var \app\modules\transfer\Module $module */
        $module = Config::getModule('transfer');
        /** @var BaseForm $form */
        $form = $module->getServiceProcessor($clientAccount->account_version)->getForm($clientAccount);

        return $this->render('index', [
            'form' => $form,
        ]);
    }

    /**
     * @return string|Response
     * @throws ModelValidationException
     * @throws InvalidParamException
     * @throws InvalidCallException
     * @throws InvalidValueException
     * @throws \yii\db\Exception
     * @throws \Exception
     */
    public function actionProcess()
    {
        $clientAccount = $this->_getCurrentClientAccount();
        if ($clientAccount === null) {
            return $this->redirect('/');
        }

        if (!Yii::$app->request->isPost) {
            return $this->redirect('/transfer/service');
        }

        /** @var \app\modules\transfer\Module $module */
        $module = Config::getModule('transfer');
        /** @var BaseForm $form */
        $form = $module
            ->getServiceProcessor($clientAccount->account_version)
            ->getForm($clientAccount);

        $form->process();

        return $this->render('log', [
            'form' => $form,
        ]);
    }

    /**
     * @param int $clientAccountId
     * @param int|null $clientAccountVersion
     * @param string $term
     * @return array
     * @throws InvalidParamException
     */
    public function actionAccountSearch($clientAccountId, $clientAccountVersion = null, $term)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        if (!Yii::$app->request->getIsAjax()) {
            return [];
        }

        $items = [];
        $clientAccounts = ClientAccount::dao()
            ->clientAccountSearch($term, $clientAccountId, $clientAccountVersion);

        foreach ($clientAccounts->each() as $row) {
            $items[] = [
                'label' => '№ ' . $row['id'] . ' - ' . $row['name'],
                'full' => '№ ' . $row['id'] . ' - ' . $row['name'],
                'value' => $row['id'],
                'version' => $row['account_version'],
            ];
        }

        return $items;
    }

    /**
     * @param int $clientAccountId
     * @return float
     * @throws \yii\base\Exception
     */
    public function actionGetClientAccountBalance($clientAccountId)
    {
        $clientAccount = ClientAccount::findOne(['id' => $clientAccountId]);
        Assert::isObject($clientAccount);

        return $clientAccount->billingCounters->realtimeBalance;
    }

    /**
     * @param int $clientAccountId
     * @param string $serviceTypeKey
     * @param int $serviceValue
     * @throws ExitException
     */
    public function actionGetUniversalTariffs($clientAccountId, $serviceTypeKey, $serviceValue = null)
    {
        Yii::$app->response->format = Response::FORMAT_HTML;

        try {
            /** @var ClientAccount $clientAccount */
            $clientAccount = ClientAccount::findOne(['id' => $clientAccountId]);
            Assert::isObject($clientAccount);

            /** @var \app\modules\transfer\Module $module */
            $module = Config::getModule('transfer');

            $serviceTypeProcessor = $module->getServiceProcessor($clientAccount->account_version)->getHandler($serviceTypeKey);
            $cityId = $statusId = $ndcType = null;

            if ($serviceTypeProcessor->getServiceTypeId() === ServiceType::ID_VOIP) {
                Assert::isNotEmpty($serviceValue);

                if (!Number::isMcnLine($serviceValue)) {

                    $number = \app\models\Number::findOne(['number' => $serviceValue]);

                    Assert::isObject($number, 'Number "' . $serviceValue . '" not found');

                    $cityId = $number->getCityByNumber()->id;
                    $ndcType = $number->ndc_type_id;
                }
            }elseif ($serviceTypeProcessor->getServiceTypeId() == ServiceType::ID_VPBX) {
                // pass
            } else {
                return ['0' => 'не найден'];
            }

            $returnArray = TariffPeriod::getList(
                $defaultTariffPeriodId,
                $serviceTypeProcessor->getServiceTypeId(),
                $clientAccount->currency,
                $clientAccount->country->code,
                $voipCountryId = null,
                $cityId,
                $isWithEmpty = false,
                $isWithNullAndNotNull = false,
                $statusId,
                $clientAccount->is_voip_with_tax,
                $clientAccount->contract->organization_id,
                $ndcType
            );

            ReturnFormatted::me()->returnFormattedValues($returnArray, ReturnFormatted::FORMAT_OPTIONS);
        } catch (\Exception $e) {
        }

        Yii::$app->end(200);
    }

}