<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Connection;

/**
 * Копирование данных из stage MySQL в dev MySQL
 */
class CopyDataController extends Controller
{
    /**
     * Таблицы справочников
     */
    const DICTIONARY_TABLES = [
        'organization',
        'organization_i18n',
        'organization_settlement_account',
        'organization_settlement_account_properties',
        'person',
        'person_i18n',
        'important_events_names',
        'important_events_groups',
        'important_events_sources',
        'country',
        'city',
        'regions',
        'city_billing_methods',
        'invoice_settings',
        'entry_point',
        'public_site',
        'tags',
        'client_contract_business_process_status',
        'params',
        'clients_price_level',
        'voip_source',
    ];

    /**
     * Копирование данных ЛС
     *
     * @param int $clientId ID лицевого счета
     * @return int
     */
    public function actionClient($clientId)
    {
        $clientId = (int)$clientId;

        $stage = $this->_getStageDb();
        if (!$stage) {
            return ExitCode::CONFIG;
        }
        /** @var Connection $dev */
        $dev = Yii::$app->db;

        // 1. Читаем clients из stage
        $client = $stage->createCommand("SELECT * FROM clients WHERE id = :id", [':id' => $clientId])->queryOne();
        if (!$client) {
            $this->stderr("clients id=$clientId не найден в stage\n");
            return ExitCode::DATAERR;
        }

        $superId = $client['super_id'];
        $contractId = $client['contract_id'];

        // 2. Читаем структуру клиента из stage
        $data = [];

        $data['client_super'] = $stage->createCommand(
            "SELECT * FROM client_super WHERE id = :id", [':id' => $superId]
        )->queryAll();

        $data['client_contragent'] = $stage->createCommand(
            "SELECT * FROM client_contragent WHERE super_id = :id", [':id' => $superId]
        )->queryAll();

        $contragentIds = array_column($data['client_contragent'], 'id');

        $data['client_contract'] = $stage->createCommand(
            "SELECT * FROM client_contract WHERE super_id = :id", [':id' => $superId]
        )->queryAll();

        $contractIds = array_column($data['client_contract'], 'id');

        if ($contragentIds) {
            $data['client_contragent_person'] = $stage->createCommand(
                "SELECT * FROM client_contragent_person WHERE contragent_id IN (" . implode(',', $contragentIds) . ")"
            )->queryAll();

            $data['client_contragent_import_lk_status'] = $stage->createCommand(
                "SELECT * FROM client_contragent_import_lk_status WHERE contragent_id IN (" . implode(',', $contragentIds) . ")"
            )->queryAll();
        } else {
            $data['client_contragent_person'] = [];
            $data['client_contragent_import_lk_status'] = [];
        }

        if ($contractIds) {
            $data['client_contact_personal'] = $stage->createCommand(
                "SELECT * FROM client_contact_personal WHERE contract_id IN (" . implode(',', $contractIds) . ")"
            )->queryAll();

            $data['client_contract_comment'] = $stage->createCommand(
                "SELECT * FROM client_contract_comment WHERE contract_id IN (" . implode(',', $contractIds) . ")"
            )->queryAll();

            $data['client_contract_reward'] = $stage->createCommand(
                "SELECT * FROM client_contract_reward WHERE contract_id IN (" . implode(',', $contractIds) . ")"
            )->queryAll();

            $data['client_contract_additional_agreement'] = $stage->createCommand(
                "SELECT * FROM client_contract_additional_agreement WHERE contract_id IN (" . implode(',', $contractIds) . ")"
            )->queryAll();
        } else {
            $data['client_contact_personal'] = [];
            $data['client_contract_comment'] = [];
            $data['client_contract_reward'] = [];
            $data['client_contract_additional_agreement'] = [];
        }

        $data['clients'] = [$client];

        $data['client_contacts'] = $stage->createCommand(
            "SELECT * FROM client_contacts WHERE client_id = :id", [':id' => $clientId]
        )->queryAll();

        $data['client_inn'] = $stage->createCommand(
            "SELECT * FROM client_inn WHERE client_id = :id", [':id' => $clientId]
        )->queryAll();

        $data['client_pay_acc'] = $stage->createCommand(
            "SELECT * FROM client_pay_acc WHERE client_id = :id", [':id' => $clientId]
        )->queryAll();

        $data['client_counters'] = $stage->createCommand(
            "SELECT * FROM client_counters WHERE client_id = :id", [':id' => $clientId]
        )->queryAll();

        $data['client_flag'] = $stage->createCommand(
            "SELECT * FROM client_flag WHERE account_id = :id", [':id' => $clientId]
        )->queryAll();

        $data['client_subaccount'] = $stage->createCommand(
            "SELECT * FROM client_subaccount WHERE account_id = :id", [':id' => $clientId]
        )->queryAll();

        $data['client_comment'] = $stage->createCommand(
            "SELECT * FROM client_comment WHERE account_id = :id", [':id' => $clientId]
        )->queryAll();

        $data['client_blocked_comment'] = $stage->createCommand(
            "SELECT * FROM client_blocked_comment WHERE account_id = :id", [':id' => $clientId]
        )->queryAll();

        $data['client_document'] = $stage->createCommand(
            "SELECT * FROM client_document WHERE account_id = :id", [':id' => $clientId]
        )->queryAll();

        $data['client_account_options'] = $stage->createCommand(
            "SELECT * FROM client_account_options WHERE client_account_id = :id", [':id' => $clientId]
        )->queryAll();

        // 3. Читаем финансовые данные
        $data['newsaldo'] = $stage->createCommand(
            "SELECT * FROM newsaldo WHERE client_id = :id", [':id' => $clientId]
        )->queryAll();

        $data['newbills'] = $stage->createCommand(
            "SELECT * FROM newbills WHERE client_id = :id", [':id' => $clientId]
        )->queryAll();

        $billNos = array_column($data['newbills'], 'bill_no');

        $data['newpayments'] = $stage->createCommand(
            "SELECT * FROM newpayments WHERE client_id = :id", [':id' => $clientId]
        )->queryAll();

        if ($billNos) {
            $billNoParams = [];
            foreach ($billNos as $i => $bn) {
                $billNoParams[":bn$i"] = $bn;
            }
            $billNoIn = implode(',', array_keys($billNoParams));

            $data['invoice'] = $stage->createCommand(
                "SELECT * FROM invoice WHERE bill_no IN ($billNoIn)", $billNoParams
            )->queryAll();

            $data['newbill_lines'] = $stage->createCommand(
                "SELECT * FROM newbill_lines WHERE bill_no IN ($billNoIn)", $billNoParams
            )->queryAll();
        } else {
            $data['invoice'] = [];
            $data['newbill_lines'] = [];
        }

        $invoiceIds = array_column($data['invoice'], 'id');
        if ($invoiceIds) {
            $data['invoice_lines'] = $stage->createCommand(
                "SELECT * FROM invoice_lines WHERE invoice_id IN (" . implode(',', $invoiceIds) . ")"
            )->queryAll();
        } else {
            $data['invoice_lines'] = [];
        }

        $paymentIds = array_column($data['newpayments'], 'id');
        if ($paymentIds) {
            $paymentIdIn = implode(',', $paymentIds);
            $data['payment_atol'] = $stage->createCommand(
                "SELECT * FROM payment_atol WHERE id IN ($paymentIdIn)"
            )->queryAll();
            $data['payment_sber_online'] = $stage->createCommand(
                "SELECT * FROM payment_sber_online WHERE id IN ($paymentIdIn)"
            )->queryAll();
            $data['payment_stripe'] = $stage->createCommand(
                "SELECT * FROM payment_stripe WHERE payment_id IN ($paymentIdIn)"
            )->queryAll();
            $data['newpayment_info'] = $stage->createCommand(
                "SELECT * FROM newpayment_info WHERE payment_id IN ($paymentIdIn)"
            )->queryAll();
            $data['newpayment_info_short'] = $stage->createCommand(
                "SELECT * FROM newpayment_info_short WHERE payment_id IN ($paymentIdIn)"
            )->queryAll();
            $data['newpayment_api_info'] = $stage->createCommand(
                "SELECT * FROM newpayment_api_info WHERE payment_id IN ($paymentIdIn)"
            )->queryAll();
        } else {
            $data['payment_atol'] = [];
            $data['payment_sber_online'] = [];
            $data['payment_stripe'] = [];
            $data['newpayment_info'] = [];
            $data['newpayment_info_short'] = [];
            $data['newpayment_api_info'] = [];
        }

        $data['newpayments_orders'] = $stage->createCommand(
            "SELECT * FROM newpayments_orders WHERE client_id = :id", [':id' => $clientId]
        )->queryAll();

        $data['invoice_payment_link'] = $stage->createCommand(
            "SELECT * FROM invoice_payment_link WHERE client_account_id = :id", [':id' => $clientId]
        )->queryAll();

        // 3b. history_version по всем версионируемым объектам
        $historyConditions = [];
        $historyConditions[] = "(model = 'app\\\\models\\\\ClientAccount' AND model_id = $clientId)";
        if ($contragentIds) {
            $historyConditions[] = "(model = 'app\\\\models\\\\ClientContragent' AND model_id IN (" . implode(',', $contragentIds) . "))";
        }
        if ($contractIds) {
            $historyConditions[] = "(model = 'app\\\\models\\\\ClientContract' AND model_id IN (" . implode(',', $contractIds) . "))";
        }
        $contragentPersonIds = array_column($data['client_contragent_person'], 'id');
        if ($contragentPersonIds) {
            $historyConditions[] = "(model = 'app\\\\models\\\\ClientContragentPerson' AND model_id IN (" . implode(',', $contragentPersonIds) . "))";
        }
        $contactIds = array_column($data['client_contacts'], 'id');
        if ($contactIds) {
            $historyConditions[] = "(model = 'app\\\\models\\\\ClientContact' AND model_id IN (" . implode(',', $contactIds) . "))";
        }
        $subaccountIds = array_column($data['client_subaccount'], 'id');
        if ($subaccountIds) {
            $historyConditions[] = "(model = 'app\\\\models\\\\ClientSubAccount' AND model_id IN (" . implode(',', $subaccountIds) . "))";
        }
        $data['history_version'] = $stage->createCommand(
            "SELECT * FROM history_version WHERE " . implode(' OR ', $historyConditions)
        )->queryAll();

        // 4. DELETE в dev в обратном порядке зависимостей
        $deleteOrder = [
            'history_version' => implode(' OR ', $historyConditions),
            'invoice_payment_link' => "client_account_id = :id",
            'newpayments_orders' => "client_id = :id",
            'payment_atol' => "id IN (SELECT id FROM newpayments WHERE client_id = :id)",
            'payment_sber_online' => "id IN (SELECT id FROM newpayments WHERE client_id = :id)",
            'payment_stripe' => "payment_id IN (SELECT id FROM newpayments WHERE client_id = :id)",
            'newpayment_info' => "payment_id IN (SELECT id FROM newpayments WHERE client_id = :id)",
            'newpayment_info_short' => "payment_id IN (SELECT id FROM newpayments WHERE client_id = :id)",
            'newpayment_api_info' => "payment_id IN (SELECT id FROM newpayments WHERE client_id = :id)",
            'invoice_lines' => "invoice_id IN (SELECT id FROM invoice WHERE bill_no IN (SELECT bill_no FROM newbills WHERE client_id = :id))",
            'invoice' => "bill_no IN (SELECT bill_no FROM newbills WHERE client_id = :id)",
            'newpayments' => "client_id = :id",
            'newbill_lines' => "bill_no IN (SELECT bill_no FROM newbills WHERE client_id = :id)",
            'newbills' => "client_id = :id",
            'newsaldo' => "client_id = :id",
            'client_account_options' => "client_account_id = :id",
            'client_document' => "account_id = :id",
            'client_blocked_comment' => "account_id = :id",
            'client_comment' => "account_id = :id",
            'client_subaccount' => "account_id = :id",
            'client_flag' => "account_id = :id",
            'client_counters' => "client_id = :id",
            'client_pay_acc' => "client_id = :id",
            'client_inn' => "client_id = :id",
            'client_contacts' => "client_id = :id",
            'clients' => "id = :id",
            'client_contract_additional_agreement' => "contract_id IN (SELECT id FROM client_contract WHERE super_id = $superId)",
            'client_contract_reward' => "contract_id IN (SELECT id FROM client_contract WHERE super_id = $superId)",
            'client_contract_comment' => "contract_id IN (SELECT id FROM client_contract WHERE super_id = $superId)",
            'client_contact_personal' => "contract_id IN (SELECT id FROM client_contract WHERE super_id = $superId)",
            'client_contragent_import_lk_status' => "contragent_id IN (SELECT id FROM client_contragent WHERE super_id = $superId)",
            'client_contragent_person' => "contragent_id IN (SELECT id FROM client_contragent WHERE super_id = $superId)",
            'client_contract' => "super_id = $superId",
            'client_contragent' => "super_id = $superId",
            'client_super' => "id = $superId",
        ];

        $insertOrder = [
            'client_super',
            'client_contragent',
            'client_contract',
            'client_contragent_person',
            'client_contragent_import_lk_status',
            'client_contact_personal',
            'client_contract_comment',
            'client_contract_reward',
            'client_contract_additional_agreement',
            'clients',
            'client_contacts',
            'client_inn',
            'client_pay_acc',
            'client_counters',
            'client_flag',
            'client_subaccount',
            'client_comment',
            'client_blocked_comment',
            'client_document',
            'client_account_options',
            'newsaldo',
            'newbills',
            'newbill_lines',
            'newpayments',
            'payment_atol',
            'payment_sber_online',
            'payment_stripe',
            'newpayment_info',
            'newpayment_info_short',
            'newpayment_api_info',
            'invoice',
            'invoice_lines',
            'newpayments_orders',
            'invoice_payment_link',
            'history_version',
        ];

        return $this->_copyData($dev, $data, $deleteOrder, $insertOrder, $clientId);
    }

