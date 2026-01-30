<?php

namespace app\controllers\api\internal;

use app\classes\ApiInternalController;
use app\classes\Assert;
use app\dao\ClientSuperDao;
use app\exceptions\api\internal\PartnerNotFoundException;
use app\exceptions\ModelValidationException;
use app\exceptions\web\BadRequestHttpException;
use app\forms\client\ClientCreateExternalForm;
use app\helpers\DateTimeZoneHelper;
use app\models\BusinessProcessStatus;
use app\models\ClientAccount;
use app\models\ClientContact;
use app\models\ClientContract;
use app\models\ClientContragent;
use app\models\ClientSuper;
use app\models\Business;
use app\models\BusinessProcess;
use app\models\Country;
use app\models\danycom\Address;
use app\models\danycom\Info;
use app\models\danycom\Number;
use app\models\EntryPoint;
use app\models\EventQueue;
use app\models\Organization;
use app\models\OrganizationSettlementAccount;
use app\models\Timezone;
use app\models\usages\UsageInterface;
use app\modules\uu\models\AccountTariff;
use Exception;
use Psr\Log\InvalidArgumentException;
use Yii;
use yii\db\Query;
use yii\helpers\ArrayHelper;

class ClientController extends ApiInternalController
{
    /**
     * @SWG\Definition(definition="contract", type="object", required={"id","number","accounts"},
     *   @SWG\Property(property="id", type="integer", description="Идентификатор договора"),
     *   @SWG\Property(property="number", type="string", description="Номер договора"),
     *   @SWG\Property(property="accounts", type="array", description="Массив идентификаторов лицевых счетов", @SWG\Items(type="integer"))
     * ),
     * @SWG\Definition(definition="contragent", type="object", required={"id","name","contracts"},
     *   @SWG\Property(property="id", type="integer", description="Идентификатор контрагента"),
     *   @SWG\Property(property="name", type="string", description="Название контрагента"),
     *   @SWG\Property(property="contracts", type="array", description="Массив договоров", @SWG\Items(ref="#/definitions/contract"))
     * ),
     * @SWG\Definition(definition="client", type="object", required={"id","name","contragents"},
     *   @SWG\Property(property="id", type="integer", description="Идентификатор супер-клиента"),
     *   @SWG\Property(property="name", type="string", description="Название супер-клиента"),
     *   @SWG\Property(property="contragents", type="array", description="Массив контрагентов", @SWG\Items(ref="#/definitions/contragent"))
     * ),
     * @SWG\Post(tags={"Работа с клиентами"}, path="/internal/client/", summary="Получение данных по клиенту", operationId="Получение данных по клиенту",
     *   @SWG\Parameter(name="client_id", type="integer", description="идентификатор супер-клиента", in="formData", default=""),
     *   @SWG\Response(response=200, description="данные о клиенте", @SWG\Schema(ref="#/definitions/client")),
     *   @SWG\Response(response="default", description="Ошибки", @SWG\Schema(ref="#/definitions/error_result"))
     * )
     */
    public function actionIndex()
    {
        $superId = isset($this->requestData['client_id']) ? $this->requestData['client_id'] : null;

        if (!$superId) {
            throw new BadRequestHttpException;
        }

        if ($superId && ($super = ClientSuper::findOne(['id' => $superId]))
        ) {

            $contragents = [];
            foreach ($super->contragents as $c) {
                $contracts = [];
                foreach ($c->contracts as $cc) {
                    $accounts = [];
                    foreach ($cc->accounts as $a) {
                        $accounts[] = $a->id;
                    }

                    $contracts[] = ['id' => $cc->id, 'number' => $cc->number, 'accounts' => $accounts];
                }

                $contragents[] = [
                    'id' => $c->id,
                    'name' => $c->name,
                    'contracts' => $contracts
                ];
            }

            $data = [
                'name' => $super->name,
                'id' => $super->id,
                'contragents' => $contragents
            ];

            return $data;
        } else {
            throw new BadRequestHttpException;
        }
    }

    /**
     * @SWG\Definition(definition="get-client-struct-applications", type="object", required={"id","name","enabled"},
     *   @SWG\Property(property="id", type="integer", description="Идентификатор"),
     *   @SWG\Property(property="name", type="string", description="Название"),
     *   @SWG\Property(property="enabled", type="boolean", description="Признак отключенного")
     * ),
     * @SWG\Definition(definition="get-client-struct-account", type="object", required={"id","is_partner","is_disabled","applications"},
     *   @SWG\Property(property="id", type="integer", description="Идентификатор ЛС"),
     *   @SWG\Property(property="partner_id", type="integer", description="Идентификатор договора партнера"),
     *   @SWG\Property(property="can_login_as_clients", type="boolean", description="Признак доступности ЛК"),
     *   @SWG\Property(property="is_partner", type="boolean", description="Признак партнерского договора"),
     *   @SWG\Property(property="is_show_in_lk", type="boolean", description="Показывать ЛС в ЛК"),
     *   @SWG\Property(property="partner_login_allow", type="boolean", description="Разрешен доступ в ЛК для партнера-родителя"),
     *   @SWG\Property(property="is_disabled", type="boolean", description="Признак отключенного"),
     *   @SWG\Property(property="version", type="integer", description="Версия биллера ЛС"),
     *   @SWG\Property(property="applications", type="array", description="Массив приложений", @SWG\Items(ref="#/definitions/get-client-struct-applications"))
     * ),
     * @SWG\Definition(definition="get-client-struct-contragent", type="object", required={"id","name","country","accounts"},
     *   @SWG\Property(property="id", type="integer", description="Идентификатор контрагента"),
     *   @SWG\Property(property="name", type="string", description="Имя контагента"),
     *   @SWG\Property(property="country", type="string", description="Страна"),
     *   @SWG\Property(property="accounts", type="array", description="Массив ЛС", @SWG\Items(ref="#/definitions/get-client-struct-account"))
     * ),
     * @SWG\Definition(definition="get-client-struct", type="object", required={"id","name","timezone","contragents"},
     *   @SWG\Property(property="id", type="integer", description="Идентификатор супер-клиента"),
     *   @SWG\Property(property="name", type="string", description="Название супер-клиента"),
     *   @SWG\Property(property="timezone", type="string", description="Таймзона"),
     *   @SWG\Property(property="contragents", type="array", description="Массив контрагентов", @SWG\Items(ref="#/definitions/get-client-struct-contragent"))
     * ),
     * @SWG\Post(tags={"Работа с клиентами"}, path="/internal/client/get-client-struct/", summary="Получение структуры клиента", operationId="Получение структуры клиента",
     *   @SWG\Parameter(name="id", type="integer", description="идентификатор супер-клиента", in="formData", default=""),
     *   @SWG\Parameter(name="name", type="string", description="имя супер-клиента", in="formData", default=""),
     *   @SWG\Parameter(name="contragent_id", type="integer", description="идентификатор контрагента", in="formData", default=""),
     *   @SWG\Parameter(name="contragent_name", type="string", description="имя контрагента", in="formData", default=""),
     *   @SWG\Parameter(name="account_id", type="integer", description="идентификатор ЛС", in="formData", default=""),
     *   @SWG\Response(response=200, description="данные о клиенте", @SWG\Schema(ref="#/definitions/get-client-struct")),
     *   @SWG\Response(response="default", description="Ошибки", @SWG\Schema(ref="#/definitions/error_result"))
     * )
     *
     * @param int $id
     * @param string $name
     * @param int $contragent_id
     * @param string $contragent_name
     * @param int $account_id
     * @return array|bool
     */
    public function actionGetClientStruct(
        $id = null,
        $name = null,
        $contragent_id = null,
        $contragent_name = null,
        $account_id = null
    )
    {

        foreach (['id', 'name', 'contragent_id', 'contragent_name', 'account_id'] as $var) {
            $$var = isset($this->requestData[$var]) ? $this->requestData[$var] : null;
        }

        $ids = [];
        if (empty($id)) {
            if (empty($name)) {
                if (empty($contragent_id)) {
                    if (empty($contragent_name)) {
                        if (empty($account_id)) {
                            return false;
                        } else {
                            $account = ClientAccount::findOne(['id' => $account_id]);
                            $ids[] = $account->super_id;
                        }
                    } else {
                        $ids = array_keys(ClientContragent::find()->andWhere(['name' => $contragent_name])->indexBy('super_id')->all());
                    }
                } else {
                    $contragent = ClientContragent::findOne(['id' => $contragent_id]);
                    $ids[] = $contragent->super_id;
                }
            } else {
                $ids = array_keys(ClientSuper::find()->andWhere(['name' => $name])->indexBy('id')->all());
            }
        } else {
            $ids[] = $id;
        }

        $fullResult = [];
        foreach ($ids as $idTmp) {
            $super = ClientSuper::find()->where(['id' => $idTmp])->with('contragents')->with('contracts')->with('accounts')->one();

            $timezone = DateTimeZoneHelper::TIMEZONE_MOSCOW;

            $resultContragents = [];

            /** @var ClientContragent $contragent */
            foreach ($super->contragents as $contragent) {
                $resultAccounts = [];
                /** @var ClientContract $contract */
                foreach ($contragent->contracts as $contract) {
                    /** @var ClientAccount $account */
                    foreach ($contract->accounts as $account) {
                        $resultAccounts[] = [
                            'id' => $account->id,
                            'is_disabled' => $contract->business_process_status_id != BusinessProcessStatus::TELEKOM_MAINTENANCE_WORK,
                            'partner_id' => $contract->isPartnerAgent(),
                            'can_login_as_clients' => $contract->is_lk_access,
                            'is_partner' => $contract->isPartner(),
                            'is_show_in_lk' => $account->is_show_in_lk,
                            'partner_login_allow' => $contract->is_partner_login_allow,
                            'version' => $account->account_version,
                            'applications' => $this->_getPlatformaServices($account->client)
                        ];
                        $timezone = $account->timezone_name;
                    }
                }

                if ($resultAccounts) {
                    $resultContragents[] = [
                        'id' => $contragent->id,
                        'name' => $contragent->name,
                        'country' => $contragent->country->alpha_3,
                        'accounts' => $resultAccounts
                    ];
                }
            }

            if ($resultContragents) {
                $fullResult[] = [
                    'id' => $super->id,
                    'timezone' => $timezone,
                    'name' => $super->name,
                    'contragents' => $resultContragents
                ];
            }
        }

        return $fullResult;
    }

