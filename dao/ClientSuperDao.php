<?php

namespace app\dao;

use app\classes\helpers\ArrayHelper;
use app\classes\Singleton;
use app\helpers\DateTimeZoneHelper;
use app\models\billing\Locks;
use app\models\Business;
use app\models\BusinessProcessStatus;
use app\models\ClientAccount;
use app\models\ClientContract;
use app\models\ClientContragent;
use app\models\ClientSuper;
use app\models\Country;
use app\modules\uu\models\ServiceType;
use yii\base\InvalidParamException;

/**
 * @method static ClientSuperDao me($args = null)
 */
class ClientSuperDao extends Singleton
{
    const STRUCT_CLIENT_ALL = 'all';
    const STRUCT_CLIENT_STRUCT = 'struct';

    /**
     * Возвращает массив структур клиента по id супер-клиентов
     *
     * @param $superIds
     * @param string $type
     * @return array
     */
    public function getSuperClientStructByIds($superIds, $type = self::STRUCT_CLIENT_ALL)
    {
        $fullResult = [];
        foreach ($superIds as $id) {

            $super = ClientSuper::findOne(['id' => $id]);
            $accounts = ArrayHelper::index(
                ClientAccount::find()
                    ->where(['super_id' => $id])
                    ->asArray()
                    ->all(),
                null,
                'contract_id'
            );

            $contracts = ArrayHelper::index(
                ClientContract::find()
                    ->where(['super_id' => $id])
                    ->asArray()
                    ->all(),
                null,
                'contragent_id'
            );

            $contragents = ClientContragent::find()
                ->where(['super_id' => $id])
                ->asArray()
                ->all();

            $countries = Country::find()
                ->where(['code' => array_unique(array_column($contragents, 'country_id'))])
                ->indexBy('code')
                ->all();


            $timezone = DateTimeZoneHelper::TIMEZONE_MOSCOW;

            $resultContragents = [];
            $accountIdxs = [];

            foreach ($contragents as $contragent) {
                $resultContracts = [];
                if (!isset($contracts[$contragent['id']])) {
                    continue;
                }
                /** @var ClientContract $contract */
                foreach ($contracts[$contragent['id']] as $contract) {
                    $resultAccounts = [];
                    if (!isset($accounts[$contract['id']])) {
                        continue;
                    }
                    /** @var ClientAccount $account */
                    foreach ($accounts[$contract['id']] as $account) {
                        $resultAccount = $this->getAccountInfo($account, $contract);
                        if ($type != self::STRUCT_CLIENT_ALL) {
                            unset($resultAccount['is_finance_block'], $resultAccount['is_overran_block']);
                        }

                        $resultAccounts[] = $resultAccount;
                        $timezone = $account['timezone_name'];
                        $accountIdxs[$account['id']] = [
                            'idx_account' => count($resultAccounts) - 1,
                            'idx_contragent' => count($resultContragents),
                            'idx_contract' => count($resultContracts)
                        ];
                    }

                    if ($resultAccounts) {
                        $resultContracts[] = [
                            'id' => $contract['id'],
                            'number' => $contract['number'],
                            'state' => $contract['state'],
                            'can_login_as_clients' => $contract['is_lk_access'],
                            'partner_id' => $contract['partner_contract_id'],
                            'is_partner' => $contract['business_id'] == Business::PARTNER,
                            'partner_login_allow' => $contract['is_partner_login_allow'],
                            'account_manager' => $contract['account_manager'],
                            'business_id' => $contract['business_id'],
                            'business_process_id' => $contract['business_process_id'],
                            'business_process_status_id' => $contract['business_process_status_id'],
                            'organization_id' => (int)$contract['organization_id'],
                        ] + ($contragent['legal_type'] == ClientContragent::LEGAL_TYPE ? [
                            'inn' => $contragent['inn'],
                            'kpp' => $contragent['kpp'],
                            'fio' => $contragent['fio']
                        ] : []) +  ['accounts' => $resultAccounts];
                    }
                }

                if ($resultContracts) {
                    $resultContragents[] = [
                        'id' => $contragent['id'],
                        'name' => $contragent['name'],
                        'country' => $countries && isset($countries[$contragent['country_id']]) ? $countries[$contragent['country_id']]->alpha_3 : '',
                        'country_id' => $contragent['country_id'],
                        'legal_type' => $contragent['legal_type'],
                        'language' => $contragent['lang_code'],
                        'contracts' => $resultContracts
                    ];
                }
            }

            if ($resultContragents && $accountIdxs) {

                if ($type == self::STRUCT_CLIENT_ALL) {
                    $locksAnswer = $this->getAccountsLocks(null, array_keys($accountIdxs));

                    if ($locksAnswer['is_load_complete']) {
                        $locks = $locksAnswer['data'];

                        foreach (array_keys($accountIdxs) as $accountId) {

                            $lock = isset($locks[$accountId]) ? $locks[$accountId] : null;

                            if (!isset($accountIdxs[$accountId])) {
                                continue;
                            }

                            $idxContragent = $accountIdxs[$accountId]['idx_contragent'];
                            $idxContract = $accountIdxs[$accountId]['idx_contract'];
                            $idxAccount = $accountIdxs[$accountId]['idx_account'];

                            $resultContragents[$idxContragent]['contracts'][$idxContract]['accounts'][$idxAccount]['is_finance_block'] = $lock && $lock['is_finance_block'];
                            $resultContragents[$idxContragent]['contracts'][$idxContract]['accounts'][$idxAccount]['is_overran_block'] = $lock && $lock['is_overran_block'];
                        }
                    }
                }

                $fullResult[$super->id] = [
                    'id' => $super->id,
                    'timezone' => $timezone,
                    'name' => $super->name,
                    'shop' => [
                        'entry_point_id' => $super->entry_point_id,
                        'country_id' => $super->entryPoint ? $super->entryPoint->country_id : null,
                        'domain' => $super->entryPoint && $super->entryPoint->site_id ? $super->entryPoint->site->domain : null,
                    ],
                    'contragents' => $resultContragents
                ];
            }
        }

        $this->_setTrueTypes($fullResult);

        return $fullResult;
    }