    /**
     * Копирование справочников
     *
     * @return int
     */
    public function actionDictionary()
    {
        $stage = $this->_getStageDb();
        if (!$stage) {
            return ExitCode::CONFIG;
        }
        /** @var Connection $dev */
        $dev = Yii::$app->db;

        $data = [];
        foreach (self::DICTIONARY_TABLES as $table) {
            $data[$table] = $stage->createCommand("SELECT * FROM $table")->queryAll();
        }

        $deleteOrder = [];
        foreach (array_reverse(self::DICTIONARY_TABLES) as $table) {
            $deleteOrder[$table] = '1=1';
        }

        return $this->_copyData($dev, $data, $deleteOrder, self::DICTIONARY_TABLES);
    }

    /**
     * @return Connection|null
     */
    private function _getStageDb()
    {
        if (strpos(gethostname(), '-dev-') === false) {
            $this->stderr("Запуск разрешен только на dev-окружении\n");
            return null;
        }

        $configFile = Yii::getAlias('@app/config/db_stage.php');
        if (!file_exists($configFile)) {
            $this->stderr("config/db_stage.php не найден\n");
            return null;
        }

        $config = require $configFile;
        return Yii::createObject($config);
    }

    /**
     * @param Connection $dev
     * @param array $data
     * @param array $deleteOrder
     * @param array $insertOrder
     * @param int|null $clientId
     * @return int
     */
    private function _copyData(Connection $dev, array $data, array $deleteOrder, array $insertOrder, $clientId = null)
    {
        $params = $clientId !== null ? [':id' => $clientId] : [];

        $transaction = $dev->beginTransaction();
        try {
            $dev->createCommand("SET FOREIGN_KEY_CHECKS = 0")->execute();

            foreach ($deleteOrder as $table => $condition) {
                $dev->createCommand("DELETE FROM $table WHERE $condition", $params)->execute();
            }

            foreach ($insertOrder as $table) {
                $rows = $data[$table];
                if (empty($rows)) {
                    echo "$table: 0\n";
                    continue;
                }

                $devColumns = $dev->getTableSchema($table)->getColumnNames();
                $stageColumns = array_keys($rows[0]);
                $extraColumns = array_diff($stageColumns, $devColumns);
                if ($extraColumns) {
                    foreach ($rows as &$row) {
                        foreach ($extraColumns as $col) {
                            unset($row[$col]);
                        }
                    }
                    unset($row);
                }

                $columns = array_keys($rows[0]);
                $dev->createCommand()->batchInsert($table, $columns, $rows)->execute();
                echo "$table: " . count($rows) . "\n";
            }

            $dev->createCommand("SET FOREIGN_KEY_CHECKS = 1")->execute();

            $transaction->commit();
            echo "OK\n";
        } catch (\Exception $e) {
            $dev->createCommand("SET FOREIGN_KEY_CHECKS = 1")->execute();
            $transaction->rollBack();
            $this->stderr("Ошибка: " . $e->getMessage() . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }
}