    /**
     * @SWG\Definition(definition="get-full-client-struct-applications", type="object", required={"id","name","is_enabled"},
     *   @SWG\Property(property="id", type="integer", description="Идентификатор"),
     *   @SWG\Property(property="name", type="string", description="Название"),
     *   @SWG\Property(property="is_enabled", type="boolean", description="Признак включенного")
     * ),
     *
     * @SWG\Definition(definition="get-full-client-struct-account", type="object", required={"id","is_disabled","version","applications"},
     *   @SWG\Property(property="id", type="integer", description="Идентификатор ЛС"),
     *   @SWG\Property(property="is_disabled", type="boolean", description="Признак отключенного"),
     *   @SWG\Property(property="is_blocked", type="boolean", description="ЛС заблокирован полностью"),
     *   @SWG\Property(property="is_finance_block", type="boolean", description="Финансовая блокировка"),
     *   @SWG\Property(property="is_overran_block", type="boolean", description="Блокировка по превышению дневных лимитов"),
     *   @SWG\Property(property="is_bill_pay_overdue", type="boolean", description="Блокировка по неоплате счета"),
     *   @SWG\Property(property="is_postpaid", type="integer", description="Тип оплаты (0 - Prepaid, 1 - Postpaid, 2 - Prepaid 2.0)"),
     *   @SWG\Property(property="is_show_in_lk", type="boolean", description="Показывать ЛС в ЛК"),
     *   @SWG\Property(property="credit", type="integer", description="Лимит кредита"),
     *   @SWG\Property(property="version", type="integer", description="Версия биллера ЛС"),
     *   @SWG\Property(property="applications", type="array", description="Массив приложений", @SWG\Items(ref="#/definitions/get-full-client-struct-applications"))
     * ),
     *
     * @SWG\Definition(definition="get-full-client-struct-contract", type="object", required={"id","number","state","is_partner","accounts"},
     *   @SWG\Property(property="id", type="integer", description="Идентификатор договора"),
     *   @SWG\Property(property="number", type="string", description="Номер договора"),
     *   @SWG\Property(property="state", type="string", description="Состояние договора"),
     *   @SWG\Property(property="can_login_as_clients", type="boolean", description="Признак доступности ЛК"),
     *   @SWG\Property(property="partner_id", type="integer", description="Идентификатор договора партнера"),
     *   @SWG\Property(property="is_partner", type="boolean", description="Признак партнерского договора"),
     *   @SWG\Property(property="partner_login_allow", type="boolean", description="Разрешен доступ в ЛК для партнера-родителя"),
     *   @SWG\Property(property="business_id", type="integer", description="Идентификатор типа договора"),
     *   @SWG\Property(property="business_process_id", type="integer", description="Идентификатор подразделения"),
     *   @SWG\Property(property="business_process_status_id", type="integer", description="Идентификатор статуса договора"),
     *   @SWG\Property(property="accounts", type="array", description="Массив ЛС", @SWG\Items(ref="#/definitions/get-full-client-struct-account"))
     * ),
     *
     * @SWG\Definition(definition="get-full-client-struct-contragent", type="object", required={"id","name","country","contracts"},
     *   @SWG\Property(property="id", type="integer", description="Идентификатор контрагента"),
     *   @SWG\Property(property="name", type="string", description="Имя контагента"),
     *   @SWG\Property(property="country", type="string", description="Страна"),
     *   @SWG\Property(property="contracts", type="array", description="Массив ЛС", @SWG\Items(ref="#/definitions/get-full-client-struct-contract"))
     * ),
     *
     * @SWG\Definition(definition="get-full-client-struct", type="object", required={"id","name","timezone","contragents"},
     *   @SWG\Property(property="id", type="integer", description="Идентификатор супер-клиента"),
     *   @SWG\Property(property="name", type="string", description="Название супер-клиента"),
     *   @SWG\Property(property="timezone", type="string", description="Таймзона"),
     *   @SWG\Property(property="contragents", type="array", description="Массив контрагентов", @SWG\Items(ref="#/definitions/get-full-client-struct-contragent"))
     * ),
     *
     * @SWG\Post(tags={"Работа с клиентами"}, path="/internal/client/get-full-client-struct/", summary="Получение полной структуры клиента", operationId="Получение полной структуры клиента",
     *   @SWG\Parameter(name="id", type="integer", description="идентификатор супер-клиента", in="formData", default=""),
     *   @SWG\Parameter(name="name", type="string", description="имя супер-клиента", in="formData", default=""),
     *   @SWG\Parameter(name="contract_id[0]", type="integer", description="идентификатор договора", in="formData", default=""),
     *   @SWG\Parameter(name="contract_id[1]", type="integer", description="идентификатор договора", in="formData", default=""),
     *   @SWG\Parameter(name="contragent_id[0]", type="integer", description="идентификатор контрагента", in="formData", default=""),
     *   @SWG\Parameter(name="contragent_id[1]", type="integer", description="идентификатор контрагента", in="formData", default=""),
     *   @SWG\Parameter(name="contragent_name", type="string", description="имя контрагента", in="formData", default=""),
     *   @SWG\Parameter(name="account_id", type="integer", description="идентификатор ЛС", in="formData", default=""),
     *   @SWG\Response(response=200, description="данные о клиенте", @SWG\Schema(ref="#/definitions/get-full-client-struct")),
     *   @SWG\Response(response="default", description="Ошибки", @SWG\Schema(ref="#/definitions/error_result"))
     * )
     */
    public function actionGetFullClientStruct()
    {
        $params = [];
        foreach (['id', 'name', 'contract_id', 'contragent_id', 'contragent_name', 'account_id'] as $value) {
            $params[$value] = isset($this->requestData[$value]) ? $this->requestData[$value] : null;
        }

        $superIds = ClientSuper::dao()->getSuperIds($params['id'], $params['name'], $params['contract_id'], $params['contragent_id'], $params['contragent_name'], $params['account_id']);

        return ClientSuper::dao()->getSuperClientStructByIds($superIds);
    }