    /**
     * Получение полной информации
     * по аккаунту
     * 
     * @param array $account
     * @param array $contract
     * @return array
     */
    public function getAccountInfo($account, $contract, $isWithApplications = true)
    {
        $data = [
            'id' => $account['id'],
            'is_disabled' => !in_array($contract['business_process_status_id'], [
                BusinessProcessStatus::TELEKOM_MAINTENANCE_CONNECTED,
                BusinessProcessStatus::TELEKOM_MAINTENANCE_WORK,
                BusinessProcessStatus::TELEKOM_MAINTENANCE_PORTING_REQUEST_ACCEPTED
            ]),
            'is_blocked' => (bool)$account['is_blocked'],
            'is_with_working_services' => (bool)$account['is_active'],
            'is_finance_block' => null,
            'is_overran_block' => null,
            'is_bill_pay_overdue' => (bool)$account['is_bill_pay_overdue'],
            'is_postpaid' => (int)$account['is_postpaid'],
            'is_show_in_lk' => ClientAccount::isShowInLk($account['show_in_lk'], $account['is_active']),
            'price_level' => $account['price_level'],
            'credit' => (int)$account['credit'],
            'version' => $account['account_version'],
        ];

        if ($isWithApplications) {
            $data['applications'] = $this->_getPlatformaServicesCleaned($account); 
        }

        return $data;
    }

    /**
     * Получение блокировок в ЛС
     * по супер-клиентам и ЛС
     *
     * @param array|null $superIds
     * @param array|null $accountIds
     * @return array
     */
    public function getAccountsLocks($superIds = null, $accountIds = null)
    {
        if (!$superIds && !$accountIds) {
            throw new InvalidParamException('Параметры не заданны');
        }

        if (!$accountIds) {
            $accountIds = array_column(
                ClientAccount::find()
                    ->where(['super_id' => $superIds])
                    ->select('id')
                    ->asArray()
                    ->all(),
                'id');
        }

        if (!$accountIds) {
            return [];
        }

        if (!is_array($accountIds)) {
            $accountIds = [$accountIds];
        }

        $data = [];
        $isLoadComplete = true;
        foreach ($accountIds as $accountId) {


            try {
                Locks::setPgTimeout(Locks::PG_ACCOUNT_TIMEOUT);
                $lock = Locks::getLock($accountId);
            } catch (\Exception $e) {
                $isLoadComplete = false;
                $lock = [];
            }

            $data[$accountId] = [
                'account_id' => $accountId,
                'is_finance_block' => isset($lock['b_is_finance_block']) && $lock['b_is_finance_block'],
                'is_overran_block' =>
                    (isset($lock['b_is_overran']) && $lock['b_is_overran'])
                    || (isset($lock['b_is_mn_overran']) && $lock['b_is_mn_overran']),
            ];
        }

        return ['is_load_complete' => $isLoadComplete, 'data' => $data];
    }

