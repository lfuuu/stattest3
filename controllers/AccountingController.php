<?php

namespace app\controllers;

use app\classes\BaseController;
use app\classes\traits\AddClientAccountFilterTraits;
use app\models\ClientAccount;
use app\models\ClientContract;
use app\models\Organization;
use app\models\Saldo;
use Yii;
use yii\base\Exception;
use yii\filters\AccessControl;

class AccountingController extends BaseController
{
    use AddClientAccountFilterTraits;

    /**
     * @return array
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['clients.read'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Фильтр списка по умолчанию на основе financial_type договора
     */
    private function getDefaultListFilter(ClientAccount $account): string
    {
        switch ($account->clientContractModel->financial_type ?? '') {
            case ClientContract::FINANCIAL_TYPE_CONSUMABLES:
                return 'outcome';
            case ClientContract::FINANCIAL_TYPE_YIELD_CONSUMABLE:
                return 'full';
            default:
                return 'income';
        }
    }

    /**
     * @param int $client_id
     * @return string
     * @throws \yii\base\InvalidParamException
     * @throws Exception
     */
    public function actionIndex($client_id = 0)
    {
        $account = ClientAccount::findOne(['id' => $client_id ? $client_id : $this->_getCurrentClientAccountId()]);

        if (!$account) {
            throw new Exception('Client not found');
        }

        // Для старого стата, для старых модулей
        Yii::$app->session->set('clients_client', $account->id);
        $this->applyFixClient($account->id);

        if ($setValue = \Yii::$app->request->get('set')) {
            $is = \Yii::$app->request->get('is');
            switch ($setValue) {
                case 'billOperations':
                    $_SESSION["billOperations"] = (bool)$is;
                    break;

                case 'listFilter':
                    $filter = $is;

                    if (!in_array($filter, ['full', 'income', 'outcome'])) {
                        $filter = null;
                    }

                    if (!$filter && isset($_SESSION["listFilter"])) {
                        unset($_SESSION["listFilter"]);
                    }

                    if ($filter) {
                        $_SESSION["listFilter"] = $filter;
                    }
                    break;

                default:
                    break;
            }
        }

        if (!isset($_SESSION["prevClientAccountId"]) || $_SESSION["prevClientAccountId"] != $account->id) {
            $_SESSION["listFilter"] = $this->getDefaultListFilter($account);
            $_SESSION["prevClientAccountId"] = $account->id;
        }

        $billOperations = $_SESSION["billOperations"] ?? false;
        $listFilter = $_SESSION["listFilter"] ?? 'income';

        return
            $this->render(
                'index',
                [
                    'account' => $account,
                    'billOperations' => $billOperations,
                    'listFilter' => $listFilter,
                    'changeCompany' => Organization::dao()->getWhenOrganizationSwitched($account->contract_id),
                    'changePaymentScheme' => ClientAccount::dao()->getWhenPaymentSchemeSwitched($account->id),
                    'saldo' => Saldo::getLastSaldo($account->id),
                ]
            );
    }
}