    /**
     * @SWG\Definition(definition="get-super-client-struct-applications", type="object", required={"id","name","is_enabled"},
     *   @SWG\Property(property="id", type="integer", description="Идентификатор"),
     *   @SWG\Property(property="name", type="string", description="Название"),
     *   @SWG\Property(property="is_enabled", type="boolean", description="Признак включенного")
     * ),
     *
     * @SWG\Definition(definition="get-super-client-struct-account", type="object", required={"id","is_disabled","version","applications"},
     *   @SWG\Property(property="id", type="integer", description="Идентификатор ЛС"),
     *   @SWG\Property(property="is_disabled", type="boolean", description="Признак отключенного"),
     *   @SWG\Property(property="is_blocked", type="boolean", description="ЛС заблокирован полностью"),
     *   @SWG\Property(property="is_bill_pay_overdue", type="boolean", description="Блокировка по неоплате счета"),
     *   @SWG\Property(property="is_postpaid", type="integer", description="Тип оплаты (0 - Prepaid, 1 - Postpaid, 2 - Prepaid 2.0)"),
     *   @SWG\Property(property="credit", type="integer", description="Лимит кредита"),
     *   @SWG\Property(property="version", type="integer", description="Версия биллера ЛС"),
     *   @SWG\Property(property="applications", type="array", description="Массив приложений", @SWG\Items(ref="#/definitions/get-super-client-struct-applications"))
     * ),
     *
     * @SWG\Definition(definition="get-super-client-struct-contract", type="object", required={"id","number","state","is_partner","accounts"},
     *   @SWG\Property(property="id", type="integer", description="Идентификатор договора"),
     *   @SWG\Property(property="number", type="string", description="Номер договора"),
     *   @SWG\Property(property="state", type="string", description="Состояние договора"),
     *   @SWG\Property(property="can_login_as_clients", type="boolean", description="Признак доступности ЛК"),
     *   @SWG\Property(property="partner_id", type="integer", description="Идентификатор договора партнера"),
     *   @SWG\Property(property="is_partner", type="boolean", description="Признак партнерского договора"),
     *   @SWG\Property(property="partner_login_allow", type="boolean", description="Разрешен доступ в ЛК для партнера-родителя"),
     *   @SWG\Property(property="business_id", type="integer", description="Идентификатор типа договора"),
     *   @SWG\Property(property="business_process_id", type="integer", description="Идентификатор подразделения"),
     *   @SWG\Property(property="business_process_status_id", type="integer", description="Идентификатор статуса договора"),
     *   @SWG\Property(property="accounts", type="array", description="Массив ЛС", @SWG\Items(ref="#/definitions/get-super-client-struct-account"))
     * ),
     *
     * @SWG\Definition(definition="get-super-client-struct-contragent", type="object", required={"id","name","country","contracts"},
     *   @SWG\Property(property="id", type="integer", description="Идентификатор контрагента"),
     *   @SWG\Property(property="name", type="string", description="Имя контагента"),
     *   @SWG\Property(property="country", type="string", description="Страна"),
     *   @SWG\Property(property="contracts", type="array", description="Массив ЛС", @SWG\Items(ref="#/definitions/get-super-client-struct-contract"))
     * ),
     *
     * @SWG\Definition(definition="get-super-client-struct", type="object", required={"id","name","timezone","contragents"},
     *   @SWG\Property(property="id", type="integer", description="Идентификатор супер-клиента"),
     *   @SWG\Property(property="name", type="string", description="Название супер-клиента"),
     *   @SWG\Property(property="timezone", type="string", description="Таймзона"),
     *   @SWG\Property(property="contragents", type="array", description="Массив контрагентов", @SWG\Items(ref="#/definitions/get-super-client-struct-contragent"))
     * ),
     *
     * @SWG\Post(tags={"Работа с клиентами"}, path="/internal/client/get-super-client-struct/", summary="Получение полной структуры клиента без блокировок", operationId="Получение полной структуры клиента без блокировок",
     *   @SWG\Parameter(name="id", type="integer", description="идентификатор супер-клиента", in="formData", default=""),
     *   @SWG\Parameter(name="name", type="string", description="имя супер-клиента", in="formData", default=""),
     *   @SWG\Parameter(name="contract_id[0]", type="integer", description="идентификатор договора", in="formData", default=""),
     *   @SWG\Parameter(name="contract_id[1]", type="integer", description="идентификатор договора", in="formData", default=""),
     *   @SWG\Parameter(name="contragent_id[0]", type="integer", description="идентификатор контрагента", in="formData", default=""),
     *   @SWG\Parameter(name="contragent_id[1]", type="integer", description="идентификатор контрагента", in="formData", default=""),
     *   @SWG\Parameter(name="contragent_name", type="string", description="имя контрагента", in="formData", default=""),
     *   @SWG\Parameter(name="account_id", type="integer", description="идентификатор ЛС", in="formData", default=""),
     *   @SWG\Response(response=200, description="данные о клиенте", @SWG\Schema(ref="#/definitions/get-super-client-struct")),
     *   @SWG\Response(response="default", description="Ошибки", @SWG\Schema(ref="#/definitions/error_result"))
     * )
     */
    public function actionGetSuperClientStruct()
    {
        $params = [];
        foreach (['id', 'name', 'contract_id', 'contragent_id', 'contragent_name', 'account_id'] as $value) {
            $params[$value] = isset($this->requestData[$value]) ? $this->requestData[$value] : null;
        }

        $superIds = ClientSuper::dao()->getSuperIds($params['id'], $params['name'], $params['contract_id'], $params['contragent_id'], $params['contragent_name'], $params['account_id']);

        return ClientSuper::dao()->getSuperClientStructByIds($superIds, ClientSuperDao::STRUCT_CLIENT_STRUCT);
    }