    /**
     * Возвращает массив Id супер-клиента по известным параметрам
     *
     * (для функции get-full-client-struct)
     *
     * @param integer|array $superId
     * @param string $superName
     * @param integer $contractId
     * @param integer $contragentId
     * @param string $contragentName
     * @param integer $accountId
     * @return int[]
     */
    public function getSuperIds($superId = null, $superName = null, $contractId = null, $contragentId = null, $contragentName = null, $accountId = null)
    {
        if ($superId) {
            return is_array($superId) ? $superId : [$superId];
        }

        if ($superName) {
            return ClientSuper::find()
                ->select('id')
                ->andWhere(['name' => $superName])
                ->column();
        }

        if ($contragentId) {
            return ClientContragent::find()
                ->select('super_id')
                ->distinct()
                ->where(['id' => $contragentId])
                ->column();

        }

        if ($contragentName) {
            return ClientContragent::find()
                ->select('super_id')
                ->where(['name' => $contragentName])
                ->groupBy('super_id')
                ->column();
        }

        if ($contractId) {
            return ClientContract::find()
                ->select('super_id')
                ->distinct()
                ->where(['id' => $contractId])
                ->column();
        }

        if ($accountId) {
            return ClientAccount::find()
                ->select('super_id')
                ->distinct()
                ->where(['id' => $accountId])
                ->column();
        }

        return [];
    }

    /**
     * Возвращает массив продуктов и их статус у клиента
     *
     * @param array $client
     * @return array
     */
    private function _getPlatformaServicesCleaned($client)
    {
        return array_map(function ($row) {
            $row['id'] = (int)$row['id'];
            $row['is_enabled'] = (bool)$row['is_enabled'];
            return $row;
        },
            \Yii::$app->db->createCommand("
                SELECT 
                    `usage_voip`.`id` AS `id`,
                    'phone' AS `name`,
                    (CAST(NOW() AS DATE) BETWEEN `actual_from` AND `actual_to`) AS `is_enabled` 
                FROM `usage_voip`  
                WHERE client = :client
                
                UNION ALL
                
                SELECT 
                    `uu_account_tariff`.id AS `id`, 
                    'phone' AS `name`, 
                    tariff_period_id IS NOT NULL 
                FROM `uu_account_tariff` 
                WHERE client_account_id = :account_id AND service_type_id = :voipServiceId
                 
                UNION ALL 
                SELECT 
                    `usage_virtpbx`.`id` AS `id`,
                    'vpbx' AS `name`,
                    (CAST(NOW() AS DATE) BETWEEN `actual_from` AND `actual_to`) AS `is_enabled` 
                FROM `usage_virtpbx`
                WHERE client = :client
                  
                UNION ALL
                 
                SELECT 
                    `uu_account_tariff`.id AS `id`, 
                    'vpbx' AS `name`, 
                    tariff_period_id IS NOT NULL 
                FROM `uu_account_tariff` 
                WHERE client_account_id = :account_id AND service_type_id = :vpbxServiceId
                 
                UNION ALL 
                
                SELECT 
                    `usage_call_chat`.`id` AS `id`,
                    'feedback' AS `name`,
                    (CAST(NOW() AS DATE) BETWEEN `actual_from` AND `actual_to`) AS `is_enabled` 
                FROM `usage_call_chat`
                WHERE client = :client
                                 
                UNION ALL 
                
                SELECT 
                    `uu_account_tariff`.id AS `id`, 
                    'feedback' AS `name`, 
                    tariff_period_id IS NOT NULL 
                FROM `uu_account_tariff` 
                WHERE client_account_id = :account_id AND service_type_id = :callChatServiceId
                
        ", [
                    ':client' => $client['client'],
                    ':account_id' => $client['id'],
                    ':voipServiceId' => ServiceType::ID_VOIP,
                    ':vpbxServiceId' => ServiceType::ID_VPBX,
                    ':callChatServiceId' => ServiceType::ID_CALL_CHAT,
                ]
            )->queryAll());
    }

    /**
     * Типизация вывода
     *
     * @param array $data
     */
    private function _setTrueTypes(&$data)
    {
        foreach ($data as &$superClient) {
            foreach ($superClient['contragents'] as &$contragent) {
                $this->_setArrayValuesAsInteger($contragent, ['id', 'country_id']);

                foreach ($contragent['contracts'] as &$contract) {
                    $this->_setArrayValuesAsInteger($contract, ['id', 'partner_id', 'can_login_as_clients', 'partner_login_allow', 'business_id', 'business_process_id', 'business_process_status_id']);

                    foreach ($contract['accounts'] as &$account) {
                        $this->_setArrayValuesAsInteger($account, ['id', 'version']);

                        foreach ($account['applications'] as &$application) {
                            $this->_setArrayValuesAsInteger($application, ['id']);
                        }
                    }
                }
            }
        }
    }

    /**
     * Конвертируем значения масиива в integer
     *
     * @param array $array
     * @param string[] $keys
     */
    private function _setArrayValuesAsInteger(&$array, $keys)
    {
        foreach ($keys as $key) {
            if (isset($array[$key])) {
                $array[$key] = (int)$array[$key];
            }
        }
    }
}