    /**
     * @SWG\Definition(definition="get-accounts-locks-data", type="object", required={"account_id","is_finance_block","is_overran_block"},
     *   @SWG\Property(property="account_id", type="integer", description="Идентификатор ЛС"),
     *   @SWG\Property(property="is_finance_block", type="boolean", description="Финансовая блокировка"),
     * ),
     *
     * @SWG\Definition(definition="get-accounts-locks", type="object", required={"is_load_complete","data"},
     *   @SWG\Property(property="is_load_complete", type="boolean", description="Данные загруженны корректно"),
     *   @SWG\Property(property="data", type="array", description="Данные о блокировках ЛС", @SWG\Items(ref="#/definitions/get-accounts-locks-data"))
     * ),
     *
     * @SWG\Post(tags={"Работа с клиентами"}, path="/internal/client/get-accounts-locks", summary="Получение блокировок ЛС", operationId="Получение блокировок ЛС",
     *   @SWG\Parameter(name="super_id", type="integer", description="идентификатор супер-клиента", in="formData", default=""),
     *   @SWG\Parameter(name="account_id", type="integer", description="идентификатор ЛС", in="formData", default=""),
     *   @SWG\Response(response=200, description="данные о блокировках", @SWG\Schema(ref="#/definitions/get-accounts-locks")),
     *   @SWG\Response(response="default", description="Ошибки", @SWG\Schema(ref="#/definitions/error_result"))
     * )
     */
    public function actionGetAccountsLocks()
    {
        $superId = isset($this->requestData['super_id']) ? $this->requestData['super_id'] : null;
        $accountId = isset($this->requestData['account_id']) ? $this->requestData['account_id'] : null;

        return ClientSuper::dao()->getAccountsLocks($superId, $accountId);
    }

    /**
     * Возвращает массив продуктов и их статус у клиента
     *
     * @param int $client
     * @return array
     * @throws \yii\db\Exception
     */
    private function _getPlatformaServices($client)
    {
        return array_map(function ($row) {
            $row['id'] = (int)$row['id'];
            $row['is_enabled'] = (bool)$row['enabled'];
            return $row;
        },
            Yii::$app->db->createCommand("		
                SELECT 		
                    `usage_voip`.`client` AS `client`,		
                    `usage_voip`.`id` AS `id`,		
                    'phone' AS `name`,		
                    ((`usage_voip`.`actual_from` <= now()) AND (`usage_voip`.`actual_to` >= now())) AS `enabled` 		
                FROM `usage_voip`  		
                WHERE client = :client		
                		
                UNION ALL 		
                SELECT 		
                    `usage_virtpbx`.`client` AS `client`,		
                    `usage_virtpbx`.`id` AS `id`,		
                    'vpbx' AS `name`,		
                    ((`usage_virtpbx`.`actual_from` <= now()) AND (`usage_virtpbx`.`actual_to` >= now())) AS `enabled` 		
                FROM `usage_virtpbx`		
                WHERE client = :client		
                  		
                UNION ALL 		
                		
                SELECT 		
                    `usage_call_chat`.`client` AS `client`,		
                    `usage_call_chat`.`id` AS `id`,		
                    'feedback' AS `name`,		
                    ((`usage_call_chat`.`actual_from` <= now()) AND (`usage_call_chat`.`actual_to` >= now())) AS `enabled` 		
                FROM `usage_call_chat`		
                WHERE client = :client		
        ", [":client" => $client])->queryAll());
    }

    /**
     * @SWG\Post(tags={"Работа с клиентами"}, path="/internal/client/create/", summary="Создание клиента", operationId="client-create",
     *   @SWG\Parameter(name="company", type="string", description="Название клиента", in="formData", default="Клиент без названия"),
     *   @SWG\Parameter(name="address", type="string", description="Адрес", in="formData", default=""),
     *   @SWG\Parameter(name="partner_id", type="integer", description="ID партнёра", in="formData", default=""),
     *   @SWG\Parameter(name="contact_phone", type="string", description="Контактный телефон", in="formData", default=""),
     *   @SWG\Parameter(name="official_phone", type="string", description="Телефон организации", in="formData", default=""),
     *   @SWG\Parameter(name="fio", type="string", description="Ф.И.О. контактного лица", in="formData", default=""),
     *   @SWG\Parameter(name="fax", type="string", description="Факс", in="formData", default=""),
     *   @SWG\Parameter(name="email", type="string", description="Email", in="formData", default="", required=true),
     *   @SWG\Parameter(name="comment", type="string", description="Комментарий к заказу", in="formData", default=""),
     *   @SWG\Parameter(name="timezone", type="string", description="Таймзона лицевого счета", in="formData", default="Europe/Moscow"),
     *   @SWG\Parameter(name="country_id", type="integer", description="Код страны подключения (ISO)", in="formData", default="643"),
     *   @SWG\Parameter(name="site_name", type="string", description="С какого сайта пришел клиент", in="formData", default=""),
     *   @SWG\Parameter(name="vats_tariff_id", type="integer", description="ID тарифа для ВАТС", in="formData", default=""),
     *   @SWG\Parameter(name="entry_point_id", type="string", description="ID (code) точки входа", in="formData", default="RU1"),
     *   @SWG\Parameter(name="legal_type", type="string", description="Тип юр лица", in="formData", default=""),
     *   @SWG\Parameter(name="utm_parameters", type="string", description="UTM-метки", in="formData", default=""),
     *   @SWG\Parameter(name="roistat_visit", type="integer", description="Roistat visit", in="formData", default=""),
     *   @SWG\Parameter(name="is_create_lk", type="integer", description="Создаем ЛК?", in="formData", default="1"),
     *
     *   @SWG\Response(response=200, description="данные о созданном клиенте",
     *     @SWG\Schema(type="object", required={"id","name","contragents"},
     *       @SWG\Property(property="client_id", type="integer", description="Идентификатор супер-клиента"),
     *       @SWG\Property(property="contragent_id", type="integer", description="Идентификатор контрагента"),
     *       @SWG\Property(property="contract_id", type="integer", description="Идентификатор договора"),
     *       @SWG\Property(property="account_id", type="integer", description="Идентификатор ЛС"),
     *       @SWG\Property(property="is_created", type="boolean", description="Создан ли клиент")
     *     )
     *   ),
     *   @SWG\Response(response="default", description="Ошибки",
     *     @SWG\Schema(ref="#/definitions/error_result")
     *   )
     * )
     */
    public function actionCreate()
    {
        $form = new ClientCreateExternalForm;
        $form->setAttributes($this->requestData);

        if ($form->validate()) {
            if ($form->create()) {
                $data = [
                    'client_id' => $form->super_id,
                    'contragent_id' => $form->contragent_id,
                    'contract_id' => $form->contract_id,
                    'account_id' => $form->account_id,
                    'is_created' => $form->isCreated,
                ];

                $account = ClientAccount::findOne(['id' => $form->account_id]);

                if ($account->contract->isPartner()) {
                    $data += [
                        'is_partner' => true,
                        'partner_id' => $account->id
                    ];
                }

                if ($account->contract->isPartnerAgent()) {
                    $contract = ClientContract::findOne(['id' => $account->contract->partner_contract_id]);
                    $data += [
                        'is_partner_agent' => true,
                        'partner_id' => $contract->accounts[0]->id
                    ];
                }

                return $data;
            }
        } else {
            $fields = array_keys($form->errors);
            if ($fields[0] == 'partner_id') {
                throw new PartnerNotFoundException();
            } else {
                throw new Exception($form->errors[$fields[0]][0], 400);
            }
        }
    }

    /**
     * @SWG\Post(tags={"Работа с клиентами"}, path="/internal/client/set-timezone", summary="Устанавливаем таймзону клиента", operationId="Устанавливаем таймзону клиента",
     *   @SWG\Parameter(name="client_id", type="integer", description="ID (супер) клиента", in="formData", default=""),
     *   @SWG\Parameter(name="account_id", type="integer", description="ID лицевого счета", in="formData", default=""),
     *   @SWG\Parameter(name="timezone", type="string", enum={"Bad/Timezone", "Asia/Novosibirsk", "Asia/Vladivostok", "Asia/Yekaterinburg", "Europe/Budapest", "Europe/Moscow", "Europe/Samara", "Europe/Volgograd"}, description="Таймзона", in="formData", default="Europe/Moscow"),
     *   @SWG\Response(response=200, description="данные о созданном клиенте",
     *   ),
     *   @SWG\Response(response="default", description="Ошибки",
     *     @SWG\Schema(ref="#/definitions/error_result")
     *   )
     * )
     */
    public function actionSetTimezone()
    {
        $clientId = isset($this->requestData['client_id']) ? $this->requestData['client_id'] : null;
        $accountId = isset($this->requestData['account_id']) ? $this->requestData['account_id'] : null;
        $timezone = isset($this->requestData['timezone']) ? $this->requestData['timezone'] : null;

        if (!$clientId) {
            if ($accountId && $account = ClientAccount::findOne(['id' => $accountId])) {
                $clientId = $account->super_id;
            }
        }

        if (!$clientId) {
            throw new BadRequestHttpException('Клиент не найден');
        }

        $client = ClientSuper::findOne(['id' => $clientId]);

        if (!$client) {
            throw new BadRequestHttpException('Клиент не найден');
        }

        if (!in_array($timezone, \DateTimeZone::listIdentifiers())) {
            throw new BadRequestHttpException('Не найдена timezone');
        }

        ClientAccount::updateAll(['timezone_name' => $timezone], ['super_id' => $client->id]);

        return true;
    }

    /**
     * @SWG\Definition(definition = "timezones", type = "object", required = {"name"},
     *   @SWG\Property(property = "id", type = "string",description = "Код таймзоны"),
     *   @SWG\Property(property = "name", type = "string",description = "Название")
     * ),
     * @SWG\Get(tags = {"Dictionaries"}, path = "/internal/client/get-timezones/", summary = "Получение списка таймзон", operationId = "get-timezones",
     *   @SWG\Response(response = 200, description = "таймзоны",
     *     @SWG\Schema(type = "array", @SWG\Items(ref = "#/definitions/timezones")
     *     )
     *   ),
     *   @SWG\Response(response = "default", description = "Ошибки", @SWG\Schema(ref = "#/definitions/error_result"))
     * )
     */
    public function actionGetTimezones()
    {
        $tzs = Timezone::getList();
        return array_map(function ($key, $value) {
            return [
                'id' => $key,
                'name' => $value
            ];
        }, array_keys($tzs), $tzs);
    }

    /**
     * @SWG\Definition(definition = "business", type = "object", required = {"id", "name"},
     *   @SWG\Property(property = "id", type = "integer",description = "ID"),
     *   @SWG\Property(property = "name", type = "string",description = "Название подразделения")
     * ),
     * @SWG\Get(tags = {"Dictionaries"}, path = "/internal/client/get-business-list/", summary = "Получение списка подразделений", operationId = "get-business-list",
     *   @SWG\Response(response = 200, description = "данные о подразделениях",
     *     @SWG\Schema(type = "array", @SWG\Items(ref = "#/definitions/business")
     *     )
     *   ),
     *   @SWG\Response(response = "default", description = "Ошибки", @SWG\Schema(ref = "#/definitions/error_result"))
     * )
     */
    public function actionGetBusinessList()
    {
        return Business::find()
            ->select(['id', 'name'])
            ->orderBy(['sort' => SORT_ASC])
            ->asArray()
            ->all();
    }

    /**
     * @SWG\Definition(definition = "business_process", type = "object",required = {"id", "business_id", "name"},
     *   @SWG\Property(property = "id", type = "integer",description = "ID бизнес процесса"),
     *   @SWG\Property(property = "business_id", type = "integer",description = "ID подразделения"),
     *   @SWG\Property(property = "name", type = "string",description = "Название подразделения")
     * ),
     * @SWG\Get(tags = {"Dictionaries"}, path = "/internal/client/get-business-process-list/", summary = "Получение списка бизнес процессов", operationId = "get-business-process-list",
     *   @SWG\Response(response = 200, description = "данные о бизнес процессах",
     *     @SWG\Schema(type = "array", @SWG\Items(ref = "#/definitions/business_process")
     *     )
     *   ),
     *   @SWG\Response(response = "default", description = "Ошибки", @SWG\Schema(ref = "#/definitions/error_result"))
     * )
     */
    public function actionGetBusinessProcessList()
    {
        return BusinessProcess::find()
            ->select(['id', 'business_id', 'name'])
            ->where(['show_as_status' => '1'])
            ->orderBy([
                'business_id' => SORT_ASC,
                'sort' => SORT_ASC,
            ])
            ->asArray()
            ->all();
    }

    /**
     * @SWG\Definition(definition = "business_process_status", type = "object",required = {"id", "business_process_id", "name"},
     *   @SWG\Property(property = "id", type = "integer",description = "ID статуса"),
     *   @SWG\Property(property = "business_process_id", type = "integer",description = "ID бизнес процесса"),
     *   @SWG\Property(property = "name", type = "string",description = "Название статуса")
     * ),
     * @SWG\Get(tags = {"Dictionaries"}, path = "/internal/client/get-business-process-status-list/", summary = "Получение списка стаусов бизнес процессов", operationId = "get-business-process-status-list",
     *   @SWG\Response(response = 200, description = "данные о статусах бизнес процессов",
     *     @SWG\Schema(type = "array", @SWG\Items(ref = "#/definitions/business_process_status")
     *     )
     *   ),
     *   @SWG\Response(response = "default", description = "Ошибки", @SWG\Schema(ref = "#/definitions/error_result"))
     * )
     */
    public function actionGetBusinessProcessStatusList()
    {
        return BusinessProcessStatus::find()
            ->select(['id', 'business_process_id', 'name'])
            ->orderBy([
                'business_process_id' => SORT_ASC,
                'sort' => SORT_ASC,
            ])
            ->asArray()
            ->all();
    }

    /**
     * @SWG\Post(tags={"Работа с клиентами"}, path="/internal/client/form-ported-number/", summary="Форма с сайта: портированный номер", operationId="Форма с сайта: портированный номер",
     *   @SWG\Parameter(name="name", type="string", description="ФИО клиента", in="formData", default="Иванов Иван Иванович"),
     *   @SWG\Parameter(name="doc_args", type="string", description="Реквизиты паспорта (серия и номер)", in="formData", default=""),
     *   @SWG\Parameter(name="doc_issue", type="integer", description="Кем выдан паспорт", in="formData", default=""),
     *   @SWG\Parameter(name="doc_issue_date", type="string", description="Когда выдан паспорт", in="formData", default=""),
     *   @SWG\Parameter(name="birth", type="string", description="Дата рождения", in="formData", default=""),
     *   @SWG\Parameter(name="address", type="string", description="Адрес регистрации", in="formData", default=""),
     *   @SWG\Parameter(name="email", type="string", description="Email", in="formData", default="", required=true),
     *   @SWG\Parameter(name="phone", type="string", description="Контактный номер", in="formData", default=""),
     *   @SWG\Parameter(name="phone_port", type="string", description="Номера телефонов для портирования", in="formData", default=""),
     *   @SWG\Parameter(name="temp", type="string", description="Временный номер (Да/Нет)", in="formData", default=""),
     *   @SWG\Parameter(name="tariff", type="string", description="Тариф", in="formData", default=""),
     *   @SWG\Parameter(name="delivery", type="string", description="Варинат доставки", in="formData", default=""),
     *   @SWG\Parameter(name="delivery_address", type="string", description="Адрес доставки", in="formData", default=""),
     *   @SWG\Parameter(name="file_link", type="string", description="Ссылка на PDF-заявление", in="formData", default=""),
     *   @SWG\Parameter(name="client_account_id", type="integer", description="ЛС", in="formData", default=""),
     *   @SWG\Parameter(name="entry_point_id", type="integer", description="Точка входа", in="formData", default="MNP_RU_DANYCOM"),
     *   @SWG\Parameter(name="is_create_trouble", type="integer", description="Создавать заявку", in="formData", default="1"),
     *
     *   @SWG\Response(response=200, description="данные о созданном клиенте",
     *     @SWG\Schema(type="object", required={"id","name","contragents"},
     *       @SWG\Property(property="account_id", type="integer", description="Идентификатор ЛС-клиента"),
     *       @SWG\Property(property="is_created", type="boolean", description="Создан ли клиент")
     *     )
     *   ),
     *   @SWG\Response(response="default", description="Ошибки",
     *     @SWG\Schema(ref="#/definitions/error_result")
     *   )
     * )
     */

    /**
     * name ФИО
     * doc_args Реквизиты паспорта (серия и номер)
     * doc_issue Кем выдан паспорт
     * doc_issue_date Когда выдан паспорт
     * birth Дата рождения
     * address Адрес регистрации
     * email E-mail
     * phone Контактный номер
     * phone_port Номера телефонов для портирования
     */
    public function actionFormPortedNumber()
    {
        $params = [];

        $data = $this->requestData;;

        foreach (['name', 'doc_args', 'doc_issue', 'doc_issue_date', 'birth', 'address', 'email', 'phone', 'phone_port', 'temp', 'tariff', 'delivery', 'delivery_address', 'file_link', 'client_account_id', 'is_create_trouble'] as $value) {
            if (isset($data[$value])) {
                $params[$value] = preg_replace('/\s+/', ' ', htmlspecialchars(trim(strip_tags($data[$value])), ENT_NOQUOTES | ENT_HTML401));
                unset($data[$value]);
            } else {
                $params[$value] = null;
            }
        }

        $comment = '';
        foreach ($data as $k => $v) {
            $comment .= "\n" . $k . ': ' . $v;
        }

        $params['doc_args'] = preg_replace("/\D/", '', $params['doc_args']);
        $isCreate = false;
        $accountId = null;
        if (!$params['client_account_id']) {

            $form = new ClientCreateExternalForm;
            $form->setAttributes([
                'entry_point_id' => $data['entry_point_id'] ?? EntryPoint::MNP_RU_DANYCOM,
                'company' => $params['name'],
                'address' => $params['address'],
                'contact_phone' => $params['phone'],
                'fio' => $params['name'],
                'email' => $params['email'],
                'comment' => 'Порировать номер: ' . $params['phone_port'] . $comment,
                'is_create_trouble' => (int)(bool)($params['is_create_trouble'] ?? 0),
            ]);

            if ($form->validate()) {
                $isCreate = $form->create();
                $accountId = $form->account_id;
            } else {
                $fields = array_keys($form->errors);
                throw new Exception($form->errors[$fields[0]][0], 400);
            }
        } else {
            $accountId = $params['client_account_id'];
        }

        $account = ClientAccount::findOne(['id' => $accountId]);
        Assert::isObject($account, 'Account not found');

        if (preg_match_all("/\+?(79\d{9})/", $params['phone_port'], $m)) {
            $numbers = array_unique($m[1]);
        } else {
            throw new \LogicException('Bad number');
        }

        foreach ($numbers as $number) {
            if (!($numberModel = Number::find()->where([
                'account_id' => $accountId,
                'number' => $number
            ])->one())) {
                $numberModel = new Number;
                $numberModel->account_id = $accountId;
                $numberModel->number = $number;
            }

            $numberInfo = ['nnp_operator_id' => 0, 'nnp_region_id' => 0];

            try {
                $numberInfo = \app\models\Number::getNnpInfo($number);
            } catch (\Exception $e) {
                Yii::error($e);
            }

            $redis = \Yii::$app->redis;

            $numberModel->operator = $redis->get('operator:' . $numberInfo['nnp_operator_id']) ?: 'unknown';
            $numberModel->region = $redis->get('region:' . $numberInfo['nnp_region_id']) ?: 'unknown';

            if (!$numberModel->save()) {
                throw new ModelValidationException($numberModel);
            }

            EventQueue::go(EventQueue::PORTED_NUMBER_ADD, [
                'account_id' => $accountId,
                'number' => $number
            ]);
        }

        /** @var Info $infoModel */
        if (!($infoModel = Info::find()->where(['account_id' => $accountId])->one())) {
            $infoModel = new Info;
            $infoModel->account_id = $accountId;
        }

        $infoModel->temp = $params['temp'];
        $infoModel->tariff = $params['tariff'];
        $infoModel->delivery_type = $params['delivery'];
        $infoModel->file_link = $params['file_link'];

        if (!$infoModel->save()) {
            throw new ModelValidationException($infoModel);
        }

        /** @var Info $infoModel */
        if (!($addressModel = Address::find()->where(['account_id' => $accountId])->one())) {
            $addressModel = new Address;
            $addressModel->account_id = $accountId;
        }

        $addressModel->address = $params['delivery_address'];

        if (!$addressModel->save()) {
            throw new ModelValidationException($addressModel);
        }

        $contragent = $account->contragent;

        if (!$isCreate) {
            return [
                'client_id' => $accountId,
                'is_created' => $isCreate,
            ];
        }


        $contragent->legal_type = ClientContragent::PERSON_TYPE;
        $contragent->name = $account->contragent->name_full = $params['name'];
        if (!$contragent->save()) {
            throw new ModelValidationException($contragent);
        }

        $person = $account->contragent->person;

        $person->registration_address = $params['address'];
        $person->birthday = $params['birth'];
        $person->passport_serial = substr($params['doc_args'], 0, 4);
        $person->passport_number = substr($params['doc_args'], 4);;
        $person->passport_issued = $params['doc_issue'];
        $person->passport_date_issued = $params['doc_issue_date'];
        $fio = explode(' ', $params['name']);

        if ($fio) {
            isset($fio) && isset($fio[0]) && $person->last_name = $fio[0];
            isset($fio) && isset($fio[1]) && $person->first_name = $fio[1];
            isset($fio) && isset($fio[2]) && $person->middle_name = $fio[2];
        }

        if (!$person->save()) {
            throw new ModelValidationException($person);
        }

        return [
            'client_id' => $accountId,
            'is_created' => $isCreate,
        ];
    }


    /**
     * @SWG\Definition(
     *   definition="form-gosuslugi-address",
     *   type="object",
     *   @SWG\Property(property="addressStr",type="string",description="адрес"),
     *   @SWG\Property(property="countryId",type="string",description="Страна", default="RUS"),
     *   @SWG\Property(property="house",type="string",description="Дом"),
     *   @SWG\Property(property="zipCode",type="string",description="Индекс"),
     *   @SWG\Property(property="city",type="string",description="Город"),
     *   @SWG\Property(property="street",type="string",description="Улица"),
     *   @SWG\Property(property="region",type="string",description="Регион")
     * ),
     * @SWG\Definition(
     *   definition="form-gosuslugi-identity",
     *   type="object",
     *   @SWG\Property(property="type",type="string",description="Тип документа", default="RF_PASSPORT"),
     *   @SWG\Property(property="series",type="string",description="Серия документа"),
     *   @SWG\Property(property="number",type="string",description="Номер документа"),
     *   @SWG\Property(property="issueDate",type="string",description="дата выдачи докумена (30.01.1972)"),
     *   @SWG\Property(property="issueId",type="string",description="код подразделения"),
     *   @SWG\Property(property="issuedBy",type="string",description="кем выдан"),
     *   @SWG\Property(property="vrfStu",type="string",description="VERIFIED",default="VERIFIED")
     * ),
     * @SWG\Definition(
     *   definition="form-gosuslugi",
     *   type="object",
     *   @SWG\Property(property="account_id",type="integer",description="ЛС"),
     *   @SWG\Property(property="email",type="string",description="emial"),
     *   @SWG\Property(property="emailVerified",type="integer",description="email подтвержден? true/false",default=1),
     *   @SWG\Property(property="phone",type="string",description="контакт телефоный номер"),
     *   @SWG\Property(property="phoneVerified",type="integer",description="номер подтвержден? true/false",default=1),
     *   @SWG\Property(property="name",type="string",description="ФИО"),
     *   @SWG\Property(property="birthDate",type="string",description="Дата рождения (25.12.1980)"),
     *   @SWG\Property(property="birthPlace",type="string",description="Место рождения"),
     *   @SWG\Property(property="gender",type="string",description="Пол (М/Ж)"),
     *   @SWG\Property(property="citizenship",type="string",description="Страна",default="RUS"),
     *   @SWG\Property(property="inn",type="string",description="ИНН"),
     *   @SWG\Property(property="address",type="object",description="Адрес",ref = "#/definitions/form-gosuslugi-address"),
     *   @SWG\Property(property="identity",type="object",description="Идентификационные данные (паспорт)",ref = "#/definitions/form-gosuslugi-identity"),
     *   @SWG\Property(property="trusted",type="integer",description="Данные подтверждены? (true/false)",default=1)
     * )
     * @SWG\Post(tags={"Работа с клиентами"}, path="/internal/client/form-gosuslugi/", summary="Форма заполнения данных с ГосУслуг", operationId="Форма заполнения данных с ГосУслуг",
     *   @SWG\Parameter(name="",type="object",items="#/definitions/step1",description="структура сохранения данных с ГосУслуг",in="body",@SWG\Schema(ref = "#/definitions/form-gosuslugi")),
     *   @SWG\Response(
     *     response=200,
     *     description="форма персональных данным с GosUslugi",
     *     @SWG\Schema(
     *       ref="#/definitions/form-gosuslugi"
     *     )
     *   ),
     *   @SWG\Response(
     *     response="default",
     *     description="Ошибки",
     *     @SWG\Schema(
     *       ref="#/definitions/error_result"
     *     )
     *   )
     * )
     */
    public function actionFormGosuslugi()
    {
        $data = Yii::$app->request->bodyParams;

        if (!$data) {
            throw new InvalidArgumentException('Data not recognized');
        }

        isset($data['data']) && $data['data'] && $data = $data['data'];

        if (!$data) {
            throw new InvalidArgumentException('Data not recognized');
        }

        if (!is_array($data)) {
            if (!($data = json_decode($data, true))) {
                throw new InvalidArgumentException('Data not recognized');
            }
        }


        /*
                $data = [
                    'account_id' => 69911,
                    'email' => 'zzz@zzz.ru',
                    'emailVerified' => true,
                    'phone' => '+79252111111',
                    'phoneVerified' => false,
                    'name' => 'Тестеров Тест тестерович оглы первый',
                    'birthDate' => '09.11.1981',
                    'birthPlace' => 'Родился там-то',
                    'gender' => 'M',
                    'citizenship' => 'RUS',
                    'inn' => '470322313839',
                    'address' => [
                        'addressStr' => 'тут адрес',
                        'countryId' => 'RUS',
                        'house' => '123',
                        'zipCode' => '123123',
                        'city' => 'город',
                        'street' => 'улица',
                        'region' => 'регион'
                    ],
                    'identity' => [
                        'type' => 'RF_PASSPORT',
                        'series' => '1234',
                        'number' => '123456',
                        'issueDate' => '30.01.1972',
                        'issueId' => 'код подразделения',
                        'issuedBy' => 'тут кем выдан',
                        'vrfStu' => 'VERIFIED'
                    ],
                    'trusted' => true
                ];
        */

        /** @var ClientAccount $account */
        $account = null;
        if (!isset($data['account_id']) || !$data['account_id'] || !($account = ClientAccount::findOne(['id' => $data['account_id']]))) {
            throw new \InvalidArgumentException('account not found');
        }

//        if ($account->contract->business_process_status_id != BusinessProcessStatus::TELEKOM_MAINTENANCE_ORDER_OF_SERVICES) {
//            throw new \InvalidArgumentException('BPStatus check failed');
//        }

        foreach ([ClientContact::TYPE_EMAIL, ClientContact::TYPE_PHONE] as $contactType) {
            if (!$account->getContacts()->where([
                'type' => $contactType,
                'data' => $data[$contactType]
            ])->exists()) {
                $contact = new ClientContact(['client_id' => $account->id]);
                $contact->addContact($contactType, $data[$contactType]);
                $contact->is_official = 1;

                if (!$contact->save()) {
                    throw new ModelValidationException($contact);
                }
            }
        }

        $contragent = $account->contragent;
        $contragent->comment = json_encode($data);
        $contragent->inn = $data['inn'];
        $contragent->legal_type = ClientContragent::PERSON_TYPE;
        $contragent->name = $data['name'];

        if (!$contragent->save()) {
            throw new ModelValidationException($contragent);
        }

        $person = $contragent->person;

        $fio = explode(' ', $data['name']);

        if ($fio) {
            $person->middle_name = '';

            $count = 0;
            while (count($fio)) {
                $value = array_shift($fio);
                switch ($count) {
                    case 0:
                        $person->last_name = $value;
                        break;

                    case 1:
                        $person->first_name = $value;
                        break;

                    default:
                        $person->middle_name .= ($person->middle_name ? ' ' : '') . $value;
                }
                $count++;
            }
        }

        $person->birthday = \DateTimeImmutable::createFromFormat('d.m.Y', $data['birthDate'])->format(DateTimeZoneHelper::DATE_FORMAT);
        $person->birthplace = $data['birthPlace'];

        $person->registration_address = $data['address']['addressStr'];

        $idt = $data['identity'];
        $person->passport_serial = $idt['series'];
        $person->passport_number = $idt['number'];
        $person->passport_date_issued = \DateTimeImmutable::createFromFormat('d.m.Y', $idt['issueDate'])->format(DateTimeZoneHelper::DATE_FORMAT);
        $person->passport_issued = $idt['issuedBy'] . ' Код подразделения: ' . $idt['issueId'];

        if (!$person->save()) {
            throw new ModelValidationException($person);
        }

        return ['is_saved' => true];
    }

    /**
     * @SWG\Post(tags={"Работа с клиентами"}, path="/internal/client/get-client-contract-info/", summary="Получение данных о договорах", operationId="Получение данных о договорах",
     *   @SWG\Parameter(name = "contract_id", type = "integer", description = "ID договора (если несколько: то массив ID, или значение через ',')", in = "query", required = false, default = ""),
     *   @SWG\Parameter(name = "account_id", type = "integer", description = "ID ЛС (если несколько: то массив ID, или значение через ',')", in = "query", required = false, default = ""),
     *
     *   @SWG\Response(response = 200, description = "Информация об УЛС по контрактам",
     *     @SWG\Schema(type = "array")
     *   ),
     *   @SWG\Response(response = "default", description = "Ошибки",
     *     @SWG\Schema(ref = "#/definitions/error_result")
     *   )
     * )
     *
     * @param string $contract_id
     * @return array
     */
    public function actionGetClientContractInfo($contract_id = null, $account_id = null)
    {
        if (!is_array($contract_id)) {
            $contract_id = explode(',', $contract_id);
        }
        $contract_id = array_filter(array_map('trim', $contract_id));

        if (!is_array($account_id)) {
            $account_id = explode(',', $account_id);
        }
        $account_id = array_filter(array_map('trim', $account_id));

        if (!$contract_id && !$account_id) {
            return [];
        }

        if ($account_id) {
            $contract_id = array_unique(
                array_merge(
                    $contract_id,
                    ClientAccount::find()
                        ->select('contract_id')
                        ->distinct()
                        ->where(['id' => $account_id])
                        ->column()
                )
            );
        }

        $contracts = ClientContract::find()
            ->select(['id', 'business_process_status_id'])
            ->where(['id' => $contract_id])
            ->indexBy('id')
            ->asArray()
            ->all();

        $accounts = ClientAccount::find()
            ->select([
                'id',
                'is_blocked',
                'is_bill_pay_overdue',
                'is_postpaid',
                'show_in_lk',
                'is_active',
                'price_level',
                'credit',
                'account_version',
                'contract_id'
            ])
            ->where(['contract_id' => $contract_id])
            ->asArray()
            ->all();

        $info = [];
        foreach ($accounts as $account) {
            $info[$account['contract_id']][] = ClientSuper::dao()->getAccountInfo($account, $contracts[$account['contract_id']], false);
        }

        $data = [];
        foreach ($info as $contractId => $accounts) {
            $data[$contractId] = [
                'contract_id' => $contractId,
                'accounts' => $accounts
            ];
        }
        unset($info);

        return $data;
    }

    /**
     * @SWG\Get(tags={"Dictionaries"}, path="/internal/client/get-entry-point-list/", summary="Получения списка Точек Входа", operationId="EntryPointList",
     *   @SWG\Parameter(name = "code", type = "string", description = "код Точки Входа", in = "query", required = false, default = ""),
     *   @SWG\Parameter(name = "country_code", type = "string", description = "Страна точки входа", in = "query", required = false, default = "643"),
     *   @SWG\Parameter(name = "is_default", type = "string", description = "Точка Входа по-умолчанию", in = "query", required = false, default = ""),
     *
     *   @SWG\Response(response = 200, description = "Информация о Точке Входа",
     *     @SWG\Schema(type = "array")
     *   ),
     *   @SWG\Response(response = "default", description = "Ошибки",
     *     @SWG\Schema(ref = "#/definitions/error_result")
     *   )
     * )
     *
     * @param string $contract_id
     * @return array
     */
    public function actionGetEntryPointList($code = null, $country_code = null, $is_default = null)
    {
        $listQuery = EntryPoint::find();

        $listQuery->andFilterWhere(['code' => $code]);
        $listQuery->andFilterWhere(['country_id' => $country_code]);
        $listQuery->andFilterWhere(['is_default' => $is_default]);

        $list = $listQuery->all();

        $orgList = Organization::dao()->getList();

        return array_map(
            function (EntryPoint $ep) use ($orgList) {
                $row = $ep->getAttributes(null, ['id', 'country_id', 'site_id', 'client_contract_business_id', 'client_contract_business_process_id', 'client_contract_business_process_status_id', 'organization_id']);
                $row['business'] = $ep->business->getAttributes(['id', 'name']);
                $row['business_process'] = $ep->businessProcess->getAttributes(['id', 'name']);
                $row['business_process_status'] = $ep->businessProcessStatus->getAttributes(['id', 'name']);
                $org = $orgList[$ep->organization_id];
                $row['organization'] = ['id' => $org->organization_id, 'name' => $org->name->value];
                $row['country'] = $ep->country->getAttributes(['code', 'name']);
                $row['site'] = $ep->site;
                $row['legal_type'] = $ep->legal_type;
                return $row;
            },
            $list
        );
    }


    /**
     * @SWG\Post(tags={"Dictionaries"}, path="/internal/client/organisations/", summary="List of organizations", operationId="ListOfOrganizations",
     *   @SWG\Response(response = 200, description = "Organizations",
     *     @SWG\Schema(type = "array")
     *   ),
     *   @SWG\Response(response = "default", description = "Error",
     *     @SWG\Schema(ref = "#/definitions/error_result")
     *   )
     * )
     *
     * @param string $contract_id
     * @return array
     */
    public function actionOrganisations()
    {
        return array_map(function ($a) {
            $row = ['name' => (string)$a->name] + $a->getAttributes([
                    "organization_id",
                    "actual_from",
                    "actual_to",
                    "country_id",
                    "lang_code",
                    "is_simple_tax_system",
                    "vat_rate",
                    "tax_registration_id",
                    "tax_registration_reason",
                    "contact_phone",
                    "contact_fax",
                    "contact_email",
                    "contact_site"
                ]);

            if ($row['actual_to'] == UsageInterface::MAX_POSSIBLE_DATE) {
                $row['actual_to'] = null;
            }
            $settlementAccount = $a->getSettlementAccount(
                $a->country_id == Country::RUSSIA
                    ? OrganizationSettlementAccount::SETTLEMENT_ACCOUNT_TYPE_RUSSIA
                    : OrganizationSettlementAccount::SETTLEMENT_ACCOUNT_TYPE_SWIFT
            );

            $row['bank'] = $settlementAccount->getAttributes(null, ['organization_record_id', 'settlement_account_type_id']);
            $row['bank']['bank_account'] = $settlementAccount->getProperty(
                $a->country_id == Country::RUSSIA
                    ? 'bank_account_RUB'
                    : ($a->country_id == Country::HUNGARY
                    ? 'bank_account_HUF'
                    : 'bank_account_EUR')
            )->value;

            return $row;
        },
            Organization::dao()->getList()
        );
    }

    /**
     * @SWG\Get(tags={"Работа с клиентами"}, path="/internal/client/find-contragent-by-number-for-bdpn", summary="Получение данных по контрагенту по номер для БДПН", operationId="Получение данных по контрагенту по номер для БДПН",
     *   @SWG\Parameter(name="number", type="integer", description="номер в e164", in="query", default="79311110000"),
     *   @SWG\Response(response="default", description="Ошибки", @SWG\Schema(ref="#/definitions/error_result"))
     * )
     */
    public function actionFindContragentByNumberForBdpn($number)
    {
        $origNumber = $number;
        $number = '79311110000';
        /** @var AccountTariff $accountTariff */
        $accountTariff = AccountTariff::find()->where(['voip_number' => $number])->orderBy(['id' => SORT_DESC])->one();
        if (!$accountTariff) {
            return ['is_found' => false];
        }

        $contragent = $accountTariff->clientAccount->contragent;

        if ($contragent->legal_type == ClientContragent::PERSON_TYPE) {
            $person = $contragent->person;
            return [
                'is_found' => true,
                'data' => [
                    'Type' => 'Person',
                    'FN' => $person->first_name,
                    'LN' => $person->last_name,
                    'PN' => $person->middle_name,
                    'Docs' => [
                        [
                            'Type' => 'CPassport',
                            'IDNAME' => 'Паспорт гражданина Российской Федерации',
                            'IDDS' => $person->passport_serial,
                            'IDDN' => $person->passport_number,
                        ]
                    ],
                    "Numbers" => [
                        substr((string)$origNumber, 1)
                    ]
                ]
            ];
        }
    }
}
