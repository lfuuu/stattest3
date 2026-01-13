<?php

use ActiveRecord\ModelException;
use app\classes\bill\ClientAccountBiller;
use app\classes\BillContract;
use app\classes\BillQRCode;
use app\classes\documents\DocumentReportFactory;
use app\classes\Encrypt;
use app\classes\helpers\DependecyHelper;
use app\classes\rewards\CalculateReward;
use app\classes\StatModule;
use app\classes\payments\PaymentParser;
use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\Bill;
use app\models\BillOutcomeCorrection;
use app\models\BillCorrection;
use app\models\BillDocument;
use app\models\BillExternal;
use app\models\Business;
use app\models\ClientAccount;
use app\models\ClientAccountOptions;
use app\models\ClientContract;
use app\models\ClientContragent;
use app\models\Courier;
use app\models\Currency;
use app\models\CurrencyRate;
use app\models\filter\PartnerRewardsFilter;
use app\models\GoodPriceType;
use app\models\Invoice;
use app\models\Language;
use app\models\OperationType;
use app\models\Organization;
use app\models\Param;
use app\models\Payment;
use app\models\PaymentSberOnline;
use app\models\Store;
use app\models\Transaction;
use app\models\User;
use app\models\ClientContarct;
use app\models\filter\PartnerRewardsNewFilter;
use app\models\rewards\RewardBill;
use app\modules\uu\models\Bill as uuBill;
use yii\db\Expression;
use yii\db\Query;
use app\models\LogBill;
use app\classes\validators\FormFieldValidator;

class m_newaccounts extends IModule
{
    private static $object;
    private static $bb_c = [];
    const SLEEPING_TIME = 3;
    const PI_IMPORT_RECPERPAGE = 50;

    function do_include()
    {
        static $inc = false;
        if ($inc) {
            return;
        }
        $inc = true;
        include_once INCLUDE_PATH . 'bill.php';
//        include_once INCLUDE_PATH . 'payments.php';
    }

    function GetMain($action, $fixclient)
    {
        $this->do_include();
        if (!$action || $action == 'default') {
            $action = 'bill_list';
        }
        if (!isset($this->actions[$action])) {
            return;
        }
        $act = $this->actions[$action];
        if ($act !== '' && !access($act[0], $act[1])) {
            return;
        }
        call_user_func([$this, 'newaccounts_' . $action], $fixclient);
    }

    function newaccounts_default($fixclient)
    {
    }

    function newaccounts_saldo($fixclient)
    {
        global $design, $db, $user, $fixclient_data;
        $saldo = get_param_protected('saldo');
        $date = get_param_protected('date');
        $db->Query('UPDATE newsaldo SET is_history=1 WHERE client_id=' . $fixclient_data['id']);
        $db->Query('INSERT INTO newsaldo (client_id,saldo,currency,ts,is_history,edit_user,edit_time) VALUES (' . $fixclient_data['id'] . ',"' . $saldo . '","' . $fixclient_data['currency'] . '","' . $date . '",0,"' . $user->Get('id') . '",NOW())');
        ClientAccount::dao()->updateBalance($fixclient_data['id']);
        if ($design->ProcessEx('errors.tpl')) {
            header("Location: " . $design->LINK_START . "module=newaccounts&action=bill_list");
            exit();
        }
    }

    function newaccounts_bill_balance($fixclient)
    {
        global $design, $db, $user, $fixclient_data;
        $client_id = $fixclient_data['id'];
        ClientAccount::dao()->updateBalance($client_id);
        if ($design->ProcessEx('errors.tpl')) {
            if ($returning = ($_GET['returning'] ?? false)) {
                header("Location: /" . $returning);
            } else {
                header("Location: " . $design->LINK_START . "module=newaccounts&action=bill_list");
            }
            exit();
        }
    }

    /**
     * @throws \yii\db\Exception
     */
    function newaccounts_bill_balance2($fixclient)
    {
        global $design, $db, $user, $fixclient_data;
        $client_id = $fixclient_data['id'];
//        ClientAccount::dao()->updateBalanceNew($client_id);
        (new \app\modules\uu\tarificator\RealtimeBalanceTarificatorWithSaldo())->tarificate($client_id);
        if ($design->ProcessEx('errors.tpl')) {
            if ($returning = ($_GET['returning'] ?? false)) {
                header("Location: /" . $returning);
            } else {
                header("Location: " . $design->LINK_START . "module=newaccounts&action=bill_list");
            }
            exit();
        }
    }

    function newaccounts_bill_balance_mass($fixclient)
    {
        global $design;
        $now = (new \DateTimeImmutable('now', new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_DEFAULT)));

        Param::setParam(
            Param::NOTIFICATIONS_SWITCH_OFF_DATE,
            $now->format(DateTimeZoneHelper::DATETIME_FORMAT),
            $isRawValue = true
        );

        Param::setParam(
            Param::NOTIFICATIONS_SWITCH_ON_DATE,
            $now->modify("+1 hour")
                ->format(DateTimeZoneHelper::DATETIME_FORMAT),
            $isRawValue = true
        );

        $count = 0;
        while (Param::getParam(Param::NOTIFICATIONS_SCRIPT_ON)) {
            flush();
            if (++$count > 60) {
                throw new RuntimeException('Невозможно обновить счета, обратитесь к разработчику');
            }
            echo '. ';
            sleep(self::SLEEPING_TIME);
        }

        $design->ProcessEx('errors.tpl');

        $event = \app\models\EventQueue::go(\app\models\EventQueue::UPDATE_BALANCE_MASS, ['notified_user_id' => \Yii::$app->user->id]);

        header("Location: /monitoring/event-queue?" . http_build_query(['EventQueueFilter' => ['id' => $event->id]]));
        exit();
    }

    function newaccounts_bill_list($fixclient, $get_sum = false)
    {
        global $design, $db, $pg_db, $user, $fixclient_data;
        if (!$fixclient) {
            return;
        }

        set_time_limit(600);

        $_SESSION['clients_client'] = $fixclient;

        $clientType = $fixclient_data->contract->financial_type;
        $design->assign('fin_type', $clientType);

        $design->assign('is_partner', $fixclient_data->contract->business_id == Business::PARTNER);

        $t = get_param_raw('simple', null);
        if ($t !== null) {
            $user->SetFlag('balance_simple', $t);
        }

        $sum_l = [
            "service" => ["USD" => 0, "RUB" => 0],
            "zalog" => ["USD" => 0, "RUB" => 0],
            "zadatok" => ["USD" => 0, "RUB" => 0],
            "good" => ["USD" => 0, "RUB" => 0],
        ];

        $saldo = \app\models\Saldo::getLastSaldo($fixclient_data->id);

        foreach ($db->AllRecords(
            "SELECT sum(l.sum) AS sum, l.type, b.currency
            FROM newbills b, newbill_lines l
            WHERE
                    client_id = '" . $fixclient_data["id"] . "'
                AND b.bill_no = l.bill_no
                AND state_1c != 'Отказ'
                " . ($saldo ? "AND l.date_from >= '" . $saldo->ts . "'" : '') . "
            GROUP BY l.type, b.currency") as $s) {
            $sum_l[$s["type"]][$s["currency"]] = $s["sum"];
        }

        $queryPayment = Payment::find()->where(['client_id' => $fixclient_data["id"]]);
        if ($saldo) {
            $queryPayment->andWhere(['>=', 'payment_date', $saldo->ts]);
        }
        $sum_l["payments"] = $queryPayment->sum('sum');

        $design->assign("sum_l", $sum_l);

        $clientAccount = ClientAccount::findOne($fixclient_data['id']);

        $design->assign("counters", $clientAccount->billingCounters);
        $design->assign("subscr_counter", $clientAccount->billingCounters);

        $design->assign(
            'notLinkedtransactions',
            Transaction::find()
                ->andWhere(['client_account_id' => $fixclient_data["id"], 'source' => 'stat', 'deleted' => 0])
                ->andWhere('bill_id is null')
                ->all()
        );

        $currentStatement = [];
        if ($fixclient_data->account_version == ClientAccount::VERSION_BILLER_UNIVERSAL) {
            $statementSum = number_format(\app\modules\uu\models\Bill::getUnconvertedAccountEntries($fixclient_data->id)->sum('price_with_vat'), 2, '.', '');

            $currentStatement = [
                'sum' => $statementSum,
                'bill_date' => date('Y-m-d'),
                'bill_no' => 'current_statement',
            ];
        }
        $design->assign('currentStatement', $currentStatement);


        if ($user->Flag('balance_simple')) {
            return $this->newaccounts_bill_list_simple($get_sum, $clientType);
        } else {
            return $this->newaccounts_bill_list_full($get_sum, $clientType);
        }
    }

    function newaccounts_bill_list_simple($get_sum = false, $clientType = null)
    {
        global $design, $db, $user, $fixclient, $fixclient_data;

        $clientAccount = ClientAccount::findOne($fixclient);
        $isMulty = $clientAccount->isMulty();
        $isViewCanceled = get_param_raw("view_canceled", null);

        if ($isViewCanceled === null) {
            if (isset($_SESSION["view_canceled"])) {
                $isViewCanceled = $_SESSION["view_canceled"];
            } else {
                $isViewCanceled = 0;
                $_SESSION["view_canceled"] = $isViewCanceled;
            }
        } else {
            $_SESSION["view_canceled"] = $isViewCanceled;
        }

        $design->assign("view_canceled", $isViewCanceled);

        $params = [
            "client_id" => $fixclient_data["id"],
            "client_currency" => $fixclient_data["currency"],
            "is_multy" => $isMulty,
            "is_view_canceled" => $isViewCanceled,
            "get_sum" => $get_sum,
            'is_with_file_name' => true,
        ];

        $R = BalanceSimple::get($params);

        if ($get_sum) {
            return $R;
        }

        [$R, $sum, $sw] = $R;

        ksort($sw);

        $stDates = Organization::dao()->getWhenOrganizationSwitched($clientAccount->contract_id);

        if ($stDates) {
            foreach ($stDates as $date => $organizationId) {
                $ks = false;
                foreach ($sw as $bDate => $billNo) {
                    if ($bDate >= $date) {
                        $ks = $billNo;
                        break;
                    }
                }

                if ($ks && isset($R[$ks])) {
                    $organization = Organization::find()->byId($organizationId)->actual($date)->one();
                    $R[$ks]['organization_switched'] = $organization;
                }
            }
        }

        $billNos =
            array_map(
                fn($bill) => $bill['bill']['bill_no'],
                array_filter($R, fn($bill) => $bill['bill']['operation_type_id'] != OperationType::ID_COST)
        );

        $extBills = BillExternal::find()->where(['bill_no' => $billNos])->indexBy('bill_no')->select(['bill_no', 'ext_vat', 'ext_sum_without_vat'])->asArray()->all();

        foreach ($R as $bill) {
            if ($bill->operation_type_id != OperationType::ID_COST) {
//                $b = BillExternal::find()->where(['bill_no' => $bill['bill']['bill_no']])->one();
                $billNo = $bill['bill']['bill_no'];
                $b = $extBills[$billNo] ?? null;
                if ($b) {
                    $R[$billNo]['ext_sum'] = $b['ext_vat'] + $b['ext_sum_without_vat'];
                }
            }
        }

        #krsort($R);
        $design->assign('client_type', $clientType);
        $design->assign('billops', $R);
        $design->assign('sum', $sum);
        $design->assign('sum_cur', $sum[$fixclient_data['currency']]);
        $design->assign('currency', $clientAccount->currency);
        $design->assign('sum_current_statement', uuBill::getUnconvertedAccountEntries($clientAccount->id)->sum('price_with_vat') ?: 0);
        $design->assign('realtime_balance', $clientAccount->billingCounters->getRealtimeBalance());
        $design->assign(
            'saldo_history',
            $db->AllRecords('
                SELECT
                    newsaldo.*,
                    user_users.name AS user_name
                FROM
                    newsaldo
                LEFT JOIN
                    user_users
                ON
                    user_users.id = newsaldo.edit_user
                WHERE
                    client_id=' . $fixclient_data['id'] . '
                ORDER BY
                    id DESC
            ')
        );

        $design->AddMain('newaccounts/bill_list_simple.tpl');
    }

    function newaccounts_show_income_goods()
    {
        global $design;

        $_SESSION['get_income_goods_on_bill_list'] = get_param_raw('show', 'N') == "Y";

        header("Location: " . $design->LINK_START . "module=newaccounts&action=bill_list");
        exit();
    }

    function newaccounts_bill_list_filter()
    {
        $value = get_param_raw('value', 'full');

        if (!in_array($value, ['full','income', 'expense'])) {
            $value = 'full';
        }

        $_SESSION['bill_list_filter_income'] = $value;

        header("Location: ./?module=newaccounts&action=bill_list");
        exit();
    }

    function newaccounts_bill_list_full($get_sum = false, $clientType = null)
    {
        global $design, $db, $user, $fixclient, $fixclient_data;

        $clientAccount = ClientAccount::findOne($fixclient);
        $isMulty = $clientAccount->isMulty();
        $isViewCanceled = get_param_raw("view_canceled", null);

        if ($isViewCanceled === null) {
            if (isset($_SESSION["view_canceled"])) {
                $isViewCanceled = $_SESSION["view_canceled"];
            } else {
                $isViewCanceled = 0;
                $_SESSION["view_canceled"] = $isViewCanceled;
            }
        } else {
            $_SESSION["view_canceled"] = $isViewCanceled;
        }

        $design->assign("view_canceled", $isViewCanceled);

        $sum = [
            'USD' => [
                'delta' => 0,
                'bill' => 0,
                'invoice' => 0,
                'ts' => ''
            ],
            'RUB' => [
                'delta' => 0,
                'bill' => 0,
                'invoice' => 0,
                'ts' => ''
            ]
        ];

        $r = $db->GetRow('
            SELECT
                *
            FROM
                newsaldo
            WHERE
                client_id=' . $fixclient_data['id'] . '
            AND
                currency="' . $fixclient_data['currency'] . '"
            AND
                is_history=0
            ORDER BY
                id DESC
            LIMIT 1
        ');
        if ($r) {
            $sum[$fixclient_data['currency']]
                =
                [
                    'delta' => 0,
                    'bill' => $r['saldo'],
                    'invoice' => 0,
                    'ts' => $r['ts'],
                    'saldo' => $r['saldo'],
                    'last_saldo' => $r['saldo'],
                    'last_saldo_ts' => $r['ts'],
                ];
        } else {
            $sum[$fixclient_data['currency']]
                =
                [
                    'delta' => 0,
                    'bill' => 0,
                    'invoice' => 0,
                    'ts' => ''
                ];
        }

        $get_income_goods_on_bill_list = get_param_integer('get_income_goods_on_bill_list', false);
        $design->assign('get_income_goods_on_bill_list', $get_income_goods_on_bill_list);

        $billListFilter = get_param_raw('bill_list_filter_income', null);

        $isIncomeExpense = $clientAccount->contract->financial_type == ClientContract::FINANCIAL_TYPE_YIELD_CONSUMABLE;

        if ($billListFilter && !$isIncomeExpense) {
            $billListFilter = null;
        }

        if (!$billListFilter && $isIncomeExpense) {
            $billListFilter = 'full';
        }

        $design->assign('bill_list_filter_income', $billListFilter);
        $design->assign('is_bill_list_filter', $isIncomeExpense);

        $design->assign('currency', $clientAccount->currency);

        $R1 = $db->AllRecords($q = '
                SELECT * FROM (
            SELECT
                "bill" AS type, P.bill_no, "" AS bill_id, ext_bill_no AS bill_no_ext, 
                bill_date, payment_date, client_id, currency, P.sum, is_payed, is_show_in_lk,
                P.comment, postreg, nal, 
                IF(state_id IS NULL OR (state_id IS NOT NULL AND state_id !=21), 0,1) AS is_canceled,
                is_pay_overdue,
                ' . (
            $sum[$fixclient_data['currency']]['ts']
                ? 'IF(bill_date >= "' . $sum[$fixclient_data['currency']]['ts'] . '",1,0)'
                : '1'
            ) . ' AS in_sum, 
                sum_correction,
                P.operation_type_id,
                bf.name as file_name,
                ' . (true /* $isIncomeExpense && $billListFilter */ ? 'ifnull(
                    (SELECT sum(sum) FROM invoice i WHERE i.bill_no = P.bill_no GROUP BY P.bill_no),
                    (SELECT  COALESCE(-sum(ext_vat)-sum(ext_sum_without_vat), -sum(ext_sum_without_vat)) FROM `newbills_external` e where e.bill_no = P.bill_no GROUP BY P.bill_no)
                )' : 'P.sum') . ' AS invoice_sum,
                -aec.sum as correction_sum
            FROM
                newbills P
                LEFT JOIN newbills_external USING (bill_no)
                LEFT JOIN tt_troubles t USING (bill_no)
                LEFT JOIN tt_stages ts ON  (ts.stage_id = t. cur_stage_id)
                LEFT JOIN newbills_external_files bf using (bill_no)
                LEFT JOIN ' . \app\modules\uu\models\AccountEntryCorrection::tableName() . ' aec ON (P.client_id = aec.client_account_id and P.bill_no = aec.bill_no)
            WHERE
                client_id=' . $fixclient_data['id'] . '
                ' . ($isMulty && !$isViewCanceled ? " and (state_id is null or (state_id is not null and state_id !=21)) " : "") . '
                ' . ($billListFilter && $billListFilter != 'full' ? 'AND ' . ($billListFilter == 'income' ? 'P.sum > 0' : 'P.sum < 0') : '') . '
                ) bills  ' .
            (($get_income_goods_on_bill_list) ? 'union
                (
                    ### incomegoods
                    SELECT
                    "income_order" as type,
                    number as bill_no,
                    convert(g.id using utf8mb4) as bill_id,
                    "" as bill_no_ext,
                    cast(date as date) as bill_date,
                    "" as payment_date, 
                    client_card_id as client_id,
                    if(currency = "RUB", "RUB", currency) as currency,
                    sum,
                    is_payed,
                    0 is_show_in_lk,
                    "" `comment`,
                    "0000-00-00" postreg ,
                    "" nal,
                    0,
                    1 in_sum,
                    0 as is_pay_overdue,

                    null as sum_correction,
                    1 as operation_type_id,
                    null as file_name,
                    0 AS invoice_sum,
                    0 as correction_sum

                    FROM `g_income_order` g
                        LEFT JOIN tt_troubles t ON (g.id = t.bill_id)
                        LEFT JOIN tt_stages ts ON  (ts.stage_id = t.cur_stage_id)
                    where client_card_id = "' . $fixclient_data['id'] . '" and state_id != 40 and deleted=0
                )' : ' ') .
            'order BY
                bill_date DESC,
                bill_no DESC
            LIMIT 1000
        ', '', MYSQLI_ASSOC);


//        if (isset($sum[$fixclient_data['currency']]['saldo']) && $sum[$fixclient_data['currency']]['saldo'] > 0) {
//            array_unshift($R1, [
//                'bill_no' => 'saldo',
//                'bill_date' => $sum[$fixclient_data['currency']]['ts'],
//                'client_id' => $fixclient_data['id'],
//                'currency' => $fixclient_data['currency'],
//                'sum' => $sum[$fixclient_data['currency']]['saldo'],
//                'is_payed' => 1,
//                'comment' => '',
//                'postreg' => $sum[$fixclient_data['currency']]['ts'],
//                'nal' => 'prov',
//                'in_sum' => 1
//            ]);
//            $sum[$fixclient_data['currency']]['saldo'] = 0;
//        }

        $R2 = $db->AllRecords('
            select
                P.id, P.client_id, P.payment_no, P.payment_date, P.oper_date, P.type, P.sum, P.comment, P.add_date, P.add_user, P.p_bill_no, P.p_bill_vis_no,
                U.user as user_name,
                ' . (
            $sum[$fixclient_data['currency']]['ts']
                ? 'IF(P.payment_date>="' . $sum[$fixclient_data['currency']]['ts'] . '",1,0)'
                : '1'
            ) . ' as in_sum,
                P.payment_id,
                P.bill_no,
                P.sum_pay,
                P.bank,
                ai.info_json,
                atl.uuid_log as uuid_log_json 
                ,pi.payer_inn
                ,pi.payer_bik
                ,pi.payer_bank
                ,pi.payer_account
                ,pi.getter_inn
                ,pi.getter_bik
                ,pi.getter_bank
                ,pi.getter_account
                ,pi.payer
                ,pi.getter
                ,pi.comment as pi_comment
            from (    SELECT P.id, P.client_id, P.payment_no, P.payment_date, P.oper_date, P.type, P.sum, P.comment, P.add_date, P.add_user, P.bill_no as p_bill_no, P.bill_vis_no as p_bill_vis_no,
                        L.payment_id, L.bill_no, L.sum as sum_pay, P.bank
                    FROM newpayments P LEFT JOIN newpayments_orders L ON L.client_id=' . $fixclient_data['id'] . ' and P.id=L.payment_id
                    WHERE P.client_id=' . $fixclient_data['id'] . '
                    UNION
                    SELECT P.id, P.client_id, P.payment_no, P.payment_date, P.oper_date, P.type, P.sum, P.comment, P.add_date, P.add_user, P.bill_no as p_bill_no, P.bill_vis_no as p_bill_vis_no,
                        L.payment_id, L.bill_no, L.sum as sum_pay, P.bank
                    FROM newpayments P RIGHT JOIN newpayments_orders L ON P.client_id=' . $fixclient_data['id'] . ' and P.id=L.payment_id
                    WHERE L.client_id=' . $fixclient_data['id'] . '
                ) as P

            LEFT JOIN newpayment_api_info ai on ai.payment_id = P.id
            LEFT JOIN newpayment_info pi on pi.payment_id = P.id
            LEFT JOIN payment_atol atl on atl.id = P.id
            LEFT JOIN user_users as U on U.id=P.add_user

            order by
                P.payment_date desc
            limit 1000
                ',
            '', MYSQLI_ASSOC);
        $result = [];

        $bill_total_add = ['p' => 0, 'n' => 0];

        $paymentStorage = [];
        $paymentInfoStorage = [];

        foreach ($R1 as $k => $r) {
            if ($r['sum'] > 0) {
                $bill_total_add['p'] += $r['sum'];
            }
            if ($r['sum'] < 0) {
                $bill_total_add['n'] += $r['sum'];
            }

            $r['operationType'] = OperationType::getNameById($r['operation_type_id']);
            $v = [
                'bill' => $r,
                'date' => $r['bill_date'],
                'pays' => [],
                'delta' => -$r['sum'],
                'delta2' => -$r['sum'],
                'isCanceled' => $r['is_canceled']
            ];

            foreach ($R2 as $k2 => &$r2) {

                if (!isset($paymentStorage[$r2['id']])) {
                    $payment = new Payment();
                    $payment->setAttributes($r2, false);

                    $info = new \app\models\PaymentInfo();
                    $info->payment_id = $payment->id;
                    $info->setAttributes($r2, false);
                    $info->comment = $r2['pi_comment'];


                    $paymentStorage[$r2['id']] = $payment;
                    $paymentInfoStorage[$r2['id']] = $info;
                } else {
                    $payment = $paymentStorage[$r2['id']];
                    $info = $paymentInfoStorage[$r2['id']];
                }

                $r2['info_json'] = \app\models\PaymentInfo::getInfoText($payment, $info);

                if (!$r2['comment'] && $r2['info_json']) {
                    $r2['comment'] = $payment->comment;
                }

                if ($r2['uuid_log_json']) {
                    $uuidLog = json_decode($r2['uuid_log_json'], true);
                    $status = $uuidLog['status'] ?? false;
                    $atolStatus = false;
                    if ($status !== false) {
                        if ($status == 'done') {
                            $atolStatus = ['status' => 'done', 'text' => $uuidLog['payload']['ofd_receipt_url']];
                        }else{
                            $atolStatus = ['status' => $status, 'text' => $uuidLog['error']['text']];
                        }
                    }
                    $r2['uuid_log'] = $atolStatus;
                }

                if (strpos($r2['payment_id'], '-')) {
                    $r2['currency'] = 'RUB';
                    $r2['id'] = $r2['payment_id'];
                    $R2[$k2]['currency'] = 'RUB';
                    $R2[$k2]['id'] = $r2['payment_id'];
                }
                if ($r['bill_no'] == $r2['p_bill_no']) {
                    if (!isset($v['pays'][$r2['id']])) {
                        $v['delta'] += $r2['sum'];
                        $v['pays'][$r2['id']] = $r2;
                    }
                }
            }

            $R1[$k]['v'] = $v;
        }

        foreach ($R1 as $k => $r) {
            $v = $r['v'];
            foreach ($R2 as $k2 => $r2) {
                if ($r['bill_no'] == $r2['bill_no']) {
                    $v['delta2'] += $r2['sum_pay'];
                    if ($r['bill_no'] != $r2['p_bill_no']) {
                        $r2['comment'] = '';
                    }
                    $v['pays'][$r2['id']] = $r2;
                    unset($R2[$k2]);
                }
            }
            $R1[$k]['v'] = $v;
        }

        foreach ($R1 as $r) {
            $v = $r['v'];
            if ($r['in_sum']) {
                $sum[$r['currency']]['bill'] += $r['sum'];
                $sum[$r['currency']]['invoice'] += $r['invoice_sum'];
                $sum[$r['currency']]['delta'] -= $v['delta'];
            }
            $result[$r['bill_no'] . '-' . $r['bill_date']] = $v;
        }
        foreach ($R2 as $r2) {
            if ($r2['sum_pay'] == '' || $r2['sum_pay'] == 0) {
                continue;
            }
            $v = [
                'date' => $r2['payment_date'],
                'pays' => [$r2],
                'delta' => $r2['sum_pay'],
                'delta2' => $r2['sum_pay']
            ];
            if ($r2['in_sum']) {
                $sum[$fixclient_data['currency']]['delta'] -= $v['delta2'];
            }
            $result[] = $v;
        }
        if ($get_sum) {
            return $sum;
        }
        ## sorting
        $sk = [];
        foreach ($result as $bn => $b) {
            if (!isset($sk[$b['date']])) {
                $sk[$b['date']] = [];
            }
            $sk[$b['date']][$bn] = 1;
        }

        $sw = [];
        $buf = [];
        krsort($sk);

        foreach ($sk as $bn) {
            krsort($bn);
            foreach ($bn as $billno => $v) {
                $buf[$billno] = $result[$billno];

                $bDate = isset($result[$billno]) && isset($result[$billno]["bill"]) ? $result[$billno]["bill"]["bill_date"] : false;

                if ($bDate) {
                    $sw[$bDate] = $billno;
                }
            }
        }
        $result = $buf;

        ksort($buf);
        ksort($sw);

        $stDates = Organization::dao()->getWhenOrganizationSwitched($clientAccount->contract_id);

        if ($stDates) {
            foreach ($stDates as $date => $organizationId) {
                $ks = false;
                foreach ($sw as $bDate => $billNo) {
                    if ($bDate >= $date) {
                        $ks = $billNo;
                        break;
                    }
                }

                if ($ks && isset($result[$ks])) {
                    $organization = Organization::find()->byId($organizationId)->actual($date)->one();
                    $result[$ks]['organization_switched'] = $organization;
                }
            }
        }

        $qrs = [];
        $qrsDate = [];
        foreach ($db->QuerySelectAll("qr_code", ["client_id" => $fixclient_data["id"]]) as $q) {
            $qrs[$q["bill_no"]][$q["doc_type"]] = $q["id"];
            $qrsDate[$q["bill_no"]][$q["doc_type"]] = $q["date"];
        }

        $billNos = [];
        foreach ($result as $i => $r) {
            foreach ($r as $j => $bill) {
                if (isset($bill['bill_no']) && ($bill['operation_type_id'] == OperationType::ID_COST)) {
                    $billNos[] = $bill['bill_no'];
                }
            }
        }

        $extBills = BillExternal::find()
            ->where(['bill_no' => $billNos])
            ->indexBy('bill_no')
            ->select(['bill_no', 'ext_vat', 'ext_sum_without_vat'])
            ->asArray()
            ->all();

        foreach ($result as $i => $r) {
            foreach ($r as $j => $bill) {
                if (isset($bill['bill_no']) && ($bill['operation_type_id'] == OperationType::ID_COST)) {
                    $ext = $extBills[$bill['bill_no']] ?? null;//BillExternal::findOne(['bill_no' => $bill['bill_no']]);
                    if ($ext) {
                        $result[$i][$j]['invoice_sum'] = ($ext['ext_sum_without_vat'] + $ext['ext_vat']) * -1;
                    }
                }
            }
        }

        $design->assign('client_type', $clientType);
        $design->assign("qrs", $qrs);
        $design->assign("qrs_date", $qrsDate);
        $bill_total_add['t'] = $bill_total_add['n'] + $bill_total_add['p'];
        $design->assign('bill_total_add', $bill_total_add);
        #krsort($result);
        $design->assign('billops', $result);
        $design->assign('sum', $sum);
        $design->assign('sum_cur', $sum[$fixclient_data['currency']]);
        $design->assign('currency', $clientAccount->currency);
        $design->assign('sum_current_statement', uuBill::getUnconvertedAccountEntries($clientAccount->id)->sum('price_with_vat') ?: 0);
        $design->assign('realtime_balance', $clientAccount->billingCounters->getRealtimeBalance());
        $design->assign(
            'saldo_history',
            $db->AllRecords('
                SELECT
                    newsaldo.*,
                    user_users.name AS user_name
                FROM
                    newsaldo
                LEFT JOIN
                    user_users
                ON
                    user_users.id = newsaldo.edit_user
                WHERE
                    client_id=' . $fixclient_data['id'] . '
                ORDER BY
                    id DESC
            ')
        );

        //$design->assign("fixclient_data", $fixclient_data);
        $design->AddMain('newaccounts/bill_list_full.tpl');
    }

    function newaccounts_bill_create_income($fixclient)
    {
        global $design, $db, $user, $fixclient_data;
        if (!$fixclient) {
            return;
        }
        $currency = get_param_raw('currency');

        $bill = new \Bill(null, $fixclient_data, time(), 0, $currency, OperationType::ID_PRICE);
        $no = $bill->GetNo();
        unset($bill);

        if ($design->ProcessEx('errors.tpl')) {
            header("Location: " . $design->LINK_START . "module=newaccounts&action=bill_view&bill=" . $no);
            exit();
        }
    }

    function newaccounts_bill_create_outcome($fixclient)
    {
        global $design, $db, $user, $fixclient_data;
        if (!$fixclient) {
            return;
        }

        $currency = get_param_raw('currency');

        $bill = new \Bill(null, $fixclient_data, time(), 0, $currency, OperationType::ID_COST);

        $no = $bill->GetNo();
        unset($bill);

        $design->assign('billl', $no);
        if ($design->ProcessEx('errors.tpl')) {
            header("Location: " . $design->LINK_START . "module=newaccounts&action=bill_view&bill=" . $no);
            exit();
        }
    }

    function newaccounts_bill_create_correction($fixclient)
    {
        global $design, $db, $user, $fixclient_data;

        if (!$fixclient) {
            return;
        }

        $origBill = $_GET['orig_bill'];
        $currency = get_param_raw('currency');

        $bill = new \Bill(null, $fixclient_data, time(), 0, $currency, OperationType::ID_COST);

        $no = $bill->GetNo();
        unset($bill);

        if ($design->ProcessEx('errors.tpl')) {
            header("Location: " . $design->LINK_START . "module=newaccounts&action=bill_edit&bill=" . $no . '&orig_bill=' . $origBill);
            exit();
        }
    }


    function newaccounts_bill_view($fixclient)
    {
        global $design, $db, $user, $fixclient_data;

        //old all4net bills
        if (isset($_POST['bill_no']) && preg_match('/^\d{6}-\d{4}-\d+$/', $_POST['bill_no'])) {

            //set doers
            if (isset($_POST['select_doer'])) {
                $d = (int)$_POST['doer'];
                /** @var Bill $bill */
                $bill = Bill::findOne(['bill_no' => $_POST['bill_no']]);
                if ($bill) {
                    $bill->courier_id = $d;
                    $bill->save();
                }
            }
            // 1c || all4net bills
        } elseif (isset($_GET['bill']) && preg_match('/^(\d{6}\/\d{4,6}|\d{6,7})$/', $_GET['bill'])) {
            $design->assign('1c_bill_flag', true);
            if (isset($_POST['select_doer'])) {
                $d = (int)$_POST['doer'];
                $bill = Bill::findOne(['bill_no' => $_POST['bill_no']]);
                if ($bill) {
                    $bill->courier_id = $d;
                    $bill->save();
                }
            }

            //income orders
            //}elseif(isset($_GET["bill"]) && preg_match("/\w{8}-\w{4}-\w{4}-\w{4}-\w{12}/", $_GET["bill"])){ // incoming orders
        } elseif (isset($_GET["income_order_id"]) || (isset($_GET["bill"]) && preg_match("/\d{2}-\d{8}/", $_GET["bill"]))
        ) { // incoming orders

            //find last order
            $order = GoodsIncomeOrder::first([
                    "conditions" => (isset($_GET["income_order_id"]) ? ["id" => $_GET["income_order_id"]] : ["number" => $_GET["bill"]]),
                    "order" => "date desc",
                    "limit" => 1
                ]
            );

            if (!$order) {
                die("Неизвестный тип документа");
            }

            header("Location: ./?module=incomegoods&action=order_view&id=" . urlencode($order->id));
            exit();

            // stat bills
        } elseif (preg_match("/\d{6}-\d{4,6}/", $_GET["bill"]) || preg_match('/^\d{10,}$/', $_GET['bill'])) {
            $a = 1;
            // Попытка установки статуса "Проверено"
            if (isset($_POST['select_doer']) && $bill = Bill::findOne(['bill_no' => $_GET['bill']])) {
                $bill->courier_id = $bill->courier_id > 0 ?
                    0 : Yii::$app->user->identity->id;
                if (!$bill->save()) {
                    throw new ModelValidationException($bill);
                }
            }
        } elseif ($_GET['bill'] == 'current_statement') {
            $bill = new Bill();
            $bill->bill_no = 'Текущая выписка';
            $bill->client_id = $fixclient_data->id;
            $bill->bill_date = date("Y-m-d");
            $bill->price_include_vat = 0;
            $bill->sum = \app\modules\uu\models\Bill::getUnconvertedAccountEntries($fixclient_data->id)->sum('price_with_vat');
            $st = \app\modules\uu\models\Bill::getUnconvertedAccountEntries($fixclient_data->id)->all();

            $design->assign('bill', $bill);
            $design->assign('bill_lines', $st);

            $design->AddMain('newaccounts/bill_view_current_statement.tpl');
            return;

        } else {
            die("Неизвестный тип документа");
        }
        $bill_no = get_param_protected("bill");
        if (!$bill_no) {
            return;
        }

        $bill = new \Bill($bill_no);
        /** @var Bill $newbill */
        $newbill = Bill::findOne(['bill_no' => $bill_no]);

        if (get_param_raw('err') == 1) {
            trigger_error2('Невозможно добавить строки из-за несовпадния валют');
        }
        if (preg_match('/^\d{6}-\d{4}-(\d+)$/', trim($bill->getNo()), $match)) {
            $design->assign('all4net_order_number', $match[1]);
        } else {
            $design->assign('all4net_order_number', false);
        }

        $connectedBillsList = BillOutcomeCorrection::find()->where(['original_bill_no' => $bill_no])->asArray()->all();
        $originalBill = BillOutcomeCorrection::find()->where(['bill_no' => $bill_no])->one();

        if (isset($originalBill)) {
            $design->assign('orig_bill', $originalBill);
        }

        if (isset($connectedBillsList)) {
            foreach($connectedBillsList as $i => $connection){
                $billExternal = BillExternal::find()->where(['bill_no' => $connection['bill_no']])->one();
                $connectedBillsList[$i]['sum'] = $billExternal['ext_vat'] +  $billExternal['ext_sum_without_vat'];
            }

            $design->assign('connected_bills', $connectedBillsList);
        }

        $adminNum = false;
        if (preg_match("/^(\d{6,7})$/", trim($bill->getNo()), $match)) {
            $adminNum = $match[1];
            $design->assign('order_editor', $bill->Get("editor"));
        }
        $design->assign('admin_order', $adminNum);


        $design->assign('is_new_invoice', $bill->Get('bill_date') >= Invoice::DATE_ACCOUNTING);
        $design->assign('invoice_info', Invoice::find()
            ->select(['sum', 'is_reversal', 'type_id', 'number'])
            ->where([
                'bill_no' => $bill->GetNo()
            ])
            ->indexBy('type_id')
            ->asArray()
            ->all()
        );

        $client = ClientAccount::find()->where(['id' => $fixclient])->one();
//        $clientType = $client->contract->financial_type;

        $L = $bill->GetLines();
        $uuL = array_map(function($l) {
            $l['amount'] = (float)$l['amount'];
            $l['price'] = (float)$l['price'];
            return $l;
        },\app\models\BillLineUu::find()
                ->where(['bill_no' => $bill->GetNo()])
                ->orderBy(['uu_account_entry_id' => SORT_ASC])
                ->asArray()
                ->indexBy('uu_account_entry_id')
                ->all() ?? []);

        $L2 = [];
        $isAutomatic = false;
        if ($L) {
            foreach ($L as $line) {
                $L2[] = $line;
                if ($line['service']) {
                    $isAutomatic = true;
//                    break;
                }

                if ($line['uu_account_entry_id'] && isset($uuL[$line['uu_account_entry_id']])) {

                    $line = $uuL[$line['uu_account_entry_id']];
                    $line['is_updated'] = true;
                    $L2[] = $line;
                    unset($uuL[$line['uu_account_entry_id']]);
                }
            }
        }

        $L = array_merge($L2,
            array_map(function ($i) {
                $i['is_deleted'] = true;
                return $i;
            }, $uuL));


        $b = $bill->GetBill();
        $rewardBill = RewardBill::findOne(['bill_id' => $b['id']]);
        $design->assign('reward_lines', $rewardBill->lines ?: []);
        $design->assign('reward_sum', $rewardBill->sum ?: 0);
        $design->assign('is_automatic', $isAutomatic);
        $design->assign('bill_lines', $L);
        $design->assign('invoice2_info', Invoice::getInfo($bill->GetNo()));
        $design->assign('bill', $bill->GetBill());
        $design->assign('bill_ext', $bill->GetExt());
        $design->assign('bill_extends_info', $newbill->extendsInfo);
        $design->assign('bill_manager', getUserName(Bill::dao()->getManager($bill->GetNo())));
        $design->assign('bill_comment', $bill->GetStaticComment());
        $design->assign('bill_courier', $bill->GetCourier());
        $design->assign('bill_bonus', $this->getBillBonus($bill->GetNo()));
        $design->assign('bill_file_name', $newbill->getExtFile()->select('name')->scalar());
        $design->assign('bill_is_new_company', [
            'retail_to_service' => Bill::dao()->isBillNewCompany($newbill, 11, 21),
            'telekom_to_service' => Bill::dao()->isBillNewCompany($newbill, 1, 21),
            'service_to_abonserv' => Bill::dao()->isBillNewCompany($newbill, 21, 14),
            'abonserv_to_telekom' => Bill::dao()->isBillNewCompany($newbill, 14, 1),
        ]);
        $design->assign('bill_is_credit_note', Bill::dao()->isBillWithCreditNote($newbill));
        $design->assign('bill_is_one_zadatok', $bill->isOneZadatok());
        $design->assign('bill_is_editable', $newbill->isEditable());

        /*
           счет-фактура(1)-абонен.плата
           счет-фактура(2)-превышение
           счет-фктура (3)-если есть товар, тоесть тов.накладная
           Счет-фактура(4)-авансовая

           Акт (1) - абонен.плата
           Акт (2) - превышение
           Акт (3) - залог
           
           счет-фактура-акт(1)-абонен.плата
           счет-фактура-акт(2)-превышение
           
         */

        $invoices = [];
        if ($bill->Get('bill_date') >= Invoice::DATE_ACCOUNTING) {
            $invoices = Invoice::find()->where([
                'bill_no' => $bill->Get('bill_no'),
                'is_reversal' => 0,
            ])
                ->distinct()
                ->select('type_id')
                ->asArray()
                ->column();
        }
        [$bill_akts, $bill_invoices, $bill_upd, $bill_upd2] = $this->get_bill_docs($bill, $L);

        if ($invoices) {
            foreach (Invoice::$types as $invoiceType) {
                if (in_array($invoiceType, $invoices)) {
                    continue;
                }

                $bill_akts[$invoiceType] && $bill_akts[$invoiceType] = -1;
                $bill_invoices[$invoiceType] && $bill_invoices[$invoiceType] = -1;
                $bill_upd[$invoiceType] && $bill_upd[$invoiceType] = -1;
            }
        }

        $design->assign('bill_akts', $bill_akts);

        $design->assign('bill_invoices', $bill_invoices);

        $design->assign('bill_upd', $bill_upd);
        $design->assign('bill_upd2', $bill_upd2);

        $design->assign('template_bills',
            $db->AllRecords('
                SELECT *
                FROM newbills
                WHERE client_id=2818
                    AND currency="' . $bill->Get('currency') . '"
                ORDER BY bill_no
            ')
        );

        $r = $bill->Client();
        ClientCS::Fetch($r);

        if ($r) {
            $r["client_orig"] = $r["client"];
            $BusinessId = ClientAccount::findOne($r['id'])->contract->business_id;
            if (access("clients", "read_multy")) {
                if ($BusinessId != Business::INTERNET_SHOP) {
                    trigger_error2('Доступ к клиенту ограничен');
                    return;
                }
            }

            if ($BusinessId == Business::INTERNET_SHOP && isset($_GET["bill"])) {
                $ai = $db->GetRow("SELECT fio FROM newbills_add_info WHERE bill_no = '" . $_GET["bill"] . "'");
                if ($ai) {
                    $r["client"] = $ai["fio"] . " (" . $r["client"] . ")";
                }
            }
        }

        $design->assign('bill_client', $r);
        $design->assign('bill_history',
            $db->AllRecords('
                SELECT
                    log_newbills.*,
                    user_users.user
                FROM log_newbills
                LEFT JOIN user_users ON user_users.id = user_id
                WHERE bill_no="' . $bill_no . '"
                ORDER BY ts DESC
            ')
        );
        $design->assign('doers',
            $db->AllRecords('
                SELECT *
                FROM courier
                WHERE enabled="yes"
                ORDER BY `depart` DESC, `name`
            ')
        );

        $design->assign("is_set_date",
            $bill->is1CBill() || $bill->isOneTimeService()); //дату документа можно установить в 1Сных счетах и счетах, с разовыми услугами

        $design->assign("store",
            $db->GetValue("SELECT s.name FROM newbills_add_info n, `g_store` s WHERE s.id = n.store_id AND n.bill_no = '" . $bill_no . "'"));

        $availableDocuments = DocumentReportFactory::me()->availableDocuments($newbill, 'bill');
        $documents = [];
        foreach ($availableDocuments as $document) {
            $documents[] = [
                'class' => $document->getDocType(),
                'title' => $document->getName(),
            ];
        }
        $design->assign('available_documents', $documents);

        if ($r->account_version == ClientAccount::VERSION_BILLER_UNIVERSAL) {
            $listOfInvoices = [];
            foreach (Language::getList() as $languageCode => $languageTitle) {
                $listOfInvoices[] = [
                    'langCode' => $languageCode,
                    'langTitle' => $languageTitle,
                    'langFlag' => explode('-', $languageCode)[0],
                    'number' => $newbill->bill_no,
                    'month' => substr($newbill->bill_date, 0, 7),
                ];
            }
            $design->assign('listOfInvoices', $listOfInvoices);
        }

        $design->assign("_showHistoryBill", Yii::$app->view->render('//layouts/_showHistory', ['model' => $newbill]));

        $design->assign('bill_correction_info', $newbill->getCorrectionInfo());

        $design->assign('isPartnerRewards', $newbill->isHavePartnerRewards());

        $design->assign('isPartnerRewardsV3', $newbill->isHavePartnerRewards());

        $design->assign('operationType', OperationType::getNameById($newbill->operation_type_id, true));

        $design->AddMain('newaccounts/bill_view.tpl');

        $tt = $db->GetRow("SELECT * FROM tt_troubles WHERE bill_no='" . $bill_no . "'");
        if ($tt) {
            StatModule::tt()->dont_filters = true;
            StatModule::tt()->cur_trouble_id = $tt['id'];
            StatModule::tt()->tt_view($fixclient);
            StatModule::tt()->dont_again = true;
        }
    }

    /**
     * Получение партнера из объекта класса ClientAccount
     *
     * @param @param ClientAccount $clientAccount
     * @return int|null
     */
    function getPartnerContractId($clientAccount)
    {
        return $clientAccount->contract->partner_contract_id;
    }

    function get_bill_docs(\Bill &$bill, $L = null)
    {
        return self::get_bill_docs_static($bill->GetNo(), $L);
    }

    static function get_bill_docs_static($billNo, $L = null)
    {
        $bill_akts = $bill_invoices = $bill_upd = $bill_upd2 = [];

        if (($doctypes = BillDocument::dao()->getByBillNo($billNo)) == false) {
            $doctypes = BillDocument::dao()->updateByBillNo($billNo, $L, true);
        }

        if ($doctypes && count($doctypes) > 0) {
            for ($i = 1; $i <= 3; $i++) {
                $bill_akts[$i] = $doctypes['a' . $i];
            }
            for ($i = 1; $i <= 7; $i++) {
                $bill_invoices[$i] = $doctypes['i' . $i];
            }
            for ($i = 1; $i <= 2; $i++) {
                $bill_upd[$i] = $doctypes['ia' . $i];
            }
            for ($i = 1; $i <= 3; $i++) {
                $bill_upd2[$i] = $doctypes['upd2-' . $i];
            }
        }

        return [$bill_akts, $bill_invoices, $bill_upd, $bill_upd2];
    }

    function newaccounts_bill_courier_comment($comment = '')
    {
        $doerId = get_param_raw("doer_id", "0");
        $billNo = get_param_raw("bill", "");
        $comment = trim(get_param_protected("comment", ""));
        var_export($comment);
        var_export($billNo);
        if ($comment && $billNo) {
            global $db;
            $db->Query("UPDATE tt_troubles SET doer_comment = '" . $comment . "' WHERE bill_no = '" . $billNo . "'");
            all4geo::getId($billNo, $doerId, $comment);
        }

        header("Location: ./?module=newaccounts&action=bill_view&bill=" . urlencode($billNo) . "#doer_comment");
        exit();
    }

    function newaccounts_bill_cleared()
    {

        $bill_no = $_POST['bill_no'];
        $bill = Bill::findOne(['bill_no' => $bill_no]);

        $bill->is_approved = $bill->is_approved ? 0 : 1;
        $bill->sum = $bill->is_approved ? $bill->sum_with_unapproved : 0;
        $bill->save();
        $bill->dao()->recalcBill($bill);
        ClientAccount::dao()->updateBalance($bill->client_id, false);

        header('Location: index.php?module=newaccounts&action=bill_view&bill=' . $bill_no);
        exit();
    }

    function newaccounts_bill_edit($fixclient)
    {
        global $design, $db, $user, $fixclient_data;
        $bill_no = get_param_protected("bill");
        if (!$bill_no) {
            return;
        }

        if (!preg_match("/20[0-9]{4}-[0-9]{4}/i", $bill_no)) {
            header("Location: ./?module=newaccounts&action=make_1c_bill&bill_no=" . $bill_no);
            exit();
        }

        $bill = new \Bill($bill_no);
        if ($bill->IsClosed()) {
            header("Location: ./?module=newaccounts&action=bill_view&bill=" . $bill_no);
            exit();
        }

        $isCorrection = false;
        if (isset($_GET['orig_bill'])){
            $orig_bill = $_GET['orig_bill'];
            $billCorrection = BillOutcomeCorrection::findOne(['bill_no' => $bill_no, 'original_bill_no' => $orig_bill]);
            if(!$billCorrection){
                $billCorrection = new BillOutcomeCorrection();
                $billCorrection->bill_no = $bill_no;
                $billCorrection->original_bill_no = $orig_bill;

                if (!$billCorrection->save()) {
                    throw new ModelException('Ошибка сохранения');
                }
            }

            $isCorrection = true;
        }

        $billModel = Bill::findOne(['bill_no' => $bill_no]);

        \app\classes\Assert::isObject($billModel);

        if ($billModel->isCorrectionType()) {
            \Yii::$app->session->addFlash('error', 'Нельзя редактировать корректировку');
            header("Location: " . $design->LINK_START . "module=newaccounts&action=bill_view&bill=" . urlencode($bill_no));
            exit;
        }


        $_SESSION['clients_client'] = $bill->Get("client_id");
        $fixclient_data = ClientAccount::findOne($bill->Get("client_id"));
        if (!$bill->CheckForAdmin()) {
            return;
        }

        $billCorrection = BillOutcomeCorrection::find()->where(['bill_no' => $bill_no])->one();
        if($billCorrection){
            $design->assign('corr_bill',  date('d-m-Y', strtotime($billCorrection->GetDate())));
            $design->assign('corr_number', (string)$billCorrection->correction_number);
            $billModel->comment = 'правка от ' . $billCorrection->original_bill_no;
            if (!$billModel->save()) {
                throw new ModelException('Ошибка сохранения');
            }
            $isCorrection = true;
        }

        if ($auId = get_param_integer('auid')) {
            $where = ['bill_no' => $billModel->bill_no, 'uu_account_entry_id' => $auId];
            $uLine = \app\models\BillLineUu::find()->where($where)->one();

            if ($uLine) {
                $line = \app\models\BillLine::find()->where($where)->one();
                if (!$line) {
                    $line = new \app\models\BillLine();
                }
                $maxSort = \app\models\BillLine::find()->where(['bill_no' => $billModel->bill_no])->max('sort') ?: 0;

                $line->sort = $maxSort + 1;

                $line->setAttributes($uLine->getAttributes(), false);
                $transaction = \Yii::$app->db->beginTransaction();
                try {
                    if (!$line->save()) {
                        throw new ModelValidationException($line);
                    }
                    if (!$uLine->delete()) {
                        throw new ModelValidationException($uLine);
                    }

                    $bill->Save();
                    $billModel->refresh();

                    $billModel->checkUuCorrectionBill();

                    $transaction->commit();
                } catch (Exception $e) {
                    $transaction->rollBack();
                    throw $e;
                }
            }

            header("Location: /?module=newaccounts&action=bill_edit&bill=" . urlencode($billModel->bill_no));
            exit();
        }

        $design->assign('is_correction', $isCorrection);
        $design->assign('show_bill_no_ext', in_array($fixclient_data['status'], ['distr', 'operator']));
        $design->assign('clientAccountVersion', $fixclient_data['account_version']);
        $design->assign('bill', $bill->GetBill());
        $design->assign('bill_ext', $bill->GetExt());
        $design->assign('bill_ext_file', $bill->GetExtFile());
        $design->assign('bill_date', date('d-m-Y', $bill->GetTs()));
        $design->assign('pay_bill_until', date('d-m-Y', strtotime($bill->get("pay_bill_until"))));
        $design->assign('l_couriers', Courier::getList($isWithEmpty = true));
        $design->assign('isEditable', $billModel->isEditable());
        $design->assign("_showHistoryLines", Yii::$app->view->render('//layouts/_showHistory', ['parentModel' => [new \app\models\BillLine(), $billModel->id]]));
        $design->assign('drafted_invoice_dates_history', LogBill::dao()->getLog($billModel->bill_no)->asArray()->all());
        $design->assign('draftedInvoices', $billModel->getInvoices()->andWhere(['idx' => null, 'type_id' => [1, 2]])->all());
        $design->assign('tax_rate_default', $fixclient_data->getTaxRateOnDate(date('Y-m-d', strtotime('first day of this month', $bill->GetTs()))));
        $lines = $bill->GetLines();

        if ($billModel->operation_type_id != OperationType::ID_COST) {
            $maxSort = $bill->GetMaxSort();
            foreach(range(1, 5) as $idx) {
                $lines[$maxSort + $idx] = [];
            };

        } else {
            if (!$lines) {
                $lines[$bill->GetMaxSort() + 1] = [];
            }
        }

        $uuLines = $billModel->uuLines;
        if ($uuLines) {
            foreach ($lines as $k => $line) {
                if (!$line['uu_account_entry_id']) {
                    continue;
                }
                if (isset($uuLines[$line['uu_account_entry_id']])) {
                    $lines[$k]['is_uu_edit'] = true;
                    unset($uuLines[$line['uu_account_entry_id']]);
                }
            }
            $uuLines = array_map(function(\app\models\BillLineUu $line){return $line->getAttributes();}, $uuLines);
        }

        $design->assign('bill_lines', $lines);
        $design->assign('bill_lines_uu', $uuLines);
        $design->AddMain('newaccounts/bill_edit.tpl');
    }

    function newaccounts_bill_comment($fixclient)
    {
        $billNo = get_param_protected("bill");

        $bill = Bill::findOne(['bill_no' => $billNo]);
        $bill->comment = nl2br(strip_tags(get_param_raw("comment")));
        $bill->save();

        header("Location: /?module=newaccounts&action=bill_view&bill=" . $billNo);
        exit();
    }

    function newaccounts_bill_nal($fixclient)
    {
        $billNo = get_param_protected("bill");

        $bill = Bill::findOne(['bill_no' => $billNo]);
        $bill->nal = get_param_raw("nal");
        $bill->save();

        header("Location: /?module=newaccounts&action=bill_view&bill=" . $billNo);
        exit();
    }

    function newaccounts_bill_postreg($fixclient)
    {
        global $design, $db, $user, $fixclient_data;

        $bills = get_param_raw("bill", []);
        if (!$bills) {
            return;
        }

        if (!is_array($bills)) {
            $bills = [$bills];
        }

        $bills = array_filter($bills, function($bill) {return strlen($bill) > 1;});

        $option = get_param_protected('option');
        $isImport = get_param_raw("from", "") == "import";

        foreach ($bills as $bill_no) {
            $bill = Bill::findOne(['bill_no' => $bill_no]);
            if ($bill) {
                $bill->postreg = $option ? '' : date('Y-m-d');
                if (!$bill->save()) {
                    throw new ModelValidationException($bill);
                }
            }
        }
        if ($design->ProcessEx('errors.tpl')) {
            header("Location: " . $design->LINK_START . "module=newaccounts&action=bill_view&bill=" . $bill_no);
            exit();
        }
    }

    function newaccounts_bill_apply($fixclient)
    {
        global $design, $db, $user, $fixclient_data;

        $_SESSION['clients_client'] = get_param_integer("client_id", 0);

        $bill_no = get_param_protected("bill");
        $bill_corr = BillOutcomeCorrection::find()->where(['bill_no' => $bill_no])->one();
        if (!$bill_no) {
            return;
        }

        $billModel = Bill::findOne(['bill_no' => $bill_no]);

        if (!$billModel) {
            return;
        }

        $bill_corr_date = get_param_raw('date_created');
        $bill_corr_num = get_param_raw('corr_number');
        $bill_nal = get_param_raw("nal");
        $billCourier = get_param_raw("courier");
        $bill_no_ext = get_param_raw("bill_no_ext");
        $invoice_no_ext = get_param_raw("invoice_no_ext");
        $invoice_date_ext = get_param_raw("invoice_date_ext");
        $registration_date_ext = get_param_raw("registration_date_ext");
        $vat_ext = get_param_raw("ext_vat", "");
        $ext_sum_without_vat = get_param_raw("ext_sum_without_vat", "");

        $ext_file = isset($_FILES["bill_ext_file"]) ? $_FILES["bill_ext_file"] : null;
        $ext_file_comment = get_param_raw('bill_ext_file_comment');

        $akt_no_ext = get_param_raw("akt_no_ext");
        $akt_date_ext = get_param_raw("akt_date_ext");

        $price_include_vat = get_param_raw('price_include_vat', 'N');
        $is_show_in_lk = get_param_raw('is_show_in_lk', 'N');
        $bill_no_ext_date = get_param_raw('bill_no_ext_date');
        $isToUuInvoice = get_param_raw('is_to_uu_invoice', null);
    
        $drafted_invoices_new_date = null;
        if (access('newaccounts_bills', 'invoice_date')) {
            $drafted_invoices_new_date = get_param_raw('drafted_invoices_new_date');
        }

        if ($drafted_invoices_new_date) {
            if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $drafted_invoices_new_date)) {
                Yii::$app->session->addFlash('error', 'Неверно указана новая дата счёт-фактуры.');
                header("Location: ?module=newaccounts&action=bill_edit&bill=" . $bill_no);
                exit();    
            }

            $billModel->invoice_date = date('Y-m-d', strtotime($drafted_invoices_new_date));
            if (!$billModel->save()) {
                throw new ModelException('Ошибка сохранения');
            }
            
            $invoices = $billModel->getInvoices()->andWhere(['idx' => null, 'type_id' => [1, 2]])->all();
            foreach ($invoices as $invoice) {
                $invoice->invoice_date = $billModel->invoice_date;
                if ($invoice->save()) {
                    LogBill::dao()->log($bill_no, "Дата счёт-фактур и актов обновлена. Новая дата: $drafted_invoices_new_date", $user);
                }
            }
        }

        $bill = new \Bill($bill_no);
        if (!$bill->CheckForAdmin()) {
            return;
        }
        $bill_date = new DatePickerValues('bill_date', $bill->Get('bill_date'));

        // проверка на изменений даты счета, при наличии зарегистрированной с/ф и выхода за пределы месяца
        if ($bill_date->getSqlDay() != $billModel->bill_date && $billModel->invoices) {
            $billDateFrom = (new DateTimeImmutable($billModel->bill_date))->modify('first day of this month');
            $billDateTo = $billDateFrom->modify('last day of this month');
            if ($bill_date->day < $billDateFrom || $bill_date->day > $billDateTo) {
                \Yii::$app->session->addFlash('error', 'Нельзя менять дату счета если зарегестрирована с/ф');
                header("Location: ?module=newaccounts&action=bill_edit&bill=" . $bill_no);
                exit();
            }
        }

        if($bill_corr_date){
            $bill_corr->date_created = date('Y-m-d', strtotime($bill_corr_date));
        }

        if($bill_corr_num){
            $bill_corr->correction_number = (int)$bill_corr_num;
        }

        // $clientType = $fixclient_data->contract->financial_type;

        // $lines = $bill->GetLines();
        // $isAutomatic = false;
        // foreach ($lines as $line) {
        //     if ($line['service']) {
        //         $isAutomatic = true;
        //         break;
        //     }
        // }

        // if (!$isAutomatic && ($clientType == ClientContract::FINANCIAL_TYPE_CONSUMABLES || $clientType == ClientContract::FINANCIAL_TYPE_YIELD_CONSUMABLE)) {
        //     $lines = $bill->GetLines();
        //     if ($lines) {
        //         foreach ($lines as $k => $line) {  
        //             $bill->EditLine($k, 'расход', 1, (($ext_sum_without_vat + $vat_ext) * - 1), 'service');        
        //         }
        //     } else {
        //         $bill->AddLine('расход', 1, (($ext_sum_without_vat + $vat_ext) * - 1), 'service', '', '', '', '');
        //     }
        //     if (!$bill->Save()) {
        //         throw new Exception();
        //     }
        // }

        $bill->Set('bill_date', $bill_date->getSqlDay());
        $billPayBillUntil = new DatePickerValues('pay_bill_until', $bill->Get('pay_bill_until'));
        $bill->Set('pay_bill_until', $billPayBillUntil->getSqlDay());
        $bill->SetCourier($billCourier);
        $bill->SetNal($bill_nal);
        $bill->SetExtNo($bill_no_ext);
        $bill->SetInvoiceNoExt($invoice_no_ext);
        $bill->SetInvoiceDateExt($invoice_date_ext);
        $bill->SetRegistrationDateExt($registration_date_ext);
        $bill->SetIsToUuInvoice($isToUuInvoice);
        $bill->SetAktNoExt($akt_no_ext);
        $bill->SetExtDate($bill_no_ext_date);
        $bill->SetAktDateExt($akt_date_ext);
        $bill->SetVatExt($vat_ext);
        $bill->SetSumWithoutVatExt($ext_sum_without_vat);

        $bill->SetPriceIncludeVat($price_include_vat == 'Y' ? 1 : 0);
        $bill->SetIsShowInLk($is_show_in_lk == 'Y' ? 1 : 0);
        if($bill_corr_date && $bill_corr_num) {
            if (!$bill_corr->save()) {
                throw new ModelException('Ошибка сохранения');
            }
        }

        if ($ext_file) {
            (new \app\classes\media\BillExtMedia($billModel))->addFile($ext_file, $ext_file_comment);
        }

        $item = get_param_raw("item");
        $amount = get_param_raw("amount");
        $price = get_param_raw("price");
        $type = get_param_raw("type");
        $tax_rate = get_param_raw("tax_rate");
        $del = get_param_raw("del", []);

        if (!$item || !$amount || !$price || !$type) { // Сохранение только "шапки" счета     
            $bill->Save();
            header("Location: ?module=newaccounts&action=bill_view&bill=" . $bill_no);
            exit();
        }

        // clear
        array_walk($item, function(&$i, $key) { $i = FormFieldValidator::cleanField($i);});

        $transaction = \Yii::$app->db->beginTransaction();
        try {

            $lines = $bill->GetLines();
            foreach(range(1, 5) as $idx) {
                $lines[$bill->GetMaxSort() + $idx] = [];
            }
            if ($billModel->isEditable()) {
                foreach ($lines as $k => $arr_v) {
//                    if ($arr_v && isset($arr_v['id_service']) && $arr_v['id_service'] >= 100000) {
//                        continue;
//                    }

                    if ((!isset($item[$k]) || (isset($item[$k]) && !$item[$k]) || (isset($del[$k]) && $del[$k])) && isset($arr_v['item'])) {
                        $bill->RemoveLine($k);
                    } elseif (isset($item[$k]) && $item[$k] && isset($arr_v['item'])) {
                        if (
                            $item[$k] != $arr_v['item'] 
                            || $amount[$k] != $arr_v['amount'] 
                            || $price[$k] != $arr_v['price'] 
                            || $type[$k] != $arr_v['type']
                            || $tax_rate[$k] != $arr_v['tax_rate']
                            ) {
                            $bill->EditLine($k, $item[$k], $amount[$k], $price[$k], $type[$k], $tax_rate[$k], $arr_v['uu_account_entry_id']);
                        }
                    } elseif (isset($item[$k]) && $item[$k]) {
                        $bill->AddLine($item[$k], $amount[$k], $price[$k], $type[$k], '', '', '', '', 0, $tax_rate[$k]);
                    }
                }
            }
            $bill->Save();
            $billModel->checkUuCorrectionBill();

            // если есть зарегистрированная с/ф, то обновить.
            if ($billModel->invoices && $billModel->isEditable()) {
                $billModel->generateInvoices();
            }
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }

        ClientAccount::dao()->updateBalance($bill->Client('id'), false);
        unset($bill);
        if ($design->ProcessEx('errors.tpl')) {
            header("Location: ?module=newaccounts&action=bill_view&bill=" . $bill_no);
            exit();
        }
    }

    function newaccounts_bill_add($fixclient)
    {
        global $design, $db, $user, $fixclient_data;
        $bill_no = get_param_protected("bill");
        if (!$bill_no) {
            return;
        }
        $obj = get_param_protected("obj");
        if (!$obj) {
            return;
        }
        $bill = new \Bill($bill_no);
        if (!$bill->CheckForAdmin()) {
            return;
        }
        $L = [
            'USD' => [
                'avans' => ["Аванс за подключение интернет-канала", 1, 500 / 27, 'zadatok'],
                'deposit' => ["Задаток за подключение интернет-канала", 1, SUM_ADVANCE, 'zadatok'],
                'deposit_back' => ["Возврат задатка за подключение интернет-канала", 1, -SUM_ADVANCE, 'zadatok'],
                'deposit_sub' => ["За вычетом ранее оплаченного задатка", 1, -SUM_ADVANCE, 'zadatok'],
            ],
            'RUB' => [
                'avans' => ["Аванс за подключение интернет-канала", 1, 500, 'zadatok'],
                'deposit' => ["Задаток за подключение интернет-канала", 1, SUM_ADVANCE * 27, 'zadatok'],
                'deposit_back' => [
                    "Возврат задатка за подключение интернет-канала",
                    1,
                    -SUM_ADVANCE * 27,
                    'zadatok'
                ],
                'deposit_sub' => ["За вычетом ранее оплаченного задатка", 1, -SUM_ADVANCE * 27, 'zadatok'],
            ]
        ];
        $err = 0;
        if ($obj == 'connecting' || $obj == 'connecting_ab') {
            $clientAccount = ClientAccount::findOne($fixclient_data['id']);

            if ($clientAccount->price_include_vat == $bill->Get('price_include_vat')) {

                $periodicalDate = new DateTime(\app\classes\Utils::dateBeginOfMonth($bill->Get('bill_date')),
                    $clientAccount->timezone);

                $connectingTransactions =
                    ClientAccountBiller::create($clientAccount, $periodicalDate, $onlyConnecting = true,
                        $connecting = $obj == 'connecting', $periodical = true, $resource = false)
                        ->createTransactions()
                        ->getTransactions();

                $connectingServices = [];

                foreach ($connectingTransactions as $transaction) {
                    $year = substr($transaction->billing_period, 0, 4);
                    $month = substr($transaction->billing_period, 5, 2);

                    $period_from = $year . '-' . $month . '-01';
                    $period_to = $year . '-' . $month . '-' . cal_days_in_month(CAL_GREGORIAN, $month, $year);

                    $bill->AddLine($transaction->name, $transaction->amount, $transaction->price, 'service',
                        $transaction->service_type, $transaction->service_id, $period_from, $period_to);
                    $connectingServices[] = ['type' => $transaction->service_type, 'id' => $transaction->service_id];
                }

                $b = Bill::findOne(['bill_no' => $bill->GetNo()]);
                $b->dao()->recalcBill($b);
                BillDocument::dao()->updateByBillNo($bill->GetNo());

                foreach ($connectingServices as $connectingService) {
                    $db->Query("update " . $connectingService['type'] . " set status='working' where id='" . $connectingService['id'] . "'");
                }

            } else {
                trigger_error2('Параметр "Цена включает НДС" счета отличается от лицевого счета');
            }

        } elseif ($obj == 'regular') {
            $clientAccount = ClientAccount::findOne($fixclient_data['id']);

            if ($clientAccount->price_include_vat == $bill->Get('price_include_vat')) {

                $periodicalDate = new DateTime(\app\classes\Utils::dateBeginOfMonth($bill->Get('bill_date')),
                    $clientAccount->timezone);
                $resourceDate = new DateTime(\app\classes\Utils::dateEndOfPreviousMonth($bill->Get('bill_date')),
                    $clientAccount->timezone);

                $periodicalTransactions =
                    ClientAccountBiller::create($clientAccount, $periodicalDate, $onlyConnecting = false,
                        $connecting = false, $periodical = true, $resource = false)
                        ->createTransactions()
                        ->getTransactions();

                $resourceTransactions =
                    ClientAccountBiller::create($clientAccount, $resourceDate, $onlyConnecting = false,
                        $connecting = false, $periodical = false, $resource = true)
                        ->createTransactions()
                        ->getTransactions();

                foreach ($periodicalTransactions as $transaction) {
                    $year = substr($transaction->billing_period, 0, 4);
                    $month = substr($transaction->billing_period, 5, 2);

                    $period_from = $year . '-' . $month . '-01';
                    $period_to = $year . '-' . $month . '-' . cal_days_in_month(CAL_GREGORIAN, $month, $year);

                    $bill->AddLine($transaction->name, $transaction->amount, $transaction->price, 'service',
                        $transaction->service_type, $transaction->service_id, $period_from, $period_to);
                }

                foreach ($resourceTransactions as $transaction) {
                    $year = substr($transaction->billing_period, 0, 4);
                    $month = substr($transaction->billing_period, 5, 2);

                    $period_from = $year . '-' . $month . '-01';
                    $period_to = $year . '-' . $month . '-' . cal_days_in_month(CAL_GREGORIAN, $month, $year);

                    $bill->AddLine($transaction->name, $transaction->amount, $transaction->price, 'service',
                        $transaction->service_type, $transaction->service_id, $period_from, $period_to, $transaction->cost_price);
                }

                $b = Bill::findOne(['bill_no' => $bill->GetNo()]);
                $b->dao()->recalcBill($b);

            } else {
                trigger_error2('Параметр "Цена включает НДС" счета отличается от лицевого счета');
            }

        } elseif ($obj == 'template') {
            $tbill = get_param_protected("tbill");
            foreach ($db->AllRecords('SELECT * FROM newbill_lines WHERE bill_no="' . $tbill . '" ORDER BY sort') as $r) {
                $bill->AddLine($r['item'], $r['amount'], $r['price'], $r['type']);
            }
        } elseif (isset($L[$bill->Get('currency')][$obj])) {
            $D = $L[$bill->Get('currency')][$obj];
            if (!is_array($D[0])) {
                $D = [$D];
            }
            foreach ($D as $d) {
                $bill->AddLine($d[0], $d[1], $d[2], $d[3]);
            }
        }
        $bill->Save();
        $client = $bill->Client('client');
        ClientAccount::dao()->updateBalance($bill->Client('id'), false);
        unset($bill);

        if (!$err && $design->ProcessEx('errors.tpl')) {
            header("Location: " . $design->LINK_START . "module=newaccounts&action=bill_view&err=" . $err . "&bill=" . $bill_no);
            exit();
        } else {
            return $this->newaccounts_bill_list($client);
        }
    }

    function newaccounts_bill_ext_file_get($fixclient)
    {
        $billNo = get_param_raw('bill_no');

        /** @var Bill $bill */
        $bill = null;

        if (
            !$billNo ||
            !($bill = Bill::find()->where(['bill_no' => $billNo])->one()) ||
            !($extFile = $bill->extFile) ||
            !($manager = $extFile->getMediaManager())
        ) {
            header('HTTP/1.1 404 Not Found');
            exit();
        }

        $manager->getContent($extFile, true);

    }

    function newaccounts_bill_mass($fixclient)
    {
        global $design, $db;
        set_time_limit(0);
        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $obj = get_param_raw('obj');
        if ($obj == 'create') {
            echo "Запущено выставление счетов<br/>";
            flush();

            $partSize = 500;
            $date = new DateTime();
            //$date->modify('+1 month');
            $totalCount = 0;
            $totalAmount = 0;
            $totalErrorsCount = 0;
            try {
                $count = $partSize;
                $offset = 0;
                while ($count >= $partSize) {
                    $clientAccounts =
                        ClientAccount::find()
                            ->andWhere(['NOT IN', 'status', ['closed', 'deny', 'tech_deny', 'trash', 'once']])
                            ->limit($partSize)
                            ->offset($offset)
                            ->orderBy('id')
                            ->all();

                    foreach ($clientAccounts as $clientAccount) {
                        $offset++;
                        echo "$offset. Лицевой счет: <a target='_blank' href='/client/view?id={$clientAccount->id}'>{$clientAccount->id}</a>";
                        flush();

                        try {

                            $bill =
                                \app\classes\bill\BillFactory::create($clientAccount, $date)
                                    ->process();

                            if ($bill) {
                                $totalCount++;
                                $totalAmount = $totalAmount + $bill->sum;
                                echo ", создан счет: <a target='_blank' href='/?module=newaccounts&action=bill_view&bill={$bill->bill_no}'>{$bill->bill_no}</a> на сумму {$bill->sum}<br/>\n";
                                flush();
                            } else {
                                echo "<br/>\n";
                                flush();
                            }

                        } catch (\Exception $e) {
                            echo "<b>ОШИБКА</b><br/>\n";
                            flush();
                            Yii::error($e);
                            $totalErrorsCount++;
                        }
                    }

                    $count = count($clientAccounts);
                }


            } catch (\Exception $e) {
                echo "<b>ОШИБКА Выставления счетов</b>\n";
                flush();
                Yii::error($e);
                exit;
            }

            echo "Закончено выставление счетов<br/>";
            echo "<b>Всего создано {$totalCount} счетов на сумму {$totalAmount}</b><br/>";
            if ($totalErrorsCount) {
                echo "<b>Всего {$totalErrorsCount} ошибок!</b><br/>";
            }
            exit;
        }
    }


    function newaccounts_bill_publish($fixclient)
    {
        $count = Bill::dao()->publishAllBills();

        Yii::$app->session->addFlash('success', 'Опубликовано ' . $count . ' счетов');

        return;
    }

    function newaccounts_bill_calculate_rewards($fixclient)
    {
        global $design, $db, $user, $fixclient_data;

        $dateFrom = get_param_raw("date_from");
        $dateTo = get_param_raw("date_to");

        if (empty($dateFrom) || empty($dateTo)) {
            Yii::$app->session->addFlash('error', 'Выберите период вознаграждения');
            header("Location: " . $design->LINK_START . "module=newaccounts&action=bill_list");
            exit();
        }

        $contractId = $fixclient_data->contract_id;
        $dateFrom = (new DateTime('01-' . $dateFrom));
        $dateTo = (new DateTime('01-' . $dateTo));

        try {
            CalculateReward::calcPartner($contractId, $dateFrom->format('Y-m-d'), $dateTo->format('Y-m-d'));
            CalculateReward::makeRewardBillByPartnerId($fixclient_data, $contractId, $dateFrom, $dateTo);
        } catch (\Exception $e) {
            Yii::$app->session->addFlash('error', $e->getMessage());
        }

        if ($design->ProcessEx('errors.tpl')) {
            header("Location: " . $design->LINK_START . "module=newaccounts&action=bill_list");
            exit();
        }
    }

    function newaccounts_bill_email($fixclient)
    {
        global $design, $db, $_GET;
        $this->do_include();
        $bills = get_param_protected("bill");
        if (!$bills) {
            return;
        }

        if (!is_array($bills)) {
            $bills = [$bills];
        }

        $bills = array_filter($bills, function($bill) {return strlen($bill) > 1;});

        $link = [];
        $document_link = [];
        $template = ['with_stamp' => '', 'without_stamp' => ''];
        foreach ($bills as $bill_no) {

            $docs = BillDocument::dao()->getByBillNo($bill_no);
            $bill = new \Bill($bill_no);
            $doc_date = $bill->getBill()['doc_date'];

            $is_doc_date = $doc_date !== '0000-00-00';

            $is_pdf = get_param_raw('is_pdf', 0);

            $D = [
                'Конверт' => ['envelope'],
                'Счет' => ['bill-1-RUB', 'bill-2-RUB'],
                'Счет-фактура' => ['invoice-1', 'invoice-2', 'invoice-3', 'invoice-4'],
                'Акт' => ['akt-1', 'akt-2', 'akt-3'],
                'Накладная' => ['lading'],
                'Приказ о назначении' => ["order"],
                'Уведомление о назначении' => ["notice"],
                'УПД' => ['upd-1', 'upd-2', 'upd-3'],
                'Уведомление о передачи прав' => ['notice_mcm_telekom'],
                'Соглашение о передачи прав' => ['sogl_mcm_telekom'],
                'Соглашение о передачи прав (МСМ=>МСН Ретайл)' => ['sogl_mcn_telekom'],
                'Соглашение о передачи прав (ООО МСН Телеком Ритейл => ООО МСН Телеком Сервис)' => ['sogl_mcn_service'],
                'Соглашение о передачи прав (ООО МСН Телеком => ООО МСН Телеком Сервис)' => ['sogl_mcn_telekom_to_service'],
                'Соглашение о передачи прав (ООО МСН Телеком Сервис => АбонСервис)' => ['sogl_mcn_service_to_abonservice'],
                'Кредит-нота' => ['credit_note'],
            ];

            $allowTypes = array_merge($D['Счет-фактура'], $D['Акт'], $D['УПД']);

            $isMultipleDocs = false;

            foreach ($D as $k => $rs) {
                $counter = 1;
                foreach ($rs as $r) {
                    if (get_param_protected($r)) {
                        if (in_array($r, $allowTypes) && !$this->isActionEnabled($r, $docs)) {
                            continue;
                        }
                        $isMultipleDocs = $counter > 1;

                        if (
                        in_array($r, ['notice_mcm_telekom', 'sogl_mcm_telekom', 'sogl_mcn_telekom', 'sogl_mcn_service', 'sogl_mcn_telekom_to_service', 'sogl_mcn_service_to_abonservice'])
                        ) {
                            $is_pdf = 1;
                        }

                        $R = [
                            'bill' => $bill_no,
                            'object' => $r,
                            'client' => $bill->Get('client_id'),
                            'is_pdf' => $is_pdf
                        ];
                        if (isset($_REQUEST['without_date'])) {
                            $R['without_date'] = 1;
                            $R['without_date_date'] = $_REQUEST['without_date_date'];
                        }
                        if (in_array($r, ["notice", "order"])) {
                            $link['with_stamp'][] = "https://stat.mcn.ru/client/pdf/" . $r . ".pdf";
                            $link['without_stamp'][] = "https://stat.mcn.ru/client/pdf/" . $r . ".pdf";
                        }

                        $link['with_stamp'][] = ['url' => Yii::$app->params['LK_PATH'] . 'docs/?bill=' . Encrypt::encodeArray($R + ['emailed' => 1]), 'description' => $k . ($isMultipleDocs ? ' №' . $counter : '') . ($is_doc_date ? ' от ' . $doc_date . ' ' : ' ')];
                        $link['without_stamp'][] = ['url' => Yii::$app->params['LK_PATH'] . 'docs/?bill=' . Encrypt::encodeArray($R + ['emailed' => 0]), 'description' => $k . ($isMultipleDocs ? ' №' . $counter : '') . ($is_doc_date ? ' от ' . $doc_date . ' ' : ' ')];
                    }
                    ++$counter;
                }
            }

            $documentReports = get_param_raw('document_reports', []);
            for ($i = 0, $s = sizeof($documentReports); $i < $s; $i++) {
                $link_params = [
                    'bill' => $bill_no,
                    'client' => $bill->Get('client_id'),
                    'doc_type' => $documentReports[$i],
                    'is_pdf' => $is_pdf,
                ];

                $description = '';
                if ($documentReports[$i] == 'bill') {
                    $description .= 'Счет' . ($isMultipleDocs ? ' №' . $counter : '') . ($is_doc_date ? ' от ' . $doc_date . ' ' : ' ');
                }
                $document_link['with_stamp'][] = ['url' => Yii::$app->params['LK_PATH'] . 'docs/?bill=' . Encrypt::encodeArray($link_params + ['emailed' => 1]), 'description' => $description];
                $document_link['without_stamp'][] = ['url' => Yii::$app->params['LK_PATH'] . 'docs/?bill=' . Encrypt::encodeArray($link_params + ['emailed' => 0]), 'description' => $description];

            }

            $design->ProcessEx();
        }

        foreach ($template as $tk => $tv) {
            if (isset($link[$tk])) {
                foreach ($link[$tk] as $item) {
                    $template[$tk] .= $item['description'] . '<a href="' . $item['url'] . '">' . $item['url'] . '</a><br>';
                }
            }
        }

        foreach ($template as $tk => $tv) {
            if (isset($document_link[$tk])) {
                foreach ($document_link[$tk] as $item) {
                    $template[$tk] .= $item['description'] . '<a href="' . $item['url'] . '">' . $item['url'] . '</a><br>';
                }
            }
        }
        $c = ClientAccount::findOne($bill->Client('id'));
        $contact = $c->officialContact;
        $this->_bill_email_ShowMessageForm('с печатью', $contact['email'], "Счет за телекоммуникационные услуги",
            $template['with_stamp']);
        $this->_bill_email_ShowMessageForm('без печати', $contact['email'], "Счет за телекоммуникационные услуги",
            $template['without_stamp']);
        echo 'Уважаемые господа!<br>Отправляем Вам следующие документы:<br>';
        $design->ProcessEx('errors.tpl');
        echo $template['with_stamp'] . '<hr>';
        echo $template['without_stamp'];
    }

    function _bill_email_ShowMessageForm($submit, $to, $subject, $msg)
    {
        global $design, $user;

        // Исключения для пользователей, у которые отправляет почту из стата не с ящика по умолчанию
        $_SPECIAL_USERS = [
            "istomina" => 191 /* help@mcn.ru */
        ];
        $_DEFAULT_MAIL_TRUNK_ID = 5; /* info@mcn.ru */


        $design->assign('subject', $subject);
        $design->assign('new_msg', $msg);
        if (is_array($to)) {
            $s = "";
            foreach ($to as $r) {
                if (is_array($r)) {
                    $r = $r['data'];
                }
                $s .= ($s ? ',' : '') . $r;
            }
        } else {
            $s = $to;
        }

        $userLogin = $user->Get('user');

        $design->assign('mail_trunk_id',
            isset($_SPECIAL_USERS[$userLogin]) ? $_SPECIAL_USERS[$userLogin] : $_DEFAULT_MAIL_TRUNK_ID);
        $design->assign('user', $userLogin);
        $design->assign('to', $s);
        $design->assign('submit', $submit);
        $design->ProcessEx('comcenter_msg.tpl');
    }


    function create_pdf_from_docs($fixclient, $bills = [])
    {
        global $user, $db, $design;

        if (count($bills) == 0) {
            return;
        }

        $fnames = [];
        $fbasename = '/tmp/' . mktime() . $user->_Data['id'];
        $i = 0;
        $is_invoice = false;
        $is_upd = false;

        foreach ($bills as $b) {
            $fname = $fbasename . (++$i) . '.html';
            if ($b['obj'] == 'envelope') {
                if (($r = $db->GetRow('SELECT * FROM clients WHERE (id="' . $b['bill_client'] . '") LIMIT 1'))) {
                    ClientCS::Fetch($r, null);
                    $content = $design->fetch('../store/acts/envelope.tpl');
                }
            } else {
                if (($pos = strpos($b['obj'], '&to_client=true'))) {
                    $to_client = true;
                    $obj = substr($b['obj'], 0, $pos);
                } else {
                    $to_client = false;
                    $obj = $b['obj'];
                }
                if (strpos($obj, 'invoice') !== false) {
                    $is_invoice = true;
                }
                if (strpos($obj, 'upd') !== false) {
                    $is_upd = true;
                }
                $content = $this->newaccounts_bill_print($fixclient, [
                    'object' => $obj,
                    'bill' => $b['bill_no'],
                    'only_html' => '1',
                    'to_client' => $to_client,
                    'is_pdf' => 1
                ]);
            }
            if (strlen($content)) {
                file_put_contents($fname, $content);
                $fnames[] = $fname;
            }
        }

        $options = ' --quiet -L 10 -R 10 -T 10 -B 10';
        if ($is_invoice || $is_upd) {
            $options .= ' --orientation Landscape ';
        }
        passthru("/usr/local/bin/wkhtmltopdf $options " . implode(' ', $fnames) . " $fbasename.pdf");
        $pdf = file_get_contents($fbasename . '.pdf');
        foreach ($fnames as $f) {
            unlink($f);
        }
        unlink($fbasename . '.pdf');

        header('Content-Type: application/pdf');
        ob_clean();
        flush();
        echo $pdf;
        exit;

    }

    function newaccounts_bill_mprint($fixclient)
    {
        global $design, $db, $user;

        $isBulkPrint = get_param_raw("isBulkPrint", 0);
        $isLandscape = get_param_raw("isLandscape", 0);
        $isPortrait = get_param_raw("isPortrait", 0);
        $is_pdf = get_param_raw("is_pdf", 0);
        $one_pdf = get_param_raw("one_pdf", 0);
        $invoiceId = get_param_raw("invoice_id", 0);
        $isDirectLink = (bool)get_param_raw("isDirectLink", 0);


        $this->do_include();
        $bills = get_param_raw("bill", []);
        if (!$bills) {
            return;
        }

        if (!is_array($bills)) {

            $billModel = Bill::findOne(['bill_no' => (string)$bills]);
            $invoice = null;
            $invoiceId && $invoice = Invoice::findOne(['id' => $invoiceId]);

            if ($billModel && $billModel->currency != Currency::RUB && ($invoice ? $invoice->date : $billModel->bill_date) >= Invoice::DATE_NO_RUSSIAN_ACCOUNTING) {
                return $this->_portingPrintNoRub($billModel, $is_pdf, $invoiceId, $isDirectLink);
            }

            $bills = [$bills];
        }

        $bills = array_filter($bills, function($bill) {return strlen($bill) > 1;});

        $R = [];
        $P = '';

        $isFromImport = get_param_raw("from", "") == "import";
        $isFromImport2 = get_param_raw("from", "") == "import2";
        $isToPrint = true;//get_param_raw("to_print", "") == "true";
        $stamp = get_param_raw("stamp", "");


        $documentReports = get_param_raw('document_reports', []);

        $L = ['envelope', 'bill-1-RUB', 'bill-2-RUB', 'lading', 'lading', 'gds', 'gds-2', 'gds-serial'];
        $L = array_merge($L, [
            'invoice-1', 'invoice-2', 'invoice-3', 'invoice-4', 'invoice-5',
            'akt-1', 'akt-2', 'akt-3',
            'upd-1', 'upd-2', 'upd-3'
        ]);
        $L = array_merge($L, ['akt-1', 'akt-2', 'akt-3', 'order', 'notice', 'upd-1', 'upd-2', 'upd-3']);
        $L = array_merge($L, ['nbn_deliv', 'nbn_modem', 'nbn_gds', 'notice_mcm_telekom', 'sogl_mcm_telekom', 'sogl_mcn_telekom', 'sogl_mcn_service', 'credit_note']);
        $L = array_merge($L, ['partner_reward', 'partner_reward_2', 'sogl_mcn_telekom_to_service', 'sogl_mcn_service_to_abonservice', 'sogl_mcn_abonserv_to_telekom', 'invoice2']);

        $landscapeActions = ['invoice-1', 'invoice-2', 'invoice-3', 'invoice-4', 'upd-1', 'upd-2', 'upd-3'];

        if ($isFromImport2) {
            return $this->importOnDocType($bills, $is_pdf);
        }

        // 'tpl1' => 3
        $upd2storage=[];
        foreach(['upd2-1', 'upd2-2', 'upd2-3'] as $upd2Name) {
            $v = get_param_raw($upd2Name);
            $v && $upd2storage[$upd2Name] = $upd2Name;
        }
        if ($upd2storage) {
            return $this->printmTpl3($upd2storage, $bills, $is_pdf);
        }


        //$L = array("invoice-1");

        //$bills = array("201204-0465");

        $idxs = [];

        foreach ($bills as $bill_no) {
            if ($isBulkPrint) {
                $docs = BillDocument::dao()->getByBillNo($bill_no);
            }
            $bill = new \Bill($bill_no);

            $bb = $bill->GetBill();


            // установка/удаление даты документа
            if (isset($_REQUEST['without_date']) && ($bill->is1CBill() || $bill->isOneTimeService())) {
                $wDate = get_param_raw("without_date_date", "");

                $toDelDate = false;

                if ($wDate) {
                    [$d, $m, $y] = explode(".", $wDate . "...");

                    $utDate = @mktime(0, 0, 0, $m, $d, $y);

                    // дата корректная
                    if ($utDate) {
                        if ($bb["doc_ts"] != $utDate) {
                            $bill->SetDocDate($utDate);
                        }
                    } else {
                        $toDelDate = true;
                    }
                } else {
                    $toDelDate = true;
                }

                // удалить дату
                if ($toDelDate) {
                    $bill->SetDocDate(0);
                }
            }

            if ($isFromImport) {
                $isSF = get_param_raw("invoice-1", "") == "1";
                $isUPD = get_param_raw("upd-1", "") == "1";
                $isAktImport = get_param_raw("akt-1", "") == "1";

                $c = ClientAccount::findOne($bill->client_id);
                if (!in_array('payment', $c->getOption(['mail_delivery_variant']))) {
                    continue;
                }

                $d = $this->get_bill_docs($bill);

                $isAkt1 = $d[0][1];
                $isAkt2 = $d[0][2];

            }
            //$design->assign('bill',$bb);

            $h = [];
            foreach ($L as $r) {

                $reCode = false;

                if ($r == "invoice-2" && $isFromImport && $isAkt2 && $isSF) {
                    $reCode = $r;
                }

                if ($r == "akt-2" && $isFromImport && $isAkt2 && !$isSF && !$isUPD && $isAktImport) {
                    $reCode = $r;
                }

                $isDeny = false;
                if ($r == "akt-1" && $isFromImport && !$isAkt1 && !$isSF) {
                    $isDeny = true;
                }

                if ($r == "invoice-1" && $isFromImport && !$isAkt1 && $isSF) {
                    //$isDeny = true;
                }

                if ($r == "upd-1" && $isFromImport && !$isAkt1) {
                    $isDeny = true;
                }

                if ($r == "upd-2" && $isFromImport && !$isAkt2) {
                    $isDeny = true;
                }

                if ((($currentAction = get_param_protected($r)) || $reCode) && !$isDeny) {
                    if ($currentAction && (
                            ($isBulkPrint && !$this->isActionEnabled($r, $docs)) ||
                            ($isPortrait && in_array($r, $landscapeActions)) ||
                            ($isLandscape && !in_array($r, $landscapeActions))
                        )) {
                        continue;
                    }

                    if ($reCode) {
                        $r = $reCode;
                    }

                    if (isset($h[$r])) {
                        $idxs[$bill_no . "==" . $r . "-2"] = count($R);
                        $r .= "&to_client=true";
                    } else {
                        $idxs[$bill_no . "==" . $r] = count($R);
                        $h[$r] = count($R);
                    }
                    /*
                    if($withoutDate){
                        $r.= '&without_date=1&without_date_date='.$withoutDate;
                    }
                    */

                    if ($stamp) {
                        $r .= "&stamp=" . $stamp;
                    }

                    if ($isFromImport) {
                        $r .= "&from=import";
                    }

                    if ($isFromImport || $isToPrint) {
                        $r .= "&to_print=true";
                    }

                    $ll = [
                        "bill_no" => $bill_no,
                        "isBill" => strpos($r, 'bill') === 0,
                        "obj" => $r . ($invoiceId ? '&invoice_id=' . $invoiceId : ''),
                        "bill_client" => $bill->Get("client_id"),
                        "g" => get_param_protected($r),
                        "r" => $reCode
                    ];

                    $R[] = $ll;
                    $P .= ($P ? ',' : '') . '1';
                }
            }

            if (sizeof($documentReports) && !($isBulkPrint && $isLandscape)) {
                $idxs[$bill_no . '==bill'] = count($R);
                foreach ($documentReports as $documentReport) {
                    $R[] = [
                        'bill_no' => $bill_no,
                        'doc_type' => $documentReport,
                    ];
                    $P .= ($P ? ',' : '') . '1';
                }
            }

            unset($bill);
        }

        //printdbg($R);


        $_R = $R;
        $R = [];

        $set = [];
        foreach ($idxs as $key => $idx) {
            if (isset($set[$key])) {
                continue;
            }
            $R[] = $_R[$idx];

            if (isset($idxs[$key . "-2"])) {
                $R[] = $_R[$idxs[$key . "-2"]];
                $set[$key . "-2"] = 1;
            }
        }
        if (count($R) == 1 && isset($R[0]["obj"]) && $R[0]["obj"] == "envelope") {
            $R[0]["param"] = "alone=true";
        }
        if ($one_pdf == '1') {
            $this->create_pdf_from_docs($fixclient, $R);
        }

        $design->assign('is_pdf', $is_pdf);
        $design->assign('rows', $P);
        $design->assign('objects', $R);
        $design->ProcessEx('newaccounts/print_bill_frames.tpl');
    }

    function isActionEnabled($r, $enabledActions)
    {
        switch ($r) {
            case 'akt-1':
                return $enabledActions['a1'];
            case 'akt-2':
                return $enabledActions['a2'];
            case 'akt-3':
                return $enabledActions['a3'];
            case 'invoice-1':
                return $enabledActions['i1'];
            case 'invoice-2':
                return $enabledActions['i2'];
            case 'invoice-3':
                return $enabledActions['i3'];
            case 'upd-1':
                return $enabledActions['ia1'];
            case 'upd-2':
                return $enabledActions['ia2'];
            default:
                return false;
        }
    }


    /**
     * Новый, экспериментальный, импорт для документов
     *
     * @param array $bills
     * @param bool $isPDF
     */
    function importOnDocType($bills = [], $isPDF = false)
    {
        global $design;

        $isSF = get_param_raw("invoice-1", "") == "1";
        $isUPD = get_param_raw("upd-1", "") == "1";
        $isAkt = get_param_raw("akt-1", "") == "1";
        $isBill = get_param_raw("bill-2-RUB", "") == "1";
        $isEnvelope = get_param_raw("envelope", "") == "1";

        $P = "";
        $R = [];

        foreach ($bills as $billNo) {
            $bill = Bill::findOne(['bill_no' => $billNo]);

            if (!$bill) {
                continue;
            }

            $docs = BillDocument::dao()->getByBillNo($billNo);
            if (!$docs) {
                continue;
            }

            $printObjects = [];
            $printParams = [];

            $isEnvelope && ($printObjects[] = "envelope") && ($printParams[] = []);
            $isBill && ($printObjects[] = "bill-2-RUB") && ($printParams[] = []);
            $isSF && $docs['i1'] && ($printObjects[] = "invoice-1") && ($printParams[] = []);
            $isSF && $docs['i2'] && ($printObjects[] = "invoice-2") && ($printParams[] = []);
            $isAkt && $docs['a1'] && ($printObjects[] = "akt-1") && ($printParams[] = []);
            $isAkt && $docs['a1'] && ($printObjects[] = "akt-1") && ($printParams[] = ['to_client' => "true"]);
            $isAkt && $docs['a2'] && ($printObjects[] = "akt-2") && ($printParams[] = []);
            $isAkt && $docs['a2'] && ($printObjects[] = "akt-2") && ($printParams[] = ['to_client' => "true"]);
            $isUPD && $docs['ia1'] && ($printObjects[] = "upd-1") && ($printParams[] = []);
            $isUPD && $docs['ia1'] && ($printObjects[] = "upd-1") && ($printParams[] = ['to_client' => "true"]);
            $isUPD && $docs['ia2'] && ($printObjects[] = "upd-2") && ($printParams[] = []);
            $isUPD && $docs['ia2'] && ($printObjects[] = "upd-2") && ($printParams[] = ['to_client' => "true"]);

            foreach ($printObjects as $idx => $obj) {

                $R[] = [
                    "bill_no" => $billNo,
                    "isBill" => strpos($obj, 'bill') === 0,
                    "obj" => $obj . "&" . http_build_query([
                                "to_print" => "true",
                            ] + $printParams[$idx]
                        ),
                    "bill_client" => $bill->client_id,
                ];

                $P .= ($P ? ',' : '') . '1';
            }
        }

        $design->assign('is_pdf', $isPDF);
        $design->assign('rows', $P);
        $design->assign('objects', $R);
        $design->ProcessEx('newaccounts/print_bill_frames.tpl');

    }


    /**
     * Новый, экспериментальный, импорт для документов
     *
     * @param array $bills
     * @param bool $isPDF
     */
    function printmTpl3($printDocs = [], $bills = [], $isPDF = false)
    {
        global $design;

        /*
         *         $data = [
            'tpl1' => 3,
            'account_id' => $form->account_id,
            'document_number' => $form->document_number,
            'template_type_id' => $templateType->id,
            'country_code' => $form->country_code,
            'include_signature_stamp' => (bool) filter_var($form->include_signature_stamp, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ];
         */
        $P = "";
        $R = [];

        foreach ($bills as $billNo) {
            /** @var Bill $bill */
            $bill = Bill::findOne(['bill_no' => $billNo]);

            if (!$bill) {
                continue;
            }

            $docs = BillDocument::dao()->getByBillNo($billNo);
            if (!$docs) {
                continue;
            }

            $printObjects = [];

            foreach ($printDocs as $printDocId) {
                $templateTypeId = \app\models\document\PaymentTemplateType::TYPE_ID_UPD;

                if ($printDocId == 'upd2-1') {
                    $invoiceTypeId = Invoice::TYPE_1;
                } elseif ($printDocId == 'upd2-2') {
                    $invoiceTypeId = Invoice::TYPE_2;
                } elseif ($printDocId == 'upd2-3') {
                    $invoiceTypeId = Invoice::TYPE_GOOD;
                } else {
                    continue;
                }

                /** @var Invoice $invoiceObject */
                $invoiceObject = Invoice::find()->where(['bill_no' => $bill->bill_no, 'type_id' => $invoiceTypeId])->orderBy(['id' => SORT_DESC])->one();

                if (!$invoiceObject) {
                    continue;
                }

                $printObject = [
                    'tpl1' => 3,
                    'account_id' => $bill->client_id,
                    'document_number' => $invoiceObject->number,
                    'template_type_id' => $templateTypeId,
                    'country_code' => $bill->clientAccount->getUuCountryId(),
                    'include_signature_stamp' => false,
                ];

                $printObjects[] = $printObject;
//                $printObjects['include_signature_stamp'] = true;
//                $printObjects[] = $printObject;
            }

            foreach ($printObjects as $idx => $obj) {
                $R[] = [
                    'isLink' => true,
                    'link' => \Yii::$app->params['SITE_URL'] . 'bill.php?bill=' . Encrypt::encodeArray($obj)
//                    'link' => \Yii::$app->params['SITE_URL'] . 'bill.php?' . http_build_query($obj)
                ];

                $P .= ($P ? ',' : '') . '1';
            }
        }

        $design->assign('is_pdf', $isPDF);
        $design->assign('rows', $P);
        $design->assign('objects', $R);
        $design->ProcessEx('newaccounts/print_bill_frames.tpl');

    }

    private function _portingPrintNoRub(Bill $bill, $isPdf, $invoiceId, $isDirectLink)
    {
        global $design;

        $allowedDocs = [
            'invoice-1' => 1,
            'invoice-2' => 2,
            'invoice-3' => 3,
        ];

        $invoice = null;
        if ($invoiceId) {
            $invoice = Invoice::findOne(['id' => $invoiceId]);

            \app\classes\Assert::isObject($invoice);

            $allowedDocs = [
                'invoice2' => $invoice->type_id,
            ];
        }

        $objects = [];
        foreach ($allowedDocs as $doc => $docId) {
            if (get_param_raw($doc)) {
                $objects[] = [
                    'isLink' => true,
                    'link' => \yii\helpers\Url::to(['/uu/invoice/get',
                        'billNo' => $bill->bill_no,
                        'typeId' => $docId,
                        'langCode' => $bill->clientAccount->clientContractModel->clientContragent->lang_code,
                    ]
                        + ($isPdf || $invoice ? ['renderMode' => 'pdf', 'isShow' => true] : [])
                        + ($invoice ? ['invoiceId' => $invoiceId] : [])
                    )
                ];
            }
        }

        if ($isDirectLink) {
            $linkData = reset($objects);
            $link = $linkData['link'];

            header('Location: ' . $link);
            exit();
        }

        /*
         * [bill_no] => 201901-012025
         * [isBill] =>
         * [obj] => akt-2&to_client=true&to_print=true
         * [bill_client] => 51486
         * [g] =>
         * [r] =>
         */
/*
        $objects[] = [
            'bill_no' => '201901-012025',
            'isBill' => '',
            'obj' => 'akt-2&to_client=true&to_print=true',
            'bill_client' => 51486,
            'g' => '',
            'r' => ''
        ];

        $objects[] = [
            'bill_no' => '201901-012025',
            'isBill' => '',
            'obj' => 'akt-2&to_client=true',
            'bill_client' => 51486,
            'g' => '',
            'r' => ''
        ];
*/

//        $invoice = (new InvoiceLight($bill->clientAccount))
//            ->setBill($bill);

        $design->assign('is_pdf', $isPdf);
        $design->assign('rows', implode(',', array_fill(0, count($objects), '1')));
        $design->assign('objects', $objects);
        $design->ProcessEx('newaccounts/print_bill_frames.tpl');


    }

    function newaccounts_bill_clear($fixclient)
    {
        global $design, $db;
        $bill_no = get_param_protected("bill");
        if (!$bill_no) {
            return;
        }
        $bill = new \Bill($bill_no);
        if ($bill->IsClosed()) {
            header("Location: ./?module=newaccounts&action=bill_view&bill=" . $bill_no);
            exit();
        }
        if (!$bill->CheckForAdmin()) {
            return;
        }

        foreach (\app\models\BillLine::find()->where(['bill_no' => $bill_no])->all() as $line) {
            $line->delete();
        }

        $bill->Save(0, 0);

        $client = $bill->Client();
        ClientAccount::dao()->updateBalance($client['id'], false);

        if ($design->ProcessEx('errors.tpl')) {
            header("Location: " . $design->LINK_START . "module=newaccounts&action=bill_view&bill=" . $bill_no);
            exit();
        }
    }

    function newaccounts_line_delete($fixclient)
    {
        global $design, $db;
        $bill_no = get_param_protected("bill");
        if (!$bill_no) {
            return;
        }
        $bill = new \Bill($bill_no);
        if (!$bill->CheckForAdmin()) {
            return;
        }
        $sort = get_param_integer("sort");
        if (!$sort) {
            return;
        }
        $bill->RemoveLine($sort);
        if ($design->ProcessEx('errors.tpl')) {
            header("Location: " . $design->LINK_START . "module=newaccounts&action=bill_view&bill=" . $bill_no);
            exit();
        }
    }

    function newaccounts_bill_delete($fixclient)
    {
        global $design, $db;
        $bill_no = get_param_protected("bill");
        if (!$bill_no) {
            return;
        }

        /** @var Bill $bill */
        $bill = Bill::find()->andWhere(['bill_no' => $bill_no])->one();
        $billConnection = BillOutcomeCorrection::find()->where(['bill_no' => $bill_no])->one();

        if ($bill->isClosed()) {
            header("Location: ./?module=newaccounts&action=bill_view&bill=" . $bill_no);
            exit();
        }

        $clientAccountId = $bill->client_id;
        try {
            if (!$bill->delete()) {
                throw new ModelValidationException($bill);
            }

            if($billConnection){
                if (!$billConnection->delete()) {
                    throw new ModelValidationException($billConnection);
                }
            }
            ClientAccount::dao()->updateBalance($clientAccountId, false);
        } catch (Exception $e) {
            \Yii::$app->session->addFlash('error', $e->getMessage());
            header("Location: " . $design->LINK_START . "module=newaccounts&action=bill_view&bill=".urlencode($bill_no));
            exit;
        }

        if ($design->ProcessEx('errors.tpl')) {
            header("Location: " . $design->LINK_START . "module=newaccounts&action=bill_list");
            exit();
        }
    }

    //эта функция готовит счёт к печати. ФОРМИРОВАНИЕ СЧЁТА
    function newaccounts_bill_print($fixclient, $params = [])
    {
        global $design, $db, $user;
        $this->do_include();

        $object = (isset($params['object'])) ? $params['object'] : get_param_protected('object');

        $mode = get_param_protected('mode', 'html');

        $is_pdf = (isset($params['is_pdf'])) ? $params['is_pdf'] : get_param_raw('is_pdf', 0);
        $is_word = get_param_raw('is_word', false);

        $design->assign("is_pdf", $is_pdf);

        $isToPrint = (isset($params['to_print'])) ? (bool)$params['to_print'] : get_param_raw('to_print',
                'false') == 'true';
        $design->assign("to_print", $isToPrint);

        $only_html = (isset($params['only_html'])) ? $params['only_html'] : get_param_raw('only_html', 0);

        self::$object = $object;
        if ($object) {
            [$obj, $source, $curr] = explode('-', $object . '---');
        } else {
            $obj = get_param_protected("obj");
            $source = get_param_integer('source', 1);
            $curr = get_param_raw('curr', 'RUB');
        }

        if ($obj == 'act') {
            $obj = 'akt';
        }

        $invoiceId = false;
        if ($obj == 'invoice2') {
            $obj = 'invoice';
            $invoiceId = isset($params['invoice_id']) && $params['invoice_id'] ? $params['invoice_id'] : get_param_integer('invoice_id');

            $source = (int)Invoice::find()->where(['id' => $invoiceId])->select(['type_id'])->scalar();
        }


        $bill_no = (isset($params['bill'])) ? $params['bill'] : get_param_protected('bill');
        if (!$bill_no && $obj !== 'receipt') {
            return false;
        }

        $billModel = app\models\Bill::findOne(['bill_no' => $bill_no]);

        if ($billModel->isCorrectionType()) {
            throw new LogicException('Нет документов у корректировки');
        }

        switch ($obj) {
            case 'receipt': {
                $this->_print_receipt();
                break;
            }
            case 'credit_note':
            case 'notice_mcm_telekom':
            case 'sogl_mcm_telekom':
            case 'sogl_mcn_telekom':
            case 'sogl_mcn_service':
            case 'sogl_mcn_service_to_abonservice':
            case 'sogl_mcn_abonserv_to_telekom':
            case 'sogl_mcn_telekom_to_service': {

                if ($billModel) {
                    $report = DocumentReportFactory::me()->getReport($billModel, $obj, get_param_raw('emailed', false));
                    echo $is_pdf ? $report->renderAsPDF() : $report->render();
                    exit;
                }
                break;
            }
            case 'partner_reward':
                // Дата за прошлый месяц для генерации отчета
                $filterDate = (new \DateTimeImmutable($billModel->bill_date))
                    ->modify('first day of previous month')
                    ->format('Y-m');
                // Создание фильтра и получение результата
                $filterModel = (new PartnerRewardsFilter(true))->load();
                // Вызов данного события обусловлен пройденной проверкой, в которой клиент счета имеет партнерское вознаграждение
                $filterModel->partner_contract_id = $billModel->clientAccount->contract_id;
                $filterModel->payment_date_before = $filterDate;
                $filterModel->payment_date_after = $filterDate;
                // Генерация контента по шаблону и создание PDF-файла
                $html = Yii::$app->view->render('//stats/partner-rewards/template/partner_rewards_report', [
                    'filterModel' => $filterModel,
                ]);
                include 'mpdf.php';
                $mpdf = new mPDF('', 'A4-L', 9);
                $mpdf->PDFA = true;
                $mpdf->PDFAauto = true;
                $mpdf->WriteHTML($html);
                header('Content-Type: application/pdf');
                echo $mpdf->Output();
                exit;
            case 'partner_reward_2':
                // Дата за прошлый месяц для генерации отчета
                $filterDate = (new \DateTimeImmutable($billModel->bill_date))
                    ->modify('first day of previous month')
                    ->format('Y-m');
                // Создание фильтра и получение результата
                $filterModel = (new PartnerRewardsNewFilter(true))->load();
                // Вызов данного события обусловлен пройденной проверкой, в которой клиент счета имеет партнерское вознаграждение
                $filterModel->partner_contract_id = $billModel->clientAccount->contract_id;
                $filterModel->payment_date_before = $filterDate;
                $filterModel->payment_date_after = $filterDate;
                // Генерация контента по шаблону и создание PDF-файла
                $html = Yii::$app->view->render('//stats/partner-rewards-new/template/partner_rewards_report', [
                    'filterModel' => $filterModel,
                ]);
                include 'mpdf.php';
                $mpdf = new mPDF('', 'A4-L', 9);
                $mpdf->PDFA = true;
                $mpdf->PDFAauto = true;
                $mpdf->WriteHTML($html);
                header('Content-Type: application/pdf');
                echo $mpdf->Output();
                exit;
        }

        $to_client = (isset($params['to_client'])) ? $params['to_client'] : get_param_raw("to_client", "false");
        $design->assign("to_client", $to_client);


        $bill = new \Bill($bill_no);

        $design->assign('without_date_date', $bill->getShipmentDate());
        $design->assign("stamp", $this->get_import1_name($bill, get_param_raw("stamp", "false")));


        if (get_param_raw("emailed", "0") != "0") {
            $design->assign("emailed", get_param_raw("emailed", "0"));
        }


        if (in_array($obj, ['nbn_deliv', 'nbn_modem', 'nbn_gds'])) {
            $this->do_print_prepare($bill, 'bill', 1, 'RUB');
            $design->assign('cli',
                $cli = $db->GetRow("SELECT * FROM newbills_add_info WHERE bill_no='" . $bill_no . "'"));
            if (preg_match("/([0-9]{2})\.([0-9]{2})\.([0-9]{4})/i", $cli["passp_birthday"], $out)) {
                $cli["passp_birthday"] = $out[1] . "-" . $out[2] . "-" . $out[3];
            }

            $lastDoer = $db->GetValue("SELECT name FROM tt_doers d , courier c WHERE stage_id IN (SELECT stage_id FROM tt_stages WHERE trouble_id = (SELECT id FROM `tt_troubles` WHERE bill_no ='" . $cli["bill_no"] . "')) AND d.doer_id = c.id ORDER BY d.id DESC");

            [$f, $i, $o] = explode(" ", $lastDoer . "   ");
            if (strlen($i) > 2) {
                $i = $i[0] . ".";
            }
            if ($o && strlen($o) > 2) {
                $o = $i[0] . ".";
            }

            $design->assign("cli_doer", $lastDoer ? $f . " " . $i . " " . $o : "");

            $design->assign('cli_fio', explode(' ', $cli['fio']));
            $design->assign('cli_bd', explode('-', $cli['passp_birthday']));
            $design->assign("serial", $this->do_print_serials($bill_no));
            $cli_passp_when_given = explode('-', $cli['passp_when_given']);
            if (count($cli_passp_when_given) == 1) {
                $cli_passp_when_given = array_reverse(explode('.', $cli_passp_when_given[0]));
            }
            $design->assign('cli_passp_when_given', $cli_passp_when_given);
            $design->assign('cli_acc_no', explode(' ', $cli['acc_no']));


            $design->ProcessEx('newaccounts/' . $obj . '.html');
            return true;
        }

        if (!in_array($obj,
            ['invoice', 'akt', 'upd', 'lading', 'gds', 'order', 'notice', 'new_director_info', 'envelope'])
        ) {
            $obj = 'bill';
        }

        if ($obj != 'bill') {
            $curr = 'RUB';
        }

        if (in_array($obj, ["order", "notice"])) {
            $t = ($obj == "order" ?
                "Приказ (Телеком) (Пыцкая)" :
                ($obj == "notice" ?
                    "Уведомление (Телеком)" : ""));

            if ($user->Get('id')) {
                $db->QueryInsert(
                    "log_newbills",
                    [
                        'bill_no' => $bill_no,
                        'ts' => ['NOW()'],
                        'user_id' => $user->Get('id'),
                        'comment' => 'Печать ' . $t
                    ]
                );
            }

        }

        if ($obj == "new_director_info") {
            $this->docs_echoFile(STORE_PATH . "new_director_info.pdf", "Смена директора.pdf");
            exit();
        }

        if ($obj == "order") {
            $this->docs_echoFile(STORE_PATH . "order2.pdf", "Смена директора МСН Телеком.pdf");
            exit();
        }

        if ($this->do_print_prepare($bill, $obj, $source, $curr, true, false, $invoiceId) || in_array($obj, ["order", "notice"])) {

            $design->assign("bill_no_qr",
                ($bill->GetTs() >= strtotime("2013-05-01") ? BillQRCode::getNo($bill->GetNo()) : false));
            $design->assign("source", $source);

            if ($source == 3 && $obj == 'akt') {
                if ($mode == 'html') {
                    $design->ProcessEx('newaccounts/print_akt_num3.tpl');
                }
            } else {
                if (in_array($obj, ['invoice', 'upd'])) {

                    $design->assign("client_contract",
                        BillContract::getString($billModel->clientAccount->contract_id, $bill->getTs()));

                    if(\app\classes\Utils::isProd()) {
                        $id = $db->QueryInsert(
                            "log_newbills",
                            [
                                'bill_no' => $bill_no,
                                'ts' => ['NOW()'],
                                'user_id' => $user->Get('id'),
                                'comment' => 'Печать с/ф &#8470;' . $source
                            ]
                        );
                    }

                    if ($obj == "upd") {
                        $design->assign("print_upd", printUPD::getInfo(count($design->_tpl_vars["bill_lines"])));
                    }

                } elseif ($obj == 'gds') {

                    $serials = [];
                    $onlimeOrder = false;
                    foreach (Serial::find('all', [
                            'conditions' => [
                                'bill_no' => $bill->GetNo()
                            ],
                            'order' => 'code_1c'
                        ]
                    ) as $s) {
                        $serials[$s->code_1c][] = $s->serial;
                    }

                    // для onlime'а показываются номера купонов, если таковые есть
                    if ($bill->Get("client_id") == "18042") {
                        $oo = OnlimeOrder::find_by_bill_no($bill->GetNo());
                        if ($oo) {
                            if ($oo->coupon) {
                                $onlimeOrder = $oo;
                            }
                        }
                    }

                    $design->assign("onlime_order", $onlimeOrder);


                    include_once INCLUDE_PATH . '1c_integration.php';
                    $bm = new \_1c\billMaker($db);
                    $f = null;
                    $b = $bm->getOrder($bill_no, $fault);

                    $_1c_lines = [];
                    if ($b) {
                        foreach ($b['list'] as $item) {
                            $_1c_lines[$item['strCode']] = $item;
                        }
                    }
                    $design->assign("serials", $serials);
                    $design->assign('1c_lines', $_1c_lines);
                }

                if ($only_html == '1') {
                    return $design->fetch('newaccounts/print_' . $obj . '.tpl');
                }

                if ($is_word) {
                    $result = (new \app\classes\Html2Mhtml)
                        ->addContents(
                            'index.html',
                            $design->fetch('newaccounts/print_' . $obj . '.tpl')
                        )
                        ->addImages(function ($image_src) {
                            $file_path = '';
                            $file_name = '';

                            if (preg_match('#\/[a-z]+(?![\.a-z]+)\?.+?#i', $image_src, $m)) {
                                $file_name = 'host_img_' . mt_rand(0, 50);
                                $file_path = Yii::$app->request->hostInfo . preg_replace('#^\.+#', '', $image_src);
                            }

                            return [$file_name, $file_path];
                        })
                        ->addMediaFiles(function ($src) {
                            $file_name = 'host_media_' . mt_rand(0, 50);
                            $file_path = Yii::$app->request->hostInfo . preg_replace('#^\.+#', '', $src);

                            return [$file_name, $file_path];
                        })
                        ->getFile();

                    Yii::$app->response->sendContentAsFile($result, time() . Yii::$app->user->id . '.doc');
                    Yii::$app->end();
                    exit;
                }

                if ($is_pdf) {
                    /*wkhtmltopdf*/
                    $options = ' --quiet -L 10 -R 10 -T 10 -B 10';
                    switch ($obj) {
                        case 'upd':
                            $options .= ' --orientation Landscape ';
                            break;
                        case 'invoice':
                            $options .= ' --orientation Landscape ';
                            break;
                    }
                    $content = $design->fetch('newaccounts/print_' . $obj . '.tpl');
                    $tmp_dir = sys_get_temp_dir();
                    $file_name = tempnam($tmp_dir, "html_to_pdf");
                    unlink($file_name);
                    $file_html = $file_name . '.html';
                    $file_pdf = $file_name . '.pdf';

                    file_put_contents($file_name . '.html', $content);

                    passthru("/usr/local/bin/wkhtmltopdf $options $file_html $file_pdf");
                    $pdf = file_get_contents($file_pdf);
                    unlink($file_html);
                    unlink($file_pdf);

                    header('Content-Type: application/pdf');
                    ob_clean();
                    flush();
                    echo $pdf;
                    exit;

                } else {
                    if ($mode == 'html') {
                        $design->ProcessEx('newaccounts/print_' . $obj . '.tpl');
                    } elseif ($mode == 'xml') {
                        $design->ProcessEx('newaccounts/print_' . $obj . '.xml.tpl');
                    } elseif ($mode == 'pdf') {
                        include(INCLUDE_PATH . 'fpdf/model/' . $obj . '.php');
                    }
                }
            }
        } else {
            if ($only_html == '1') {
                return '';
            }
            trigger_error2('Документ не готов');
        }
        $design->ProcessEx('errors.tpl');
    }

    private function _print_receipt()
    {
        global $design;
        $clientId = get_param_raw('client', 0);
        $sum = (float)get_param_raw('sum', 0);

        if ($clientId && $sum) {
            $clientAccount = ClientAccount::findOne($clientId);
            $organization = $clientAccount->organization;

            [$sum, $sum_without_tax, $sum_tax] = $clientAccount->convertSum($sum, null);

            [$rub, $kop] = explode('.', sprintf('%.2f', $sum));
            [$ndsRub, $ndsKop] = explode('.', sprintf('%.2f', $sum_tax));

            $summary = [
                'rub' => $rub,
                'kop' => $kop,
                'nds' => [
                    'rub' => $ndsRub,
                    'kop' => $ndsKop
                ]
            ];

            $design->assign('sum', $summary);
            $design->assign('client', $clientAccount);
            $design->assign('organization', $organization);
            $design->assign('organization_settlement_account', [
                'bank_account' => $organization->settlementAccount->bank_account,
                'bank_name' => $organization->settlementAccount->bank_name,
                'bank_correspondent_account' => $organization->settlementAccount->bank_correspondent_account,
                'bank_bik' => $organization->settlementAccount->bank_bik,
            ]);
            $design->assign('qrdata', Encrypt::encodeArray(['accountId' => $clientId, 'sum' => $sum]));

            echo $design->fetch('newaccounts/print_receipt.tpl');
        }

        exit;
    }


    function get_import1_name($bill, $flag)
    {
        if ($flag == "import1") {
            $ts = $bill->GetTs();

            //return "solop";

            if ($ts >= strtotime("2010-10-01")) {
                return "uskova";
            } elseif ($ts >= strtotime("2010-04-01")) {
                return "zam_solop_tp";
            } elseif ($ts >= strtotime("2009-07-01")) {
                return "solop_tp";

            } elseif ($ts >= strtotime("2008-11-01")) {
                return "solop_nm";
            } else {
                return "false";
            }
        }

        return false;
    }

    function do_print_serials($billNo)
    {
        global $db;
        $s = ["decoder" => [], "w300" => [], "other" => [], "cii" => [], "fonera" => []];
        foreach ($db->AllRecords("SELECT num_id, item, serial FROM newbill_lines l, g_serials s, g_goods g  WHERE l.bill_no = '" . $billNo . "' AND l.bill_no = s.bill_no AND l.code_1c = s.code_1c AND g.id = l.item_id") as $l) {
            $idx = "other";

            if (preg_match("/w300/i", $l["item"])) {
                $idx = "w300";
            }
            if (preg_match("/декодер/i", $l["item"])) {
                $idx = "decoder";
            }
            if ($l["num_id"] == 11243) {
                $idx = "cii";
            }
            if ($l["num_id"] == 11241) {
                $idx = "fonera";
            }

            $s[$idx][] = $l["serial"];
        }
        unset($l);

        foreach ($s as &$l) {
            $l = implode(", ", $l);
        }

        return $s;
    }

    public static function do_print_prepare_filter(
        $obj,
        $source,
        &$billLines,
        $period_date,
        $inv3Full = true,
        $isViewOnly = false,
        $origObj = false
    )
    {
        $M = [];

        if ($origObj === false) {
            $origObj = $obj;
        }

        if ($obj == BillDocument::TYPE_GDS) {
            $M = [
                'all4net' => 0,
                'service' => 0,
                'zalog' => 0,
                'zadatok' => 0,
                'good' => 1,
                '_' => 0
            ];
        } else {
            if ($obj == BillDocument::TYPE_BILL) {
                $M['all4net'] = 1;
                $M['service'] = 1;
                $M['zalog'] = 1;
                $M['zadatok'] = ($source == BillDocument::ID_RESOURCE ? 1 : 0);
                $M['good'] = 1;
                $M['_'] = 0;
            } elseif ($obj == BillDocument::TYPE_LADING) {
                $M['all4net'] = 1;
                $M['service'] = 0;
                $M['zalog'] = 0;
                $M['zadatok'] = 0;
                $M['good'] = 1;
                $M['_'] = 0;
            } elseif ($obj == BillDocument::TYPE_AKT) {
                if ($source == BillDocument::ID_GOODS) {
                    $M = [
                        'all4net' => 0,
                        'service' => 0,
                        'zalog' => 1,
                        'zadatok' => 0,
                        'good' => 0,
                        '_' => 0
                    ];
                } elseif (in_array($source, [BillDocument::ID_PERIOD, BillDocument::ID_RESOURCE])) {
                    $M = [
                        'all4net' => 1,
                        'service' => 1,
                        'zalog' => 0,
                        'zadatok' => 0,
                        'good' => 0,
                        '_' => $source
                    ];
                }
            } else { //invoice
                if (in_array($source, [BillDocument::ID_PERIOD, BillDocument::ID_RESOURCE])) {
                    $M['all4net'] = 1;
                    $M['service'] = 1;
                    $M['zalog'] = 0;
                    $M['zadatok'] = 0;
                    $M['good'] = 0;//($obj=='invoice'?1:0);
                    $M['_'] = $source;
                } elseif ($source == 3) {
                    $M['all4net'] = 1;
                    $M['service'] = 0;
                    $M['zalog'] = ($isViewOnly) ? 0 : 1;
                    $M['zadatok'] = 0;
                    $M['good'] = $inv3Full ? 1 : 0;
                    $M['_'] = 0;
                } elseif ($source == 4) {
                    if (!count($billLines)) {
                        return [];
                    }
                    foreach ($billLines as $val) {
                        $bill = $val;
                        break;
                    }
                    global $db;

                    $db->Query("
                    SELECT
                        bill_date,
                        nal
                    FROM
                        newbills
                    WHERE
                        bill_no = '" . $bill['bill_no'] . "'
                ");

                    $ret = $db->NextRecord(MYSQLI_ASSOC);

                    if (in_array($ret['nal'], ['nal', 'prov'])) {
                        $db->Query($q = "
                        SELECT
                            *
                        FROM
                            newpayments
                        WHERE
                            bill_no = '" . $bill['bill_no'] . "'
                    ");
                        $ret = $db->NextRecord(MYSQLI_ASSOC);
                        if ($ret == 0) {
                            return -1;
                        }
                    }

                    $query = "
                    SELECT
                        *
                    FROM
                        newbills nb
                    INNER JOIN
                        newpayments np
                    ON
                        (
                            np.bill_vis_no = nb.bill_no
                        OR
                            np.bill_no = nb.bill_no
                        )
                    AND
                        (
                            (
                                YEAR(np.payment_date)=YEAR(nb.bill_date)
                            AND
                                (
                                    MONTH(np.payment_date)=MONTH(nb.bill_date)
                                OR
                                    MONTH(nb.bill_date)-1=MONTH(np.payment_date)
                                )
                            )
                        OR
                            (
                                YEAR(nb.bill_date)-1=YEAR(np.payment_date)
                            AND
                                MONTH(np.payment_date)=1
                            AND
                                MONTH(nb.bill_date)=12
                            )
                        )

                    WHERE
                        nb.bill_no = '" . $bill['bill_no'] . "'
                ";

                    //echo $query;
                    $db->Query($query);
                    $ret = $db->NextRecord(MYSQLI_ASSOC);

                    if ($ret == 0) {
                        return 0;
                    }

                    $R = [];
                    foreach ($billLines as $item) {
                        if (preg_match("/^\s*Абонентская\s+плата|^\s*Поддержка\s+почтового\s+ящика|^\s*Виртуальная\s+АТС|^\s*Перенос|^\s*Выезд|^\s*Сервисное\s+обслуживание|^\s*Хостинг|^\s*Подключение|^\s*Внутренняя\s+линия|^\s*Абонентское\s+обслуживание|^\s*Услуга\s+доставки|^\s*Виртуальный\s+почтовый|^\s*Размещение\s+сервера|^\s*Настройка[0-9a-zA-Zа-яА-Я]+АТС|^Дополнительный\sIP[\s\-]адрес|^Поддержка\sпервичного\sDNS|^Поддержка\sвторичного\sDNS|^Аванс\sза\sподключение\sинтернет-канала|^Администрирование\sсервер|^Обслуживание\sрабочей\sстанции|^Оптимизация\sсайта/",
                            $item['item'])) {
                            $R[] = $item;
                        }
                    }
                    return $R;
                } else {
                    return [];
                }
            }
        }

        // счета из 1С выводим полностью.
        $Lkeys = array_keys($billLines);
        $is1Cbill = $Lkeys && isset($billLines[$Lkeys[0]]) && isset($billLines[$Lkeys[0]]["bill_no"]) && preg_match("/^\d{6}\/\d{4}$/i",
                $billLines[$Lkeys[0]]["bill_no"]);

        $R = [];
        foreach ($billLines as &$li) {
            if ($M[$li['type']] == 1) {
                if (
                    $M['_'] == 0
                    || ($M['_'] == 1 && $li['ts_from'] >= $period_date)
                    || ($M['_'] == 2 && $li['ts_from'] < $period_date)
                ) {
                    if (
                        $li['sum'] != 0 ||
                        $li["item"] == "S" ||
                        ($origObj == BillDocument::TYPE_GDS && $source == BillDocument::ID_RESOURCE) ||
                        preg_match("/^Аренд/i", $li["item"]) ||
                        ($li["sum"] == 0 && preg_match("|^МГТС/МТС|i", $li["item"])) ||
                        $is1Cbill
                    ) {
                        if ($li["sum"] == 0) {
                            $li["outprice"] = 0;
                            $li["price"] = 0;
                        }
                        $R[] =& $li;
                    }
                }
            }
        }

        return $R;
    }

    function do_print_prepare(\Bill &$bill, $obj, $source = 1, $curr, $do_assign = 1, $isSellBook = false, $invoiceId = false)
    {
        global $design, $db, $user;

        $design->assign('invoice_source', $source);
        $origObj = $obj;
        if ($obj == BillDocument::TYPE_GDS) {
            $obj = BillDocument::TYPE_BILL;
        }
        if ($source == BillDocument::ID_FOUR) {
            $source = BillDocument::ID_PERIOD;
            $is_four_order = true;
        } else {
            $is_four_order = false;
        }
        $design->assign('is_four_order', $is_four_order);

        if (is_null($source)) {
            $source = BillDocument::ID_GOODS;
        }

        $curr = $bill->Get('currency');


        $bdata = $bill->GetBill();


        // Если счет 1С, на товар, 
        if ($bill->is1CBill()) {
            //то доступны только счета
            if ($obj == BillDocument::TYPE_BILL && in_array($source, [BillDocument::ID_PERIOD, BillDocument::ID_RESOURCE])) {
                $inv_date = $bill->GetTs();
            } else {
                // остальные документы после отггрузки

                if ($bdata["doc_ts"]) {
                    $inv_date = $bdata["doc_ts"];
                } else {
                    if ($shipDate = $bill->getShipmentDate()) {
                        $inv_date = $shipDate;
                    } else {
                        return; //Документ не готов
                    }
                }
            }
            $period_date = get_inv_period($inv_date);
        } elseif ($bill->isOneTimeService())// или разовая услуга
        {
            if ($bdata["doc_ts"]) {
                $inv_date = $bill->GetTs();
                $period_date = get_inv_period($inv_date);
            } else {
                [$inv_date, $period_date] = get_inv_date($bill->GetTs(),
                    ($bill->Get('inv2to1') && ($source == BillDocument::ID_RESOURCE)) ? 1 : $source);
            }
        } else { // статовские переодичекские счета
            [$inv_date, $period_date] = get_inv_date($bill->GetTs(),
                ($bill->Get('inv2to1') && ($source == BillDocument::ID_RESOURCE)) ? 1 : $source);
        }


        if ($is_four_order) {
            $row = $db->QuerySelectRow('newpayments', ['bill_no' => $bill->GetNo()]);
            if ($row && $row['payment_date']) {
                $da = explode('-', $row['payment_date']);
                $inv_date = mktime(0, 0, 0, $da[1], $da[2], $da[0]);
            } else {
                $inv_date = time();
            }
            unset($da, $row);
        }

        if (in_array($obj, [BillDocument::TYPE_INVOICE, BillDocument::TYPE_AKT, BillDocument::TYPE_UPD])) {
            if (date(DateTimeZoneHelper::DATE_FORMAT, $inv_date) != date(DateTimeZoneHelper::DATE_FORMAT, $bill->GetTs())) {
                $bill->SetClientDate(date(DateTimeZoneHelper::DATE_FORMAT, $inv_date));
            }
        }


        if (in_array($obj, [BillDocument::TYPE_INVOICE, BillDocument::TYPE_UPD]) &&
            (
                in_array($source, [BillDocument::ID_PERIOD, BillDocument::ID_GOODS]) ||
                ($source == BillDocument::ID_RESOURCE && $bill->Get('inv2to1'))
            ) && $do_assign
        ) {//привязанный к фактуре счет
            //не отображать если оплата позже счета-фактуры

            ($inv_pays = $bill->getInvoicePayments()) && $design->assign('inv_pays', $inv_pays);
        }

        $bdata['ts'] = $bill->GetTs();


        $L_prev = $bill->GetLines((preg_match('/bill-\d/',
            self::$object)) ? 'order' : false);//2 для фактур значит за прошлый период


        $design->assign_by_ref('negative_balance',
            $bill->negative_balance); // если баланс отрицательный - говорим, что недостаточно средств для проведения авансовых платежей

        foreach ($L_prev as $k => $li) {
            if (!($obj == BillDocument::TYPE_BILL || ($li['type'] != 'zadatok' || $is_four_order))) {
                unset($L_prev[$k]);
            }
        }
        unset($li);

        $billLines = self::do_print_prepare_filter(
            $obj,
            $source,
            $L_prev,
            $period_date,
            (($obj == BillDocument::TYPE_INVOICE || $obj == BillDocument::TYPE_UPD) && $source == BillDocument::ID_GOODS),
            $isSellBook ? true : false,
            $origObj
        );

        if ($is_four_order) {
            $billLines =& $L_prev;
            $bill->refactLinesWithFourOrderFacure($billLines);
        }

        if ($bill->Client('type_of_bill') == ClientAccount::TYPE_OF_BILL_SIMPLE) {
            $billLines = \app\models\BillLine::compactLines(
                $billLines,
                $bill->Client()->contragent->lang_code,
                $bill->Get('price_include_vat')
            );
        }

        // скорректированные с/ф только если они есть и не в книге продаж.
        $correctionInfo = null;
        if (!$isSellBook && $bill->Get('sum_correction') && $obj != 'bill') {

            $billCorrection = BillCorrection::findOne([
                'bill_no' => $bill->Get('bill_no'),
                'type_id' => $source
            ]);


            if ($billCorrection) {
                $correctionInfo = [
                    'number' => $billCorrection->number,
                    'date' => (new DateTime($billCorrection->date)),
                    'date_timestamp' => (new DateTime($billCorrection->date))->getTimestamp()
                ];

                $billLines = $billCorrection->getLines()->asArray()->all();
            }
        }

        //подсчёт итоговых сумм, получить данные по оборудованию для акта-3

        $cpe = [];
        foreach ($billLines as &$li) {
            if ($obj == BillDocument::TYPE_AKT && $source == BillDocument::ID_GOODS && $do_assign) {            //связь строчка>устройство или строчка>подключение>устройство
                $id = null;
                if ($li['service'] == 'usage_tech_cpe') {
                    $id = $li['id_service'];
                } elseif ($li['service'] == 'usage_ip_ports') {
                    $account = $db->GetRow('SELECT id_service FROM usage_tech_cpe WHERE id_service=' . $li['id_service'] . ' AND actual_from<"' . $inv_date . '" AND actual_to>"' . $inv_date . '" ORDER BY id DESC LIMIT 1');
                    if ($account) {
                        $id = $account['id_service'];
                    }
                }
                if ($id) {
                    $account = $db->GetRow('SELECT usage_tech_cpe.*,model,vendor,type FROM usage_tech_cpe INNER JOIN tech_cpe_models ON tech_cpe_models.id=usage_tech_cpe.id_model WHERE usage_tech_cpe.id=' . $id);
                    $account['amount'] = floatval($li['amount']);
                    $cpe[] = $account;
                } else {
                    $cpe[] = [
                        'type' => '',
                        'vendor' => '',
                        'model' => $li['item'],
                        'serial' => '',
                        'amount' => floatval($li['amount']),
                        "actual_from" => $li["date_from"]
                    ];
                }
            }
        }
        unset($li);

        if ($do_assign) {
            $design->assign('cpe', $cpe);
            $design->assign('curr', $curr);
            $invoice = null;
            $shippedDate = null;
            if (in_array($obj, [BillDocument::TYPE_INVOICE, BillDocument::TYPE_AKT, BillDocument::TYPE_UPD])) {

                $newInvoiceNumber = false;

                $where = [
                    'bill_no' => $bill->Get('bill_no'),
                    'is_reversal' => 0
                ];

                $where['type_id'] = $is_four_order ? Invoice::TYPE_PREPAID : $source;

                if ($invoiceId) {
                    $where['id'] = $invoiceId;
                }

                /** @var Invoice $invoice */
                if ($invoice = Invoice::find()->where($where)->orderBy(['id' => SORT_DESC])->one()) {
                    $newInvoiceNumber = $invoice->number;

                    // if ($is_four_order) {
                        $inv_date = (new \DateTimeImmutable($invoice->date))->getTimestamp();
                    // }

                    if ($invoice->lines || $invoice->sum == 0) {
                        $billLines = $invoice->lines;
                    }

                    if ($invoice->type_id == Invoice::TYPE_GOOD) {
                        $shippedDate = Invoice::getShippedDateFromTrouble($invoice->bill);
                    }
                }

                $design->assign('is_document_ready', $newInvoiceNumber || $bill->Get('bill_date') < Invoice::DATE_ACCOUNTING);

                $design->assign('inv_number', $newInvoiceNumber);
                $design->assign('invoice', $invoice);

                $design->assign('inv_no', '-' . $source);
                $design->assign('inv_date', $inv_date);
                $design->assign('inv_is_new', ($inv_date >= mktime(0, 0, 0, 5, 1, 2006)));
                $design->assign('inv_is_new2', ($inv_date >= mktime(0, 0, 0, 6, 1, 2009)));
                $design->assign('inv_is_new3', ($inv_date >= mktime(0, 0, 0, 1, 24, 2012)));
                $design->assign('inv_is_new4', ($inv_date >= mktime(0, 0, 0, 2, 13, 2012)));
                $design->assign('inv_is_new5', ($inv_date >= mktime(0, 0, 0, 10, 1, 2012))); // доработки в акте и сф, собственные (акциз, шт => -) + увеличен шрифт в шапке
                $design->assign('inv_is_new6', ($inv_date >= mktime(0, 0, 0, 1, 1, 2013))); // 3 (объем), 5 всего, 6 сумма, 8 предъявлен покупателю, 8 всего
                $design->assign('inv_is_new7', ($inv_date >= mktime(0, 0, 0, 7, 1, 2021)));
                $design->assign('inv_is_new8', ($inv_date >= mktime(0, 0, 0, 10, 1, 2024)));
            }

            $bdata["sum"] = 0;
            $bdata['sum_without_tax'] = 0;
            $bdata['sum_tax'] = 0;

            foreach ($billLines as &$li) {
                $bdata['sum'] += $li['sum'];
                $bdata['sum_without_tax'] += $li['sum_without_tax'];
                $bdata['sum_tax'] += $li['sum_tax'];
            }


            $design->assign('opener', 'interface');
            $design->assign('bill', $bdata);
            $design->assign('bill_lines', $billLines);
            $design->assign('correction_info', $correctionInfo);
            $design->assign('shipped_date', $shippedDate ? $shippedDate->getTimestamp() : null);
            $total_amount = 0;
            foreach ($billLines as $line) {
                $total_amount += round($line['amount'], 2);
            }
            $design->assign('total_amount', $total_amount);

            $clientAccount = $bill->Client();

            $date = $inv_date ?: $bill->Get('bill_date');

            if ($correctionInfo) {
                $date = $correctionInfo['date']->format(DateTimeZoneHelper::DATE_FORMAT);
            }

            /** @var ClientAccount $clientAccount */
            $organization = $clientAccount->contract->getOrganization($date);

            $organization_info = $organization->getOldModeInfo();

            $design->assign("organization", $organization);
            $design->assign('firm', $organization_info);
            $design->assign('firma', $organization_info);
            $design->assign('firm_director', $organization->director->getOldModeInfo());
            $design->assign('firm_buh', $organization->accountant->getOldModeInfo());
            //** /Выпилить */

            ClientCS::Fetch($clientAccount);
            $design->assign('bill_client', $clientAccount);
            return true;
        } else {
            $bdata["sum"] = 0;
            $bdata['sum_without_tax'] = 0;
            $bdata['sum_tax'] = 0;

            foreach ($billLines as &$li) {
                $bdata['sum'] += $li['sum'];
                $bdata['sum_without_tax'] += $li['sum_without_tax'];
                $bdata['sum_tax'] += $li['sum_tax'];
            }

            if (in_array($obj, [BillDocument::TYPE_INVOICE, BillDocument::TYPE_AKT, BillDocument::TYPE_UPD])) {
                return [
                    'bill' => $bdata,
                    'bill_lines' => $billLines,
                    'inv_no' => $bdata['bill_no'] . '-' . $source,
                    'inv_date' => $inv_date
                ];
            } else {
                return ['bill' => $bdata, 'bill_lines' => $billLines];
            }
        }
    }

    function newaccounts_pi_list($fixclient)
    {
        global $design, $db;

        $param = Param::findOne(["param" => Param::PI_LIST_LAST_INFO]);
        if ($param) {
            foreach (json_decode($param->value) as $line) {
                trigger_error2($line);
            }
        }


        $filter = get_param_raw('filter', ['d' => '', 'm' => date('m'), 'y' => date('Y')]);
        $R = [];
        $d = dir(PAYMENTS_FILES_PATH);

        $paymentQuery = PaymentSberOnline::find();

        if (!$filter['d'] && !$filter['m'] && !$filter['y']) {
            $pattern = '';
        } else {
            $pattern = '/';
            if ($filter['d']) {
                $paymentQuery->andWhere(['day' => $filter['d']]);
                $pattern .= $filter['d'];
            } else {
                $pattern .= '..';
            }
            $pattern .= '.';
            if ($filter['m']) {
                $paymentQuery->andWhere(['month' => $filter['m']]);
                $pattern .= $filter['m'];
            } else {
                $pattern .= '..';
            }
            $pattern .= '.';
            if ($filter['y']) {
                $paymentQuery->andWhere(['year' => $filter['y']]);
                $pattern .= $filter['y'];
            } else {
                $pattern .= '....';
            }
            $pattern .= '\.txt$/';
        }

        $data = [];
        $all[] = [];
        while ($e = $d->read()) {
            if ($e != '.' && $e != '..') {
                if (!$pattern || preg_match($pattern, $e)) {
                    $R[] = $e;
                    if (preg_match_all("/^([^_]+)_([^_]+)(_([^_]+))?__(\d+-\d+-\d+).+/", $e, $o, PREG_SET_ORDER)) {
                        $o = $o[0];
                        $data[strtotime($o[5])][$o[2]][$o[1] . $o[3]] = $o[0];
                        $all[] = $o[0];
                    } elseif (preg_match_all("/^(.+?)(\d+_\d+_\d+).+/", $e, $o, PREG_SET_ORDER)) {
                        $o = $o[0];
                        $co = $o[1] == "citi" ? "mcn" : ($o[1] == "ural" ? "cmc" : "mcn");
                        $acc = $o[1] == "mcn" ? "mos" : $o[1];
                        $data[strtotime(str_replace("_", "-", $o[2]))][$co][$acc] = $o[0];

                        $all[] = $o[0];

                    } else {
                    }

                }
            }
        }

        foreach ($paymentQuery->select(['day_timestamp' => new Expression('UNIX_TIMESTAMP(cast(created_at as date))')])->distinct()->createCommand()->queryAll() as $paymentDay) {
            if (!isset($data[$paymentDay['day_timestamp']])) {
                $data[$paymentDay['day_timestamp']] = [];
            }

            $data[$paymentDay['day_timestamp']]['sber_online']['sber'] = 'sber_online_' . date('d-m-Y', $paymentDay['day_timestamp']);
        }

        foreach($data as &$dayData) {
            foreach($dayData as &$co) {
                $b = [];
                foreach($co as $k => $file) {
                    $b = [$k => ['name' => $file]];
                    $key = 'payment_file_count:' . $file;
                    $count = \Yii::$app->cache->get($k);
                    if ($count === false) {
                        [, , $payments] = PaymentParser::Parse(PAYMENTS_FILES_PATH . $file);
                        $count = count($payments);

                        \Yii::$app->cache->set($key, $count, \app\classes\helpers\DependecyHelper::TIMELIFE_MONTH, new \yii\caching\TagDependency(['tags' => [DependecyHelper::TAG_PAYMENTS]]));
                    }

                    $b[$k]['count'] = $count;
                    $b[$k]['links'] = ['all' => $file];
                    $pages = ceil($count / self::PI_IMPORT_RECPERPAGE);
                    if ($pages > 1) {
                        foreach (range(1, $pages) as $i) {
                            $b[$k]['links']['p' . $i] = $file . 'p' . $i;
                        }
                    }
                }
                $co = $b;
            }
        }

        ksort($data);
        $d->close();
        sort($R);
        $design->assign('payments', $data);

        if (get_param_raw('get_list')) {
            echo implode("<br>\n", array_filter($all));
        }

        $design->assign("l1", [
                "mcn" => [
                    "title" => "Эм Си Эн",
                    "colspan" => 2
                ],
                "all4net" => [
                    "title" => "All4Net",
                    "colspan" => 3
                ],
                "cmc" => [
                    "title" => "Си Эм Си",
                    "colspan" => 1
                ],
                "telekom" => [
                    "title" => "МСН Телеком",
                    "colspan" => 1
                ],
                "mcm_telekom" => [
                    "title" => "МСН Телеком Ритейл",
                    "colspan" => 1
                ],
                "mcnservice" => [
                    "title" => "МСН Телеком Сервис",
                    "colspan" => 1
                ],

                "sber_online" => [
                    "title" => "Сбербанк Online",
                    "colspan" => 1
                ]
            ]
        );

        $design->assign("companyes", [
                "mcn" => [
                    "acc" => ["mos", "citi"]
                ],
                "all4net" => [
                    "acc" => ["citi_rub", "citi_usd", "ural"]
                ],
                "cmc" => [
                    "acc" => ["ural"]
                ],
                "telekom" => [
                    "acc" => ["sber"]
                ],
                "mcm" => [
                    "acc" => ["sber"]
                ],
                "mcnservice" => [
                    "acc" => ["sber"]
                ],
                "sber_online" => [
                    "acc" => ["sber"]
                ]
            ]
        );

        $design->assign('filter', $filter);
        $design->AddMain('newaccounts/pay_import_list.tpl');
    }

    function newaccounts_pi_upload($fixclient)
    {
        global $_FILES, $design;

        $fHeader = $this->readFileHeader($_FILES['file']['tmp_name']);

        $d = false;

        if (PaymentSberOnline::dao()->detectPaymentArchive($_FILES['file']['tmp_name'])) {
            PaymentSberOnline::dao()->loadPaymentsFromArchive($_FILES['file']['tmp_name']);
        } else {
            if ($type = PaymentSberOnline::dao()->detectPaymentListType($_FILES['file'])) {
                PaymentSberOnline::dao()->loadPaymentListFromFile($_FILES['file']['tmp_name'], $type);
            } elseif (PaymentSberOnline::dao()->detectPaymentList($fHeader)) {
                PaymentSberOnline::dao()->loadPaymentListFromFile($_FILES['file']['tmp_name']);
            } else {
                include INCLUDE_PATH . "mt940.php";
                $d = Banks::detect($fHeader);
            }
        }


        if ($d) {
            if ($this->isPaymentInfo($fHeader)) {
                $date = $this->getPaymentInfoDate($fHeader);
                move_uploaded_file($_FILES['file']['tmp_name'],
                    PAYMENTS_FILES_PATH . $d["file"] . "_info__" . str_replace(".", "-", $date) . ".txt");
            } else {

                $fName = $d["file"] . "__";

                switch ($d["bank"]) {
                    case 'sber':
                    case 'ural':

                        $this->saveClientBankExchangePL($_FILES['file']['tmp_name'], $d["file"]/*$fName*/);

                        /*
                       $data = $this->getUralSibPLDate($fheader);
                       move_uploaded_file($_FILES['file']['tmp_name'],PAYMENTS_FILES_PATH.$fName.str_replace(".","-", $data).".txt");
                       */
                        break;

                    case 'mos':
                        $data = $this->getMosPLDate($fHeader);
                        move_uploaded_file($_FILES['file']['tmp_name'],
                            PAYMENTS_FILES_PATH . $fName . str_replace(".", "-", $data) . ".txt");
                        break;

                    case 'citi':
                        $fName = $_FILES['file']['tmp_name'];
                        $this->saveCitiBankPL($fName);
                        break;
                }
            }
        }
        if ($design->ProcessEx('errors.tpl')) {
            header('Location: ?module=newaccounts&action=pi_list');
            exit();
        }
    }

    function getPaymentInfoDate($h)
    {
        @preg_match_all("/БИК \d{9}\s+(\d{2}) ([^ ]+) (\d{4})\s+ПРОВЕДЕНО/", iconv("cp1251", "utf-8//translit", $h), $o,
            PREG_SET_ORDER);

        $month = "янв фев мар апр май июн июл авг сен окт ноя дек";

        $m = array_search($o[0][2], explode(" ", $month)) + 1;
        $m .= "";
        if (strlen($m) == 1) {
            $m = "0" . $m;
        }

        return $o[0][1] . "." . $m . "." . $o[0][3];
    }

    function isPaymentInfo($fheader)
    {
        @preg_match_all("/ПОРУЧЕНИЕ/", iconv("cp1251", "utf-8//translit", $fheader), $f);
        return isset($f[0][0]);
    }

    function getUralSibPLDate($h)
    {
        $h = iconv("cp1251", "utf-8", $h);
        preg_match_all("@ДатаНачала=(.+)\r?\n@", $h, $o);
        return str_replace("\r", "", $o[1][0]);
    }

    function getMosPLDate($h)
    {
        preg_match_all("@BEGIN_DATE=(.+)\r?\n@", $h, $o);
        return str_replace("\r", "", $o[1][0]);
    }

    function saveCitiBankPL($fPath)
    {
        include_once INCLUDE_PATH . "mt940.php";

        $c = file_get_contents($fPath);
        MT940ListManager::parseAndSave($c);
    }

    function saveClientBankExchangePL($fPath, $prefix)
    {
        $import = new importCBE($prefix, $_FILES['file']['tmp_name']);
        $info = $import->save();

        $lines = [];
        if ($info) {
            $totalPlus = $totalMinus = 0;
            foreach ($info as $day => $count) {
                $new = isset($count["new"]) ? $count["new"] : 0;
                $skiped = isset($count["skiped"]) ? $count["skiped"] : 0;
                $plus = isset($count["sum_plus"]) ? $count["sum_plus"] : 0;
                $minus = isset($count["sum_minus"]) ? $count["sum_minus"] : 0;


                $lines[] = "За " . mdate("d месяца Y", strtotime($day)) . " найдено платежей: " . $count["all"] .
                    //($skiped ? ", пропущено: ".$skiped : "").
                    //($new ? ", новых: ".$new: "").
                    "&nbsp;&nbsp;&nbsp;&nbsp;+" . number_format($plus, 2, ".", "`") . "/-" . number_format($minus, 2,
                        ".", "`");
            }

        }

        Param::setParam(Param::PI_LIST_LAST_INFO, $lines);

        /*
        include_once INCLUDE_PATH."mt940.php";

        $c = file_get_contents($fPath);
        cbe_list_manager::parseAndSave($c, $fName);
         */
    }

    function readFileHeader($fPath)
    {
        $pFile = fopen($fPath, "rb");
        $fheader = fread($pFile, 4096);
        /*
        if(($p = strpos($fheader, "\n")) !== false){
            $fheader = substr($fheader, 0, $p-1);
        }*/
        return $fheader;
    }

    function importPL_citibank_apply()
    {
        $pays = get_param_raw("pays", []);

        print_r($pays);
    }

    function getFirmByPayAccs($pas)
    {
        global $db;

        $firms = [];
        foreach ($db->AllRecords("SELECT firma FROM firma_pay_account WHERE pay_acc IN ('" . implode("', '",
                $pas) . "')") as $f) {
            $firms[] = $f["firma"];
        }
        return $firms;
    }

    function getCompanyByInn($inn, $organizations, $fromAdd = false)
    {
        global $db;

        $v = [];

        if ($inn) {
            $q = $fromAdd ?
                "SELECT client_id AS id FROM client_inn p, clients c
INNER JOIN `client_contract` cr ON cr.id=c.contract_id
INNER JOIN `client_contragent` cg ON cg.id=cr.contragent_id
WHERE p.inn = '" . $inn . "' AND p.client_id = c.id AND p.is_active"
                :
                "SELECT c.id FROM clients c
 INNER JOIN `client_contract` cr ON cr.id=c.contract_id
 INNER JOIN `client_contragent` cg ON cg.id=cr.contragent_id
WHERE cg.inn = '" . $inn . "'";

            foreach ($db->AllRecords($qq = $q . " and cr.organization_id in ('" . implode("','",
                    $organizations) . "')") as $c) {
                $v[] = $c["id"];
            }

        }

        return $v;
    }

    public function importSberOnline($file)
    {
        $payments = [];

        if (preg_match('/sber_online_([0-9]{2})-([0-9]{2})-([0-9]{4})/', $file, $match)) {
            foreach (PaymentSberOnline::find()
                         ->andWhere([
                             'day' => $match[1],
                             'month' => $match[2],
                             'year' => $match[3]
                         ])->all() as $payment) {

                /** @var $payment PaymentSberOnline */
                $payments[] = [
                    'no' => $payment->code4,
                    'date_exch' => $payment->payment_sent_date,
                    'date' => $payment->payment_received_date,
                    'oper_date' => $payment->payment_sent_date,
                    'sum' => $payment->sum_paid,
                    'noref' => $payment->code4,
                    'inn' => '7707083893',
                    'description' => $payment->description,
                    'from' =>
                        [
                            'bik' => '044583547',
                            'account' => '40702810600020009191',
                            'a2' => 'ОАО "СБЕРБАНК РОССИИ"'
                        ],
                    'company' => $payment->payer,
                    'is_no_check' => 1
                ];
            }
        }

        return $this->importPL_citibank($file, ['40702810038110015462' => '40702810038110015462'], $payments);
    }

    function importPL_citibank($file, $_payAccs = false, $_pays = [])
    {
        global $design, $db;

        $d = [];
        $sum = ["plus" => 0, "minus" => 0, "imported" => 0, "all" => 0];


        include_once INCLUDE_PATH . "mt940.php";

        if ($_payAccs === false) {
            $c = file_get_contents($file);
            $m = new MT940($c);

            $pays = $m->getPays();
            $payAccs = [$m->getPayAcc()];

            $f = explode("/", $file);
            $f = $f[count($f) - 1];
            if (preg_match_all("/(citi_mcn)(__\d{2}-\d{2}-\d{4}\.txt)/", $f, $o)) {
                $infoFile = PAYMENTS_FILES_PATH . $o[1][0] . "_info" . $o[2][0];
                if (file_exists($infoFile)) {
                    include_once INCLUDE_PATH . "citi_info.php";

                    $c = citiPaymentsInfoParser::parse(file_get_contents($infoFile));

                    citiInfo::add($pays, $c);
                }
            }

        } else {
            $payAccs = $_payAccs;
            $pays = $_pays;
            //$pays=array($pays[0]);
            usort($pays, ["MT940", "sortBySum"]);
        }

        $firms = $this->getFirmByPayAccs($payAccs);
        $date_formats = ['d.m.Y', 'd.m.y', 'd-m-Y', 'd-m-y'];

        // @TODO: на переходный период разрешить платить на Ритейл с Сервиса
        if (in_array('mcm_telekom', $firms)) {
            $firms[] = 'mcn_telekom_ser';
        }

        $organizations = Organization::find()
            ->andWhere(['organization.firma' => $firms])
            ->actual()
            ->select('organization.organization_id')
            ->column();

        foreach ($pays as $pay) {
            //if(abs($pay["sum"]) != 7080   ) continue;
            //if($pay["noref"] != 427) continue;

            $pay['is_need_to_send_atol'] =
                strpos(
                    str_replace(
                        ['р/с', 'p/c', 'p/с', 'р/c', 'л/с', 'л/c'],
                        '',
                        mb_strtolower(
                            strip_tags($pay['company'])
                        )
                    ), '/') !== false || (preg_match('/^(\s+)?([\w\-]+)\s+([\w\-]+)\s+([\w\-]+)/mu', $pay['company']));

            $clientId = [];
            $billNo = $this->GetBillNoFromComment(@$pay["description"]);

            if ($billNo) {
                $clientId = $this->getCompanyByBillNo($billNo, $organizations);
            }

            $clientId2 = $this->getCompanyByPayAcc(@$pay["from"]["account"], $organizations);
            $clientId3 = $this->getCompanyByPayAcc(@$pay["from"]["account"], $organizations, true);

            $clientId4 = $clientId5 = [];
            if (isset($pay["inn"])) {
                $clientId4 = $this->getCompanyByInn(@$pay["inn"], $organizations);
                $clientId5 = $this->getCompanyByInn(@$pay["inn"], $organizations, true);
            }

            $clientIdLs = $this->getClientIdByDescription($pay['description']);

            $clientIdExtBills = [];
            // только внешние счета
            $pay['sum'] < 0 && $clientIdExtBills = $this->getClientIdInExtBills($pay['description']);

            if ($clientId && !$clientId2 && !$clientId3 && !$clientId4 && !$clientId5) {
                $pay["to_check_bill_only"] = 1;
            }

            $clientIdSum =
                $clientIdLs ?
                    [$clientIdLs] :
                    ($clientIdExtBills ?
                        $clientIdExtBills :
                        array_unique(array_merge($clientId, $clientId2, $clientId3, $clientId4, $clientId5)));


            // если счет и клиент различаются
            if ($clientId2 && $clientId && array_search($clientId[0], $clientId2) === false && (!isset($pay['is_no_check']) || !$pay['is_no_check'])) {
                $pay["to_check"] = 1;
            }

            $pay["bill_no"] = $billNo;
            $pay["clients"] = $this->getClient($clientIdSum);

            $pay['from_str'] = implode(
                "<br />",
                array_map(
                    function ($key, $value) {
                        $names = [
                            'bik' => "БИК",
                            'account' => "р/с",
                            'a2' => "БАНК"
                        ];
                        return (isset($names[$key]) ? $names[$key] : $key) . ": " . $value;
                    },
                    array_keys($pay['from']),
                    $pay['from']
                ));


            if ($clientIdSum) {
                $pay["clients_bills"] = $this->getClientBills($clientIdSum, $billNo);
                if ($pay["sum"] < 0) {
                    $pay["clients_bills"][] = ["bill_no" => "--Минус счета--", "is_payed" => -1, "is_group" => 1];

                    foreach ($this->getClientMinusBills($clientIdSum, $billNo) as $p) {
                        if ($this->notInBillList($pay["clients_bills"], $p)) {
                            $pay["clients_bills"][] = $p;
                        }
                    }
                }

                $extBills = [];
                foreach ($pay["clients_bills"] as &$b) {
                    $b['is_selected'] = false;
                    if (!isset($b["bill_no_ext"]) || !$b["bill_no_ext"]) {
                        continue;
                    }
                    if (isset($b["is_group"]) && $b["is_group"]) {
                        continue;
                    }

                    $extBills[$b["bill_no_ext"]][] = $b["bill_no"];

                    $description = preg_replace_callback("@\d[\d\/ -]{3,}@", function ($m) {
                        return str_replace(" ", "", $m[0]);
                    }, $pay["description"]);
                    if (strpos($description, $b["bill_no_ext"]) !== false) {
                        $b["ext_no"] = $b["bill_no_ext"];
                    }
                    if (!isset($b["ext_no"]) || !isset($b["bill_no_ext_date"]) || !$b["bill_no_ext_date"]) {
                        continue;
                    }

                    foreach ($date_formats as $format) {
                        $bill_no_ext_date = date($format, $b["bill_no_ext_date"]);
                        if (strpos($description, $bill_no_ext_date) !== false) {
                            $b["ext_no_date"] = $bill_no_ext_date;
                            break;
                        }
                    }
                }
                unset($b);
            }


            if ($pay["clients"][0]['currency'] != 'RUB') {
                $pay['usd_rate'] = $this->getPaymentRate($pay["date"]);
            }

            if (isset($pay['usd_rate']) && $pay['usd_rate'] && $pay['bill_no']) {
                $r = $db->GetRow('SELECT sum AS S FROM newbills WHERE bill_no="' . $pay['bill_no'] . '"');
                if ($r && $r['S'] != 0) {
                    $rate_bill = round($pay['sum'] / $r['S'], 4);
                    if (abs($rate_bill - $pay['usd_rate']) / $pay['usd_rate'] <= 0.03) {
                        $pay['usd_rate'] = $rate_bill;
                    }
                }
            }

            $this->isPayPass($clientIdSum, $pay);

            if (!empty($pay["clients_bills"])) {
                $rank = 0;
                $selected = null;
                foreach ($pay["clients_bills"] as $k => $b) {
                    if ($pay['bill_no'] == $b['bill_no']) {
                        $selected = $k;
                        break;
                    } elseif ($rank < 2 && $pay['sum'] < 0 && isset($b['sum']) && $pay['sum'] == $b['sum']) {
                        if (isset($b['ext_no']) && isset($b['ext_no_date'])) {
                            $rank = 2;
                            $selected = $k;
                        } elseif (isset($b['ext_no'])) {
                            $selected = $k;
                            $rank = 1;
                        } elseif (!$rank) {
                            $selected = $k;
                        }
                    }
                }
                if (!is_null($selected)) {
                    $pay["clients_bills"][$selected]['is_selected'] = true;
                }
            }

            $sum["all"] += $pay["sum"];
            $sum["plus"] += $pay["sum"] > 0 ? $pay["sum"] : 0;
            $sum["minus"] += $pay["sum"] < 0 ? -$pay["sum"] : 0;
            if (isset($pay["imported"]) && $pay["imported"]) {
                $sum["imported"] += $pay["sum"];
            }

            $d[] = $pay;
        }

        $bills = [];

        foreach ($d as $p) {
            if (isset($p["imported"]) && $p["imported"] && $p["bill_no"] && substr($p["bill_no"], 6, 1) == "-") {
                $bill = new \Bill($p["bill_no"]);

                if (/*substr($bill->Get("bill_date"), 7,3) == "-01" && */
                    $bill->Get("postreg") == "0000-00-00" && !$bill->isOneZadatok()
                ) {
                    $c = ClientAccount::findOne($bill->client_id);
                    if (in_array('payment', $c->getOption('mail_delivery_variant'))) {
                        $bills[] = $p["bill_no"];
                    }
                }
            }
        }

        $design->assign('file', $file);
        $design->assign("sum", $sum);
        $design->assign("pays", $d);
        $design->assign("bills", $bills);
        $design->AddMain('newaccounts/pay_import_process_citi.tpl');
    }

    function notInBillList(&$pl, $p)
    {
        if (isset($p["is_group"]) && $p["is_group"]) {
            return true;
        }

        foreach ($pl as $l) {
            if ($l["bill_no"] == $p["bill_no"]) {
                return false;
            }
        }
        return true;
    }

    function isPayPass($clientIds, &$pay)
    {
        global $db;

        $pay['is_sended_to_atol'] = false;
        $pay['payment_id'] = false;

        if (!$clientIds) {
            $clientIds = [-1];
        }
        if ($pm = $db->GetRow('SELECT id, comment,bill_no FROM newpayments WHERE client_id IN ("' . implode('","',
                $clientIds) . '") AND sum = "' . $pay['sum'] . '" AND payment_date = "' . $pay['date'] . '" AND type="bank" AND payment_no = "' . $pay["noref"] . '"')
        ) {
            $pay['imported'] = 1;
            $pay["comment"] = $pm["comment"];
            $pay["bill_no"] = $pm["bill_no"];
            $pay['payment_id'] = $pm['id'];

            if ($pay['is_need_to_send_atol']) {
                $pay['is_sended_to_atol'] = \app\models\PaymentAtol::find()->where(['id' => $pm['id']])->exists();
            }
        }
    }

    function getClient($clientIds)
    {
        if (!$clientIds) {
            return false;
        }
        if (!is_array($clientIds)) {
            $clientIds = [$clientIds];
        }

        static $c = [];

        $diffId = array_diff($clientIds, array_keys($c));

        if ($diffId) {
            $clients = (new \yii\db\Query())
                ->select(['c.id', 'c.client', 'cg.name', 'full_name' => 'cg.name_full', 'cr.manager', 'c.currency', 'cr.organization_id', 'cg.inn', 'c.pay_acc'])
                ->from(['c' => ClientAccount::tableName()])
                ->innerJoin(['cr' => ClientContract::tableName()], 'cr.id=c.contract_id')
                ->innerJoin(['cg' => ClientContragent::tableName()], 'cg.id=cr.contragent_id')
                ->where(['c.id' => $diffId])
                ->orderBy(['c.id' => SORT_ASC])
                ->all();

            static $organizationStore = [];
            foreach ($clients as $client) {
                if (!array_key_exists($client['organization_id'], $organizationStore)) {
                    $organizationStore[$client['organization_id']] = Organization::find()->byId($client['organization_id'])->actual()->one();
                }

                $client['organization_name'] = $organizationStore[$client['organization_id']] ?
                    $organizationStore[$client['organization_id']]->name->value : '';

                $c[$client['id']] = $client;
            }
        }

        $data = [];
        sort($clientIds);
        foreach ($clientIds as $clientId) {
            if ($c[$clientId]) {
                $data[] = $c[$clientId];
            }
        }

        return $data;
    }

    function getClientBills($clientIds, $billNo)
    {
        global $db;

        $v = [];

        static $cache = [];

        sort($clientIds);

        foreach ($clientIds as $clientId) {
            if (count($clientIds) > 1) {
                $c = $this->getClient($clientId);
                $v[] = [
                    "bill_no" => "--" . $c[0]["client"] . "--",
                    "is_payed" => -1,
                    "is_group" => true,
                    "bill_no_ext" => false
                ];
            }

            if (isset($cache[$clientId])) {
                $v = array_merge($v, $cache[$clientId]);
                continue;
            }

            $clientV = [];

            foreach ($db->AllRecords($q = '
                        (select bill_no, is_payed,sum,ext_bill_no as bill_no_ext,UNIX_TIMESTAMP(ext_bill_date) as bill_no_ext_date, client_id from newbills n2
                        left join newbills_external e using (bill_no)
                         where n2.client_id="' . $clientId . '" and n2.is_payed=1

                         order by  n2.id /* n2.bill_date */ desc limit 1)
                        union (select bill_no, is_payed,sum,ext_bill_no as bill_no_ext,UNIX_TIMESTAMP(ext_bill_date) as bill_no_ext_date, client_id 
                        from newbills 
                        left join newbills_external e using (bill_no)
                        where client_id=' . $clientId . ' and bill_no = "' . $billNo . '")
                        
                        union (select bill_no, is_payed,sum,ext_bill_no as bill_no_ext,UNIX_TIMESTAMP(ext_bill_date) as bill_no_ext_date, client_id 
                        from newbills 
                        left join newbills_external e using (bill_no)
                        
                        where client_id=' . $clientId . ' and is_payed!="1")
                        ORDER BY client_id
                        '
            ) as $b) {
                $clientV[] = $b;
            }

            $cache[$clientId] = $clientV;
            $v = array_merge($v, $cache[$clientId]);
        }
        return $v;
    }

    function getClientMinusBills($clientsIds, $billNo)
    {
        global $db;

        $where = "sum < 0 and client_id in ('" . implode("','", $clientsIds) . "')";

        $v = [];

        // все неоплаченные, и последний оплаченный
        foreach ($db->AllRecords("
        (select b.bill_no, b.sum, ext_bill_no as bill_no_ext, UNIX_TIMESTAMP(ext_bill_date) as bill_no_ext_date, if((select count(*) from newpayments p where p.bill_no = b.bill_no)>=1,1,0) as is_payed1, client_id
            from newbills b 
            left join newbills_external e using (bill_no)
            where " . $where . " having is_payed1 = 0)
        union
        (select b.bill_no, b.sum, ext_bill_no as bill_no_ext, UNIX_TIMESTAMP(ext_bill_date) as bill_no_ext_date, if((select count(*) from newpayments p where p.bill_no = b.bill_no)>=1,1,0) as is_payed1, client_id
            from newbills b 
            left join newbills_external e using (bill_no)
            where " . $where . " having is_payed1 = 1 order by bill_no desc limit 1)
        ") as $p) {
            $p["is_payed"] = $p["is_payed1"] ? -1 : 0;
            $v[] = $p;
        }
        return $v;
    }

    function getCompanyByPayAcc($acc, $organizations, $fromAdd = false)
    {
        global $db;

        $v = [];

        if ($acc) {

            $q = $fromAdd ?
                "SELECT client_id AS id FROM client_pay_acc p, clients c
 INNER JOIN `client_contract` cr ON cr.id=c.contract_id
 INNER JOIN `client_contragent` cg ON cg.id=cr.contragent_id
WHERE p.pay_acc = '" . $acc . "' AND p.client_id = c.id"
                :
                "SELECT c.id FROM clients c
 INNER JOIN `client_contract` cr ON cr.id=c.contract_id
 INNER JOIN `client_contragent` cg ON cg.id=cr.contragent_id
WHERE c.pay_acc = '" . $acc . "'";

            foreach ($db->AllRecords($qq = $q . " and cr.organization_id in ('" . implode("','",
                    $organizations) . "')") as $c) {
                $v[] = $c["id"];
            }

        }

        return $v;
    }

    function getCompanyByBillNo($billNo, $organizations)
    {
        global $db;

        $bill = Bill::findOne(['bill_no' => $billNo]);

        if (!$bill) {
            return [];
        }

        /** @var $contract ClientContract */
        $contract = $bill->clientAccount->contract->loadVersionOnDate($bill->bill_date);

        if (!$contract) {
            return [];
        }

        if (in_array($contract->organization_id, $organizations)) {
            return [$bill->client_id];
        }

        return [];

        $r = $db->GetRow("SELECT client_id FROM newbills b, clients c
 INNER JOIN `client_contract` cr ON cr.id=c.contract_id
WHERE b.bill_no = '" . $billNo . "' AND c.id = b.client_id AND cr.organization_id IN ('" . implode("','", $organizations) . "')");
        return $r ? [$r["client_id"]] : [];

    }

    function GetBillNoFromComment($c)
    {
        $isDetected = preg_match('|20\d{4} ?[-/]\d{1,6}(?:-\d+)?|', $c, $m);

        if (!$isDetected) {
            $isDetected = preg_match('|[12]\d{9,11}|', $c, $m);
        }

        if (!$isDetected) {
            return false;
        }

        $billNo = str_replace(" ", "", $m[0]);

        if (
        Bill::find()
            ->where(['bill_no' => $billNo])
            ->exists()
        ) {
            return $billNo;
        }

        return false;
    }

    /**
     * Поиск по явно указанному ЛС
     *
     * @param $comment
     * @return bool
     */
    public function getClientIdByDescription($comment)
    {
        if (preg_match('/ЛС: (\d{4,})\s*$/', $comment, $m)) {
            return $m[1];
        }

        return false;
    }

    /**
     * Поиск клиента по внешнему счету
     *
     * @param $comment
     * @return array
     */
    public function getClientIdInExtBills($comment)
    {
        static $bills = [];

        if (!$bills) {
            $monthThis = new DateTimeImmutable('now');
            $monthPrev = $monthThis->modify('first day of previous month');
            $bills = BillExternal::find()
                ->alias('e')
                ->joinWith('bill b', true, 'INNER JOIN')
                ->where(['>=', new Expression('LENGTH(e.ext_bill_no)'), 5])
                ->andWhere(['OR',
                    ['like', 'e.bill_no', $monthThis->format('Ym') . '-%', false],
                    ['like', 'e.bill_no', $monthPrev->format('Ym') . '-%', false],
                ])
                ->andWhere(['<', 'b.sum', 0])
                ->select(['b.client_id'])
                ->indexBy('ext_bill_no')
                ->column();
        }

        $ids = [];
        foreach ($bills as $extBillNo => $clientId) {
            if (strpos($comment, (string)$extBillNo) !== false) {
                $ids[] = $clientId;
            }
        }

        return array_unique($ids);
    }

    function getPaymentRate($date)
    {
        static $d = [];

        if (!isset($d[$date])) {
            global $db;
            $tableName = CurrencyRate::tableName();
            $r = $db->GetRow('SELECT * FROM ' . $tableName . ' WHERE date="' . addslashes($date) . '" AND currency="USD"');
            $d[$date] = $r['rate'];
        }
        return $d[$date];
    }

    function restructPayments($payAccs, $pp)
    {
        $r = [];

        foreach ($pp as $k => $p) {

            $isIn = !isset($payAccs[$p["account"]]);

            if (!$isIn) {
                $p["sum"] = -abs($p["sum"]);
                $p["account"] = $p["geter_acc"];
                $p["bik"] = $p["geter_bik"];
                $p["payer"] = $p["geter"];
                $p["inn"] = $p["geter_inn"];
                $p["oper_date"] = $p["date_dot"];
            }

            $od1 = explode(".", $p["date_dot"]);
            $od = explode(".", $p["oper_date"]);

            $r[] = [
                "no" => $k + 1,
                "date_exch" => $od1[2] . "-" . $od1[1] . "-" . $od1[0],
                "date" => $od1[2] . "-" . $od1[1] . "-" . $od1[0],
                "oper_date" => $od[2] . "-" . $od[1] . "-" . $od[0],
                "sum" => $p["sum"],
                "noref" => $p["pp"],
                "inn" => $p["inn"],
                "description" => $p["comment"],
                "from" => [
                    "bik" => $p["bik"],
                    "account" => $p["account"],
                    "a2" => $p["a2"]
                ],
                "company" => $p["payer"]
            ];
        }
        return $r;
    }


    function newaccounts_pi_process($fixclient)
    {
        global $design, $db;

        $design->assign('dbg', isset($_REQUEST['dbg']));
        $file = get_param_protected('file');

        $file = str_replace(['/', "\\"], ['', ''], $file);

        if (substr($file, 0, 11) == "sber_online") {
            return $this->importSberOnline($file);
        }

        $matches = [];
        $page = null;
        if (preg_match('/(.+\-\d{4}.txt)(p(\d+))?/', $file, $matches)) {
            $file = $matches[1];
            $page = $matches[3];
        }

        if (!file_exists(PAYMENTS_FILES_PATH . $file)) {
            //trigger_error2('Файл не существует');
            return;
        }

        if (substr($file, 0, 4) == "citi") {
            //, array("40702810700320000882","40702810038110015462","301422002")
            return $this->importPL_citibank(PAYMENTS_FILES_PATH . $file);
        } else {
            [$type, $payAccs, $payments] = PaymentParser::Parse(PAYMENTS_FILES_PATH . $file, $page, self::PI_IMPORT_RECPERPAGE);

            if (isset($_GET['check_payments']) && $_GET['check_payments']) {
                $this->_checkPaymenets($payments);
            }

            return $this->importPL_citibank(PAYMENTS_FILES_PATH . $file, $payAccs,
                $this->restructPayments($payAccs, $payments));
        }
    }

    private function _checkPaymenets($pays)
    {
        foreach ($pays as $pay) {
            $paymentId = Payment::find()->where([
                'payment_no' => $pay['pp'],
                'oper_date' => DateTime::createFromFormat(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED, $pay['oper_date_out'] ?: $pay['oper_date'] ?: $pay['date_dot'])->format(DateTimeZoneHelper::DATE_FORMAT),
                'sum' => $pay['sum'],
            ])->select('id')->scalar();

            if ($paymentId) {
                if (!\app\models\PaymentInfo::find()->where(['payment_id' => $paymentId])->exists()) {
                    $info = new \app\models\PaymentInfo();
                    $info->payment_id = $paymentId;

                    $info->payer = $pay['payer'];
                    $info->payer_inn = $pay['inn'];
                    $info->payer_bik = $pay['bik'];
                    $info->payer_bank = $pay['a2'];
                    $info->payer_account = $pay['account'];

                    $info->getter = $pay['geter'];
                    $info->getter_inn = $pay['geter_inn'];
                    $info->getter_bik = $pay['geter_bik'];
                    $info->getter_bank = $pay['geter_bank'];
                    $info->getter_account = $pay['geter_acc'];
                    $info->comment = $pay['comment'];

                    if (!$info->save()) {
                        throw new ModelValidationException($info);
                    }
                }
            }

        }
    }

    private function _savePaymentInfo(Payment $payment, $file)
    {
        static $c = [];

        if (!isset($c[$file])) {
            [$type, $payAccs, $payments] = PaymentParser::Parse($file);

            $c[$file] = $payments;
        } else {
            $payments = $c[$file];
        }

        $ps = array_filter($payments, function($p) use ($payment) {
            return $p['pp'] ==  $payment->payment_no
                && DateTime::createFromFormat(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED, $p['oper_date_out'] ?: $p['oper_date'] ?: $p['date_dot'])->format(DateTimeZoneHelper::DATE_FORMAT) == $payment->oper_date
                && $p['sum'] == $payment->sum
                ;
        });

        $this->_checkPaymenets($ps);


    }


    function newaccounts_pi_apply($fixclient)
    {
        global $design, $db, $user;
        $file = get_param_raw('file');

        if (strpos($file, "ural") !== false) {
            $bank = "ural";
        } elseif (strpos($file, "citi") !== false) {
            $bank = "citi";
        } elseif (strpos($file, "sber") !== false) {
            $bank = "sber";
        } else {
            $bank = "mos";
        }

        $pay = get_param_raw('pay');
        $CL = [];

        //include_once INCLUDE_PATH.'1c_integration.php';
        //$cs = new \_1c\clientSyncer($db);
        $b = 0;
        foreach ($pay as $P) {
            if (isset($P['client']) && $P['client'] != '' && $P['bill_no']) {
                if ($client = $db->QuerySelectRow('clients', ['client' => $P['client']])) {

                    $bill = $db->QuerySelectRow("newbills", ["bill_no" => $P["bill_no"]]);

                    if ($bill["client_id"] != $client["id"]) {
                        trigger_error2("Платеж #" . $P["pay"] . ", на сумму:" . $P["sum"] . " не внесен, проверте, что бы счет принадлежал этой компании");
                        continue;
                    }

                    $CL[$client['id']] = $client['currency'];

                    $b = 1;
                    $r2 = $db->GetRow('SELECT currency FROM newbills WHERE bill_no="' . $P['bill_no'] . '"');
                    if (isset($r2['currency']) && $r2['currency'] != 'RUB') {
                        $b = 0;
                    }

                    if ($b) {
                        $transaction = \Yii::$app->db->beginTransaction();
                        $payment = new Payment();
                        $payment->client_id = $client['id'];
                        $payment->payment_no = $P['pay'];
                        $payment->bill_no = $P['bill_no'];
                        $payment->bill_vis_no = $P['bill_no'];
                        $payment->payment_date = $P['date'];
                        $payment->oper_date = isset($P['oper_date']) ? $P['oper_date'] : $P['date'];
                        $payment->sum = $P['sum'];
                        $payment->currency = 'RUB';
                        $payment->payment_rate = 1;
                        $payment->original_sum = $P['sum'];
                        $payment->original_currency = 'RUB';
                        $payment->comment = $P['comment'];
                        $payment->add_date = date('Y-m-d H:i:s');
                        $payment->add_user = $user->Get('id');
                        $payment->type = 'bank';
                        $payment->bank = $bank;
                        isset($P['is_need_check']) && $P['is_need_check'] && $payment->isNeedToSendAtol = true;
                        $payment->save();

                        $this->_savePaymentInfo($payment, $file);
                        $transaction->commit();

                    }
                    if ($b) {
                        echo '<br>Платеж ' . $P['pay'] . ' клиента ' . $client['client'] . ' внесён';
                    } else {
                        echo '<br>Платеж ' . $P['pay'] . ' клиента ' . $client['client'] . ' не внесён, так как на ' . $P['date'] . ' отсутствует курс доллара';
                    }
                }
            } elseif (isset($P['is_need_check']) && isset($P['payment_id']) && $P['payment_id'] && $P['sum'] > 0) {
                \app\modules\atol\behaviors\SendToOnlineCashRegister::addEvent($P['payment_id'], true);
            }
        }

        trigger_error2("Баланс обновлён");
        if ($b && $design->ProcessEx('errors.tpl')) {
            header('Location: ?module=newaccounts&action=pi_process&file=' . $file);
            exit();
        } else {
            return $this->newaccounts_pi_process($fixclient);
        }
    }

    function newaccounts_balance_bill($fixclient)
    {
        global $design, $db;
        $design->assign('b_nedopay', $nedopay = get_param_protected('b_nedopay', 0));
        $design->assign('p_nedopay', $p_nedopay = get_param_protected('p_nedopay', 1));
        $design->assign('manager', $manager = get_param_protected('manager'));
        $design->assign('b_pay0', ($b_pay0 = get_param_protected('b_pay0', 0)));
        $design->assign('b_pay1', $b_pay1 = get_param_protected('b_pay1', 0));
        $design->assign('b_show_bonus', $b_show_bonus = get_param_protected('b_show_bonus', 0));
        $design->assign('user_type', $userType = get_param_protected('user_type', 'manager'));

        $design->assign("report_by", $reportBy = get_param_protected("report_by", "bill_created"));

        $dateFrom = new DatePickerValues('date_from', 'first');
        $dateTo = new DatePickerValues('date_to', 'last');
        $dateFrom->format = 'Y-m-d 00:00:00';
        $dateTo->format = 'Y-m-d 23:59:59';
        $date_from = $dateFrom->getDay();
        $date_to = $dateTo->getDay();

        $design->assign("l_status", $lStatus = [
            "work" => "Включенные",
            "income" => "Входящие",
            "once" => "Разовые",
            "closed" => "Отключенные"
        ]);

        $design->assign('cl_status', $cl_status = get_param_protected('cl_status', []));

        $managerInfo = $db->QuerySelectRow("user_users", ["user" => $manager]);

        if ($managerInfo["usergroup"] == "account_managers") {
            $managerInfo["usergroup"] = "manager";
        }

        $newpayments_join = '';

        if ($manager && ($b_pay0 || $b_pay1 || $nedopay)) {

            $W1 = ["and", "~~where_owner~~"];

            $W1[] = 'newbills.sum > 0';

            if ($cl_status) {
                $W1[] = 'status in ("' . implode('", "', $cl_status) . '")';
            }

            if ($reportBy == "bill_created") {
                $W1[] = 'newbills.bill_date >= "' . $date_from . '"';
                $W1[] = 'newbills.bill_date <= "' . $date_to . '"';
            } else { // report_by == bill_closed
                $W1[] = 'trouble_stage.date_start between "' . $date_from . '" and "' . $date_to . '"';
                $W1[] = 'trouble_stage.state_id = 20'; //closed
            }

            if (!$b_pay0 || !$b_pay1) {
                if ($b_pay0) {
                    $W1[] = 'newbills.is_payed IN (0,2)';
                }
                if ($b_pay1) {
                    $W1[] = 'newbills.is_payed = 1';
                }
            }
            if ($nedopay) {
                $W1[] = "newbills.is_payed IN (0,2)";
                $newpayments_join .= "
                inner join newpayments on newpayments.bill_no = newbills.bill_no
                and
                newbills.`sum` - (select sum(p.sum) from newpayments p where bill_no=newbills.bill_no) >= " . ((float)$p_nedopay) . "
                    ";
            }

            $W1[] = '( newbills.bill_date >= newsaldo.ts OR newsaldo.ts IS NULL)';


            $sql = '
            SELECT
            newbills.*,
            c.nal,
            c.client,
            cg.name AS company,
            cr.organization_id AS firma,
            if(c.currency = newbills.currency, 1,0) AS f_currency,
                    cr.manager AS client_manager,
                    (SELECT user FROM user_users WHERE id=nbo.owner_id) AS bill_manager' . ($b_show_bonus ? ',

                    (SELECT group_concat(concat("#",code_1c, " ",bl.type, if(b.type is null, " -- ", concat(": (", `value`, b.type,") ", bl.sum, " => ", round(if(b.type = "%",bl.sum*0.01*`value`, `value`*amount),2)))) ORDER BY bl.`code_1c` separator "|\n")  FROM newbill_lines bl
                     left join g_bonus b on b.good_id = bl.item_id and `group` = "' . $managerInfo["usergroup"] . '"
                     where bl.bill_no=newbills.bill_no) bonus_info ,
                    (SELECT sum(round(if(b.type = "%",bl.sum*0.01*`value`, `value`*amount),2)) FROM newbill_lines bl
                     left join g_bonus b on b.good_id = bl.item_id and `group` = "' . $managerInfo["usergroup"] . '"
                     where bl.bill_no=newbills.bill_no) bonus' : '') . '
                        FROM
                        newbills ' .

                ($reportBy == "bill_closed" ? '
                        inner join tt_troubles trouble using (bill_no)
                        inner join tt_stages trouble_stage on (trouble.cur_stage_id = trouble_stage.stage_id)
                        ' : '') . '

                        LEFT JOIN newbill_owner nbo ON (nbo.bill_no = newbills.bill_no)
                        ' . $newpayments_join . '
                        LEFT JOIN clients c ON c.id = newbills.client_id
                         LEFT JOIN `client_contract` cr ON cr.id=c.contract_id
                         LEFT JOIN `client_contragent` cg ON cg.id=cr.contragent_id
                        LEFT JOIN newsaldo ON newsaldo.client_id = c.id
                        AND newsaldo.is_history = 0
                        AND newsaldo.currency = c.currency
                        WHERE ' . MySQLDatabase::Generate($W1) . '

                        ';

            if ($userType == "manager") {
                $sql = str_replace("~~where_owner~~", 'cr.manager="' . $manager . '"', $sql);
            } else {

                if ($userType == "creator") {
                    $sql = str_replace("~~where_owner~~", 'nbo.owner_id="' . $managerInfo["id"] . '"', $sql);
                } else {
                    $sql = str_replace("~~where_owner~~",
                        'cr.manager="' . $manager . '" or nbo.owner_id="' . $managerInfo["id"] . '"', $sql);
                }
            }

            $sql .= "    order by client, bill_no";

            $R = $db->AllRecords($sql);

            $totalAmount = [];
            $totalBonus = [];

            $organizations = Organization::find()->actual()->all();
            $organizations = \yii\helpers\ArrayHelper::map($organizations, 'organization_id', 'firma');

            $clients = [];
            foreach ($R as &$r) {
                $r['firma'] = $organizations[$r['firma']];

                $clients[$r['client_id']] = 1;
                if ($r['sum']) {
                    if (!isset($totalAmount[$r['currency']])) {
                        $totalAmount[$r['currency']] = 0;
                    }
                    $totalAmount[$r['currency']] += $r['sum'];
                }
                if (isset($r["bonus"]) && $r['bonus']) {
                    if (!isset($totalBonus[$r['currency']])) {
                        $totalBonus[$r['currency']] = 0;
                    }
                    $totalBonus[$r['currency']] += $r['bonus'];
                }
            }
            $design->assign('clients_count', count($clients));
            $design->assign('bills', $R);
            $design->assign('totalAmount', $totalAmount);
            $design->assign('totalBonus', $totalBonus);
        }

        $R = User::dao()->getListByDepartments(['manager', 'marketing']);
        if (($managerInArray = array_search($manager, array_column($R, 'user'), $strict = true)) !== false) {
            $R[$managerInArray]['selected'] = ' selected';
        }
        $design->assign('users_manager', $R);
        $design->assign('action', $_GET["action"]);
        $design->AddMain('newaccounts/balance_bill.tpl');
    }

    function GetDebt($clientId)
    {

        static $mdb = [];

        if (isset($mdb[$clientId])) {
            return $mdb[$clientId];
        }

        global $user, $db;

        $fixclient_data = ClientAccount::findOne($clientId);

        // saldo
        $sum = [
            'USD' => ['delta' => 0, 'bill' => 0, 'ts' => ''],
            'RUB' => ['delta' => 0, 'bill' => 0, 'ts' => '']
        ];
        $r = $db->GetRow('SELECT * FROM newsaldo WHERE client_id=' . $fixclient_data['id'] . ' AND currency="' . $fixclient_data['currency'] . '" AND is_history=0 ORDER BY id DESC LIMIT 1');
        if ($r) {
            $sum[$fixclient_data['currency']] = [
                'delta' => 0,
                'bill' => $r['saldo'],
                'ts' => $r['ts'],
                'saldo' => $r['saldo']
            ];
        } else {
            $sum[$fixclient_data['currency']] = ['delta' => 0, 'bill' => 0, 'ts' => ''];
        }
        $R1 = $db->AllRecords('select *,' . ($sum[$fixclient_data['currency']]['ts'] ? 'IF(bill_date>="' . $sum[$fixclient_data['currency']]['ts'] . '",1,0)' : '1') . ' as in_sum from newbills where client_id=' . $fixclient_data['id'] . ' order by bill_no desc');
        $R2 = $db->AllRecords('select P.*,U.user as user_name,' . ($sum[$fixclient_data['currency']]['ts'] ? 'IF(P.payment_date>="' . $sum[$fixclient_data['currency']]['ts'] . '",1,0)' : '1') . ' as in_sum from newpayments as P LEFT JOIN user_users as U on U.id=P.add_user where P.client_id=' . $fixclient_data['id'] . ' order by P.payment_date desc');

        foreach ($R1 as $r) {
            $delta = -$r['sum'];
            foreach ($R2 as $k2 => $r2) {
                if ($r['bill_no'] == $r2['bill_no']) {
                    $delta += $r2['sum'];
                    unset($R2[$k2]);
                }
            }
            if ($r['in_sum']) {
                $sum[$r['currency']]['bill'] += $r['sum'];
                $sum[$r['currency']]['delta'] -= $delta;
            }
        }
        foreach ($R2 as $r2) {
            if ($r2['in_sum']) {
                $sum[$fixclient_data['currency']]['delta'] -= $r2['sum'];
            }
        }
        $mdb[$clientId] = [
            "sum" => $sum[$fixclient_data["currency"]]["delta"],
            "currency" => $fixclient_data["currency"]
        ];
        return $mdb[$clientId];
    }

    function newaccounts_debt_report($fixclient)
    {
        global $design, $db;

        $design->assign("l_couriers",
            ["all" => "--- Все ---", "checked" => "--- Установленные --"] + Courier::getList($isWithEmpty = false));
        $design->assign("l_metro", ["all" => "--- Все ---"] + \app\models\Metro::getList());
        $design->assign('courier', $courier = get_param_protected('courier', "all"));
        $design->assign('metro', $metro = get_param_protected('metro', "all"));
        $design->assign('manager', $manager = get_param_protected('manager'));
        $design->assign('cl_off', $cl_off = get_param_protected('cl_off'));
        $design->assign('zerobills', 1);
        $dateFrom = new DatePickerValues('date_from', 'first');
        $dateTo = new DatePickerValues('date_to', 'last');
        $dateFrom->format = 'Y-m-d';
        $dateTo->format = 'Y-m-d';
        $date_from = $dateFrom->getDay();
        $date_to = $dateTo->getDay();


        $zerobill = get_param_integer('zerobills', 0);

        if (get_param_raw("save", 0) == 1) {
            $s_obj = get_param_protected("obj");
            $s_value = get_param_protected("value");
            $s_billNo = get_param_protected("bill_no");

            if ($s_obj && ($s_value || $s_value == 0) && $s_billNo) {
                $oBill = Bill::findOne(['bill_no' => $s_billNo]);
                if ($oBill) {
                    if ($s_obj == "nal" && in_array($s_value, ["nal", "beznal", "prov"])) {
                        $oBill->nal = $s_value;

                    } elseif ($s_obj == "courier") {
                        $oBill->courier_id = $s_value;
                    } elseif ($s_obj == "comment") {
                        $v = [
                            "bill_no" => $s_billNo,
                            "ts" => ['NOW()'],
                            "user_id" => $this->GetUserId(),
                            "comment" => $s_value
                        ];
                        $R = $db->AllRecords("SELECT id FROM log_newbills_static WHERE bill_no = '" . $s_billNo . "'");
                        if ($R) {
                            $db->QueryUpdate("log_newbills_static", 'bill_no', $v);
                        } else {
                            $db->QueryInsert("log_newbills_static", $v);
                        }
                    }
                    $oBill->save();
                }
            }
        }
        $nal = [];
        foreach (get_param_protected("nal", []) as $n) {
            $nal[$n] = 1;
        }
        $design->assign("nal", $nal);

        $isPrint = get_param_raw("print", 0) == 1;
        if (get_param_raw("go", false) && !empty($nal)) {
            $getURL = "";
            foreach ($_GET as $k => $v) {
                if (!is_array($v)) {
                    $getURL .= ($getURL ? "&" : "?") . $k . "=" . urlencode($v);
                } else {
                    foreach ($v as $l) {
                        $getURL .= "&" . $k . "[]=" . $l;
                    }
                }
            }

            $design->assign("get_url", $getURL);

            $W1 = ["and"];
            if ($manager != "all") {
                $W1[] = 'cr.manager="' . $manager . '"';
            }
            if ($courier != "all") {
                if ($courier == 'checked') {
                    $W1[] = "courier_id > 0";
                } else {
                    $W1[] = "courier_id = '" . $courier . "'";
                }
            }
            if ($metro != "all") {
                $W1[] = "metro_id = '" . $metro . "'";
            }
            $W1[] = "newbills.nal in ('" . implode("','", array_keys($nal)) . "')";
            $W1[] = 'is_payed in (0,2)';

            if (!$cl_off) {
                $W1[] = 'status="work"';
            }
            if ($date_from) {
                $W1[] = 'newbills.bill_date>="' . $date_from . '"';
            }
            if ($date_to) {
                $W1[] = 'newbills.bill_date<="' . $date_to . '"';
            }
            if ($zerobill) {
                $W1[] = 'newbills.sum<>0';
            }

            $q = '
                SELECT
                    a.*,
                    ls.ts AS date,
                    u.user,
                    ls.comment
                FROM
                    (
                        SELECT
                            `bill_no`,
                            `bill_date`,
                            `newbills`.`client_id`,
                            `newbills`.`currency`,
                            `sum`,
                            `is_payed`,
                            `inv2to1`,
                            `courier_id`,
                            c.nal,
                            c.metro_id,
                            c.address_post AS address,
                            newbills.nal AS bill_nal,
                            c.client,
                            cg.name AS company,
                            cr.manager
                        FROM newbills
                        LEFT JOIN clients c ON c.id=newbills.client_id
                         LEFT JOIN `client_contract` cr ON cr.id=c.contract_id
                         LEFT JOIN `client_contragent` cg ON cg.id=cr.contragent_id
                        LEFT JOIN newsaldo ON newsaldo.client_id=c.id
                            AND newsaldo.is_history=0 AND newsaldo.currency=c.currency
                        WHERE
                            ' . MySQLDatabase::Generate($W1) . '
                            AND c.status NOT IN ("tech_deny","deny")
                        ORDER BY
                            ' . ($isPrint ? "cg.name, " : "") . 'client,
                            bill_no
                    ) a
                LEFT JOIN log_newbills_static ls USING (bill_no)
                LEFT JOIN user_users u ON u.id = ls.user_id
                ORDER BY a.client_id
            ';

            $R = $db->AllRecords($q);

            $totalAmount = [];
            $totalSaldo = [];

            foreach ($R as &$r) {
                if ($isPrint) {
                    $r["metro"] = \app\models\Metro::getList()[$r["metro_id"]];
                }
                $r["debt"] = $this->GetDebt($r["client_id"]);
                $r["courier"] = Courier::dao()->getNameById($r["courier_id"]);
                if ($r['sum']) {
                    if (!isset($totalAmount[$r['currency']])) {
                        $totalAmount[$r['currency']] = 0;
                    }
                    $totalAmount[$r['currency']] += $r['sum'];
                }
                if ($r["debt"]["sum"]) {
                    if (!isset($totalSaldo[$r["debt"]["currency"]])) {
                        $totalSaldo[$r["debt"]["currency"]] = 0;
                    }
                    $totalSaldo[$r["debt"]["currency"]] += $r["debt"]["sum"];
                }
            }
            $design->assign('bills', $R);
            $design->assign('totalAmount', $totalAmount);
            $design->assign('totalSaldo', $totalSaldo);
        }
        $m = User::dao()->getListByDepartments('manager');
        $R = ["all" => ["name" => "Все", "user" => "all"]];
        foreach ($m as $user => $userData) {
            $R[$user] = $userData;
        }
        if (isset($R[$manager])) {
            $R[$manager]['selected'] = ' selected';
        }
        $design->assign('users_manager', $R);
        $design->assign("isPrint", $isPrint);
        if ($isPrint) {
            $design->ProcessEx('newaccounts/debt_report_print.tpl');
        } else {
            $design->AddMain('newaccounts/debt_report.tpl');
        }
    }

    function getUserId()
    {
        global $user;

        return $user->Get("id");
    }

    function newaccounts_search($fixclient)
    {
        global $db, $design;

        $search = get_param_protected('search');
        $search = trim($search);
        if ($search) {

            $R = $db->AllRecords(
                'SELECT newbills.*,clients.nal,clients.client,cg.name AS company
                    FROM newbills
                    INNER JOIN clients c ON (clients.id=newbills.client_id)
                     INNER JOIN `client_contract` cr ON cr.id=c.contract_id
                     INNER JOIN `client_contragent` cg ON cg.id=cr.contragent_id
                    WHERE bill_no LIKE "' . $search . '%" OR bill_no_ext LIKE "' . $search . '%" ORDER BY client,bill_no LIMIT 1000');

            !$R && $R = $db->AllRecords(
                $q = 'SELECT b.*,c.nal,c.client,cg.name AS company
                        FROM newbills b, newbills_external e, clients c
                         INNER JOIN `client_contract` cr ON cr.id=c.contract_id
                         INNER JOIN `client_contragent` cg ON cg.id=cr.contragent_id
                        WHERE c.id=b.client_id 
                        AND b.bill_no=e.bill_no
                        AND (
                             e.ext_bill_no = "' . $search . '"
                          OR e.ext_akt_no = "' . $search . '"
                          OR e.ext_invoice_no = "' . $search . '"
                        )
                        ORDER BY c.client, b.bill_no LIMIT 1000');

            !$R && $R = $db->AllRecords(
                $q = 'SELECT b.*,c.nal,c.client,cg.name AS company
                        FROM newbills b, newbills_add_info i, clients c
                         INNER JOIN `client_contract` cr ON cr.id=c.contract_id
                         INNER JOIN `client_contragent` cg ON cg.id=cr.contragent_id
                        WHERE c.id=b.client_id
                        AND b.bill_no = i.bill_no AND i.req_no = "' . $search . '"
                        ORDER BY c.client, b.bill_no LIMIT 1000');

            if (count($R) == 1000) {
                trigger_error2('Ограничьте условия поиска. Показаны первые 1000 вариантов');
            }
            if (count($R) == 1) {
                header("Location: ./?module=newaccounts&action=bill_view&bill=" . $R[0]["bill_no"]);
                exit();
            }
            $design->assign('bills', $R);
            $design->assign('search', $search);
        }
        $design->AddMain('newaccounts/search.tpl');

    }

    function newaccounts_balance_client($fixclient)
    {
        global $design, $db;
        $design->assign('manager', $manager = get_param_protected('manager'));
        $design->assign('cl_off', $cl_off = get_param_protected('cl_off'));
        $design->assign('sort', $sort = get_param_protected('sort'));

        if ($manager) {
            $W0 = ['AND'];
            if (!$cl_off) {
                $W0[] = 'clients.status="work"';
            }
            if ($manager != '()') {
                $W0[] = 'cr.manager="' . $manager . '"';
            }

            $W1 = ['AND', 'newbills.client_id=clients.id'];
            $W1[] = [
                'AND',
                'newbills.currency=clients.currency',
                'saldo_ts IS NULL OR newbills.bill_date>=saldo_ts'
            ];

            $W2 = ['AND', 'P.client_id=clients.id'];
            $W2[] = [
                'OR',
                [
                    'AND',
                    'newbills.bill_no IS NULL',
                    [
                        'OR',
                        'saldo_ts IS NULL',
                        'P.payment_date>=saldo_ts',
                    ]
                ],
                [
                    'AND',
                    'newbills.currency=clients.currency',
                    [
                        'OR',
                        'saldo_ts IS NULL',
                        'newbills.bill_date>=saldo_ts',
                    ]
                ],
            ];
            /*
            $W2 = array('AND','P.client_id=clients.id', array('OR',
                            'saldo_ts IS NULL',
                            'P.payment_date>=saldo_ts',
                        ));
                        */

            $S = ['client', 'client', 'sum_payments'];
            if (!isset($S[$sort])) {
                $sort = 0;
            }
            $sortK = $S[$sort];

            $balances = $db->AllRecords('select' .
                ' cr.*, cg.*, clients.*, clients.client as client_orig, cg.name AS company, cg.name_full AS company_full, cg.legal_type AS type, cr.organization_id AS firma,
cg.position AS signer_position, cg.fio AS signer_fio, cg.positionV AS signer_positionV, cg.fioV AS signer_fioV, cg.legal_type AS type ' .
                ', (select ts from newsaldo where client_id=clients.id and newsaldo.is_history=0 and newsaldo.currency=clients.currency order by id desc limit 1) as saldo_ts' .
                ', (select saldo from newsaldo where client_id=clients.id and newsaldo.is_history=0 and newsaldo.currency=clients.currency order by id desc limit 1) as saldo_sum' .
                ', (select sum(`sum`) from newbills where ' . MySQLDatabase::Generate($W1) . ') as sum_bills' .
                ', (select sum(P.sum) from newpayments as P LEFT JOIN newbills ON newbills.bill_no=P.bill_no and P.client_id = newbills.client_id where ' . MySQLDatabase::Generate($W2) . ') as sum_payments' .
                ', (select bill_date from newbills where ' . MySQLDatabase::Generate($W1) . ' order by bill_date desc limit 1) as lastbill_date' .
                ', (select bill_no from newbills where ' . MySQLDatabase::Generate($W1) . ' order by bill_date desc limit 1) as lastbill_no' .
                ', (select round(`sum`) from newbills where ' . MySQLDatabase::Generate($W1) . ' order by bill_date desc limit 1) as lastbill_sum' .
                ' from clients ' .
                "  INNER JOIN `client_contract` cr ON cr.id=clients.contract_id" .
                "  INNER JOIN `client_contragent` cg ON cg.id=cr.contragent_id" .
                ' WHERE ' . MySQLDatabase::Generate($W0) . ' HAVING lastbill_date IS NOT NULL ORDER by ' . $sortK);
            if ($sort == 1) {
                usort($balances, create_function('$a,$b', '$p=$a["saldo_sum"]+$a["sum_bills"]-$a["sum_payments"]; $q=$b["saldo_sum"]+$b["sum_bills"]-$b["sum_payments"];
                                                        if ($p==$q) return 0; else if ($p>$q) return 1; else return -1;'));
            }

            $date = date('Y-m-d');
            $organizations = Organization::find()->actual()->all();
            $organizations = \yii\helpers\ArrayHelper::map($organizations, 'organization_id', 'firma');

            foreach ($balances as &$balance) {
                $balance['firma'] = $organizations[$balance['firma']];
            }

            $design->assign('balance', $balances);
        }
        $R = User::dao()->getListByDepartments('manager');
        if (isset($R[$manager])) {
            $R[$manager]['selected'] = ' selected';
        }
        $design->assign('users_manager', $R);
        $design->AddMain('newaccounts/balance_client.tpl');
    }

    function newaccounts_balance_check($fixclient)
    {
        global $design, $db, $user, $fixclient_data;

        if (!$fixclient) {
            trigger_error2('Выберите клиента');
            return;
        }

        $dateFrom = new DatePickerValues('date_from', 'first');
        $dateTo = new DatePickerValues('date_to', 'last');
        $dateFrom->format = 'Y-m-d';
        $dateTo->format = 'Y-m-d';
        $date_from = $dateFrom->getDay();
        $date_to = $dateTo->getDay();

        /** @var ClientAccount $clientData */
        $clientData = ClientAccount::findOne(['id' => $fixclient_data['id']])
            ->loadVersionOnDate($date_from);

        //** Todo:  */
        $organization = Organization::find()->byId($clientData->contract->organization_id)->actual($date_to)->one();
        $design->assign('firma', $organization->getOldModeInfo());
        $design->assign('firm_director', $organization->director->getOldModeInfo());
        $design->assign('firm_buh', $organization->accountant->getOldModeInfo());
        //** Todo:  */

        $saldo = $db->GetRow('SELECT * FROM newsaldo WHERE client_id="' . $fixclient_data['id'] . '" AND newsaldo.is_history=0 ORDER BY id');
        $design->assign('saldo', $startsaldo = floatval(get_param_protected('saldo', 0)));
        $design->assign('date_from_val', $date_from_val = strtotime($date_from));
        $design->assign('date_to_val', $date_to_val = strtotime($date_to));
        $R = [];
        $Rc = 0;
        $S_p = 0;
        $S_b = 0;

        $R[0] = ['type' => 'saldo', 'date' => $date_from_val, 'sum_outcome' => $startsaldo];
        $B = [];

        $W = ['AND', 'P.client_id="' . $fixclient_data['id'] . '"', 'P.currency="' . $clientData->currency . '"'];
        if ($saldo) {
            $W[] = 'P.payment_date>="' . $saldo['ts'] . '"';
        }
        if ($date_from) {
            $W[] = 'P.payment_date>="' . $date_from . '"';
        }
        if ($date_to) {
            $W[] = 'P.payment_date<="' . $date_to . '"';
        }

        $P = $db->AllRecords($sql = '
                SELECT P.*,
                    UNIX_TIMESTAMP(P.payment_date) AS payment_date
                FROM newpayments AS P
                WHERE ' . MySQLDatabase::Generate($W) . '
                ORDER BY P.id');


        foreach ($P as &$A) {

            $sum_outcome = $A['sum'];
            if ($sum_outcome < 0) {
                $sum_income = -$sum_outcome;
                $sum_outcome = 0;
                $S_b += $sum_income;
            } else {
                $sum_income = 0;
                $S_p += $sum_outcome;
            }

            $R[$A['payment_date'] + ($Rc++)] =
                [
                    'type' => $A['type'] == Payment::TYPE_CREDITNOTE ? $A['type'] : 'pay',
                    'date' => $A['payment_date'],
                    'sum_outcome' => $sum_outcome,
                    'sum_income' => $sum_income,
                    'pay_no' => $A['payment_no'],
                    'bill_no' => $A['bill_no'],
                ];
            $B[$A['bill_no']] = 1;
        }
        unset($A);

        $W = [
            'AND',
            'newbills.client_id="' . $fixclient_data['id'] . '"',
            'newbills.currency="' . $fixclient_data['currency'] . '"'
        ];
        if ($saldo) {
            $W[] = 'newbills.bill_date>="' . $saldo['ts'] . '"';
        }
        if ($date_from) {
            $W[] = 'newbills.bill_date>="' . $date_from . '"-INTERVAL 1 MONTH';
        }
        if ($date_to) {
            $W[] = 'newbills.bill_date<="' . $date_to . '"+INTERVAL 1 MONTH';
        }
        $P = $db->AllRecords($q = '
                SELECT newbills.*,ext.ext_akt_no, ext.ext_akt_date,
                    ifnull((SELECT if(state_id = 21 , 1, 0)
                        FROM tt_troubles t, tt_stages s
                        WHERE t.bill_no = newbills.bill_no AND s.stage_id = t.cur_stage_id LIMIT 1), 0) AS is_rejected
                FROM newbills
                LEFT JOIN newbills_external ext USING (bill_no)
                WHERE ' . MySQLDatabase::Generate($W) . '
                HAVING is_rejected = 0
                ORDER BY newbills.bill_no
        ');

        $zalog = [];
        $S_zalog = 0;

        foreach ($P as &$p) {
            $bill = new \Bill($p['bill_no']);
            $A1 = null;
            for ($I = 1; $I <= 4; $I++) {
                $A = $this->do_print_prepare($bill, $I == 4 ? 'lading' : 'akt', $I == 4 ? null : $I, 'RUB', 0);
                if ($I == 1) {
                    $A1 = $A;
                }
                if ($I == 4 && $A['bill']) {
                    $A['inv_date'] = ($A1) ? $A1['inv_date'] : $A['inv_date'];
                    $A['inv_no'] = $A['bill']['bill_no'];
                }
                if ($I != 3 && is_array($A) && $A['bill']['sum']) {
                    $k = date('Y-m-d', $A['inv_date']);
                    if (
                        (!$date_from || $k >= $date_from)
                        &&
                        (!$date_to || $k <= $date_to)
                    ) {
                        $isNegativeSum = false;
                        if ($A['bill']['sum'] < 0) {
                            $isNegativeSum = true;
                            $A['bill']['sum'] = -$A['bill']['sum'];
                        }
                        $sum_in = $A["bill"]["is_rollback"] || $isNegativeSum ? 0 : $A['bill']['sum'];
                        $sum_out = $A["bill"]["is_rollback"] || $isNegativeSum ? $A['bill']['sum'] : 0;

                        $invoice = null;

                        $invNo = $A['inv_no'];
                        $invDate = $A['inv_date'];

                        if ($p['ext_akt_no']) {
                            $invNo = $p['ext_akt_no'];

                            if ($p['ext_akt_date']) {
                                $invDateObj = new DateTime($p['ext_akt_date']);
                                $invDate = $invDateObj->getTimestamp();
                            }

                        } elseif ($bill->Get('bill_date') >= Invoice::DATE_ACCOUNTING) {
                            $invoice = Invoice::findOne([
                                'bill_no' => $A['bill']['bill_no'],
                                'type_id' => $I == 4 ? 3 : $I,
                                'is_reversal' => 0
                            ]);

                            $invNo = ($invoice ? $invoice->number : "***" . $A['inv_no'] . "***");
                            // $invoice && $invDate = $invoice->date;
                        }


                        $R[$A['inv_date'] + ($Rc++)] = [
                            'type' => 'inv',
                            'date' => $invDate,
                            'sum_income' => $sum_in,
                            'sum_outcome' => $sum_out,
                            'inv_no' => $invNo,
                            'bill_no' => $A['bill']['bill_no'],
                            'inv_num' => $I,
                        ];
                        if ($isNegativeSum) {
                            $S_p += $sum_out;
                        } else {
                            $S_b += $sum_in;
                        }
                    }
                }
            }
            unset($bill);
        }

        foreach ($db->AllRecords(
            "SELECT 'inv' AS type, 3 AS inv_num,
                b.bill_no, concat(b.bill_no,'-3') AS inv_no,
                unix_timestamp(bill_date) AS date,
                l.sum AS sum_income, item AS items, b.currency, b.sum AS b_sum
            FROM
                newbills b, newbill_lines l
            WHERE
                    b.bill_no = l.bill_no
                AND client_id = '" . $fixclient_data['id'] . "'
                AND type='zalog'
                AND b.bill_date<='" . $date_to . "'") as $z) {
            $zalog[$z["date"] . "-" . count($zalog)] = $z;
            $S_zalog += $z["sum_income"];
        }

        ksort($R);
        //tabledbg($R);
        $R[0]['sum_income'] = $startsaldo < 0 ? -$startsaldo : 0;
        $R[0]['sum_outcome'] = $startsaldo > 0 ? $startsaldo : 0;
        $S = $startsaldo + $S_p - $S_b;
        $R[] = ['type' => 'total', 'sum_outcome' => $S_p, 'sum_income' => $S_b];
        $R[] = $ressaldo = [
            'type' => 'saldo',
            'date' => $date_to_val,
            'sum_income' => $S > 0 ? 0 : -$S,
            'sum_outcome' => $S > 0 ? $S : 0
        ];

        $S -= $S_zalog;
        $formula = sprintf("%.2f", -$S) . "=" . sprintf("%.2f", $ressaldo["sum_income"]);
        foreach ($zalog as $z) {
            $formula .= ($z["sum_income"] > 0 ? "+" : "") . sprintf("%.2f", $z["sum_income"]);
        }

        $ressaldo = [
            'type' => 'saldo',
            'date' => $date_to_val,
            'sum_income' => $S > 0 ? 0 : -$S,
            'sum_outcome' => $S > 0 ? $S : 0
        ];


        $design->assign("company_full", $clientData->company_full);
        $design->assign("client_id", $clientData->id);

        $design->assign("last_contract", BillContract::getLastContract($clientData->contract_id, $date_from_val));
        $design->assign('data', $R);
        $design->assign('zalog', $zalog);
        $design->assign('sum_bill', $S_b);
        $design->assign('sum_pay', $S_p);
        $design->assign('sum_zalog', $S_zalog);
        $design->assign('ressaldo', $ressaldo);
        $design->assign('formula', $formula);
        $design->assign('currency', $clientData->currency);

        $fullscreen = get_param_protected('fullscreen', 0);
        $is_pdf = get_param_protected('is_pdf', 0);
        $sign = get_param_protected('sign', '');
        $design->assign('fullscreen', $fullscreen);
        $design->assign('is_pdf', $is_pdf);
        $design->assign('sign', $sign);

        $contragentLangCode = $clientData->contragent->lang_code === Language::LANGUAGE_RUSSIAN ? '' : '_en';

        if ($is_pdf == 1) {
            /*wkhtmltopdf*/
            $options = ' --quiet -L 10 -R 10 -T ' . get_param_protected('pdf_top_padding', 10) . ' -B 10';
            $content = $design->fetch("newaccounts/print_balance_check{$contragentLangCode}.tpl");
            $file_name = '/tmp/' . time() . $user->_Data['id'];
            $file_html = $file_name . '.html';
            $file_pdf = $file_name . '.pdf';

            file_put_contents($file_name . '.html', $content);

            exec("/usr/local/bin/wkhtmltopdf $options $file_html $file_pdf");
            $pdf = file_get_contents($file_pdf);

            //Create file
            $V = [
                'name' => str_replace(['"'], "",
                        $clientData["company_full"]) . ' ' . $clientData->id . ' Акт сверки (на ' . $date_to . ').pdf',
                'ts' => ['NOW()'],
                'contract_id' => $fixclient_data['contract_id'],
                'comment' => $clientData["company_full"] . ' ' . $clientData->id . ' Акт сверки (на ' . $date_to . ')',
                'user_id' => $user->Get('id')
            ];
            $id = $db->QueryInsert('client_files', $V);
            copy($file_pdf, STORE_PATH . 'files/' . $id);

            unlink($file_html);
            unlink($file_pdf);

            header('Content-Type: application/pdf');
            ob_clean();
            flush();
            echo $pdf;
            exit;
        }

        if ($fullscreen == 1) {
            $design->ProcessEx("newaccounts/print_balance_check{$contragentLangCode}.tpl");
            //$design->ProcessEx('pop_header.tpl');
            //$design->ProcessEx('errors.tpl');
            //$design->ProcessEx('newaccounts/balance_check.tpl');
            //$design->ProcessEx('pop_footer.tpl');
        } else {
            $design->AddMain("newaccounts/balance_check{$contragentLangCode}.tpl");
        }
    }

    function newaccounts_ext_bills($fixclient)
    {
        global $design, $db, $user, $fixclient_data;

        $periodType = get_param_raw('period_type', 'registration');
        $isBmd = (bool)get_param_raw('is_bmd', '0');

        $dateFromStr = get_param_raw('date_from', date('m-Y'));
        $dateToStr = get_param_raw('date_to', date('m-Y'));

        $dateFromExp = explode('-', $dateFromStr . '-00-00-00');
        $dateToExp = explode('-', $dateToStr . '-00-00-00');
        $dateFrom = (new DateTimeImmutable())->setDate($dateFromExp[1], $dateFromExp[0], 1)->setTime(0, 0, 0);
        $dateTo = (new DateTimeImmutable())->setDate($dateToExp[1], $dateToExp[0], 1)->setTime(0, 0, 0)->modify('+1 month')->modify('-1 day');

        $design->assign('date_from', $dateFrom->format('m-Y'));
        $design->assign('date_to', $dateTo->format('m-Y'));

        $design->assign('organizations', Organization::dao()->getList());
        $design->assign('organization_id', $organizationId = get_param_protected('organization_id', Organization::MCN_TELECOM));

        $design->assign('period_type_list', ['registration' => 'По дайте регистрации', 'ext_invoice_date' => 'По дате внешней с/ф']);
        $design->assign('period_type', $periodType);

        $design->assign('is_ext_invoice_only', $isExtInvoiceOnly = (bool)get_param_raw('is_ext_invoice_only', false));

        $where = "";

        $isExtInvoiceOnly && $where .= ' AND ex.ext_invoice_no IS NOT NULL AND ex.ext_invoice_no != ""';

        if ($periodType == 'registration') {
            $dateField = 'STR_TO_DATE(ex.ext_registration_date, \'%d-%m-%Y\')';
            $where .= ' AND ex.ext_registration_date IS NOT NULL AND ex.ext_registration_date != ""';
        }elseif ($periodType == 'ext_invoice_date'){
            $dateField = 'STR_TO_DATE(ex.ext_invoice_date, \'%d-%m-%Y\')';
            $where .= ' AND ex.ext_invoice_date IS NOT NULL AND ex.ext_invoice_date != ""';
        }

        $sql = "SELECT
  b.bill_no,
  STR_TO_DATE(ext_registration_date, '%d-%m-%Y')                  AS registration_date,
  c.id                                                            AS account_id,
  cg.name_full,
  cnt.name                                                        AS country_name,
  cg.country_id,
  cg.inn_euro,
  cg.inn,
  ext_invoice_no,
  STR_TO_DATE(ext_invoice_date, '%d-%m-%Y')                       AS invoice_date,
  pay_bill_until                                                  AS due_date,
  coalesce(ext_sum_without_vat, 0)                                AS sum_without_vat,
  coalesce(ext_vat, 0)                                            AS vat,
  (coalesce(ex.ext_vat, 0) + coalesce(ex.ext_sum_without_vat, 0)) AS sum,
  b.currency,
  cur_euro.rate                                                   AS euro_rate,
  cur_nat.rate                                                    AS nat_rate,
  bf.name                                                         AS file_name
FROM newbills b, clients c, client_contract cc, client_contragent cg, country cnt, newbills_external ex
  LEFT JOIN currency_rate cur_euro
    ON (STR_TO_DATE(ex.ext_invoice_date, '%d-%m-%Y') = cur_euro.date AND cur_euro.currency = 'EUR')
  LEFT JOIN currency_rate cur_nat ON (STR_TO_DATE(ex.ext_invoice_date, '%d-%m-%Y') = cur_nat.date)
  LEFT JOIN newbills_external_files bf ON bf.bill_no = ex.bill_no
WHERE " . $dateField . " BETWEEN :date_from AND :date_to
      AND b.bill_no = ex.bill_no
      AND cur_nat.currency = b.currency
      AND b.organization_id = :organization_id
      AND c.id = b.client_id AND cc.id = c.contract_id AND cc.contragent_id = cg.id
      AND cnt.code = cg.country_id
" . $where . "
ORDER BY STR_TO_DATE(ext_invoice_date, '%d-%m-%Y'), sum DESC";

        $query = \Yii::$app->db->createCommand($sql, [
                ':date_from' => $dateFrom->format(DateTimeZoneHelper::DATE_FORMAT),
                ':date_to' => $dateTo->format(DateTimeZoneHelper::DATE_FORMAT),
                ':organization_id' => $organizationId
            ]);

        $data = $query->queryAll();

        $total = $totalEuro = [];
        $total['EUR'] = ['sum' => 0, 'vat' => 0, 'sum_without_vat' => 0, 'count' => 0];

        $steuercodeMap = [
            '5701' => '2',
            '5755' => '79',
        ];


        foreach ($data as $idx => $row) {
            if ($isBmd) {
                $gkonto = $row['country_id'] == \app\models\Country::AUSTRIA
                    ? '5701'
                    : (in_array($row['country_id'], \app\models\Country::EUROPE) ? '5755' : '5756');

                $prozent = ($gkonto != '5756' ? 20 : 0);

                $row['vat'] = (float)$row['vat'];

                $tax = $row['vat'] ?: ($gkonto == '5755' && $prozent ? $row['sum_without_vat'] * ($prozent/100) : null);

                $bSum = $row['sum_without_vat'];
                if($gkonto == '5701') {
                    $bSum = $row['sum_without_vat']+$tax;

                    if ($tax) {
                        $tax = -abs($tax);
                    }
                }

                $b = [
                    'fwbetrag' => ($row['currency'] != Currency::EUR ? $this->nf(-$bSum) : ''),
                    'betrag' => ($row['currency'] == Currency::EUR ? $this->nf(-$bSum) : ''),
                    'fwsteuer' => ($row['currency'] != Currency::EUR && $tax? $this->nf(-$tax) : ''),
                    'steuer' => ($row['currency'] == Currency::EUR && $tax? $this->nf(-$tax) : ''),
                    'prozent' => $prozent,
                    'steuercode' => $steuercodeMap[$gkonto] ?? '',
                    'gkonto' => $gkonto,
                ];

                $data[$idx]['bmd'] = $b;
            }

            $rate = $row['nat_rate'] / $row['euro_rate'];

            $data[$idx]['sum_without_vat_euro'] = $row['sum_without_vat_euro'] = round($row['sum_without_vat'] * $rate, 2);
            $data[$idx]['vat_euro'] = $row['vat_euro'] = round($row['vat'] * $rate, 2);
            $data[$idx]['sum_euro'] = $row['sum_euro'] = round($row['sum'] * $rate, 2);
            $data[$idx]['rate'] = $row['rate'] = round($rate, 4);

            if (!isset($total[$row['currency']])) {
                $total[$row['currency']] = [
                    'sum' => 0,
                    'vat' => 0,
                    'sum_without_vat' => 0,
                    'bill_sum' => 0,
                    'count' => 0
                ];
            }

            $total[$row['currency']]['sum'] += $row['sum'];
            $total[$row['currency']]['vat'] += $row['vat'];
            $total[$row['currency']]['sum_without_vat'] += $row['sum_without_vat'];

            $totalEuro['sum_without_vat'] += $row['sum_without_vat_euro'];
            $totalEuro['vat'] += $row['vat_euro'];
            $totalEuro['sum'] += $row['sum_euro'];

//            $total[$row['currency']]['bill_sum'] += $row['bill_sum'];

            $total[$row['currency']]['count']++;
        }

        if (get_param_raw('is_to_excel_bmd', '0') == 1) {
            $excel = new \app\classes\excel\PurchaseBookToExcelBmd();
            $excel->data = $data;
            $excel->total = $total;
            $excel->organizationId = $organizationId;
            $excel->dateFrom = $dateFrom->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED);
            $excel->dateTo = $dateTo->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED);
            $excel->openFile(Yii::getAlias('@app/templates/purchase_book_bmd.xls'));
            $excel->prepare();
            $excel->download('Purchase_Book_BMD');
        } elseif (get_param_raw('is_to_excel', 0) == 1) {
            $excel = new \app\classes\excel\PurchaseBookToExcel;
            $excel->data = $data;
            $excel->total = $total;
            $excel->organizationId = $organizationId;
            $excel->dateFrom = $dateFrom->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED);
            $excel->dateTo = $dateTo->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED);
            $excel->openFile(Yii::getAlias('@app/templates/purchase_book.xls'));
            $excel->prepare();
            $excel->download('Европейская книга покупок');
        }

        $design->assign('data', $data);
        $design->assign('totals', $total);
        $design->assign('totalEuro', $totalEuro);
        $design->assign('is_bmd', $isBmd);
        $design->AddMain('newaccounts/ext_bills.tpl');
    }

    private function nf($number)
    {
        if ($number === null || $number === '') {
            return '';
        }

        if (!is_numeric($number)) {
            return $number;
        }

        return number_format($number, 2, '.', '');
    }


    function newaccounts_ext_bills_ifns($fixclient)
    {
        global $design, $db, $user, $fixclient_data;

        $dateFrom = new DatePickerValues('date_from', 'first');
        $dateTo = new DatePickerValues('date_to', 'last');
        $date_from = $dateFrom->getDay();
        $date_to = $dateTo->getDay();

        $design->assign('organizations', Organization::dao()->getList());
        $design->assign('organization_id', $organizationId = get_param_protected('organization_id', Organization::MCN_TELECOM));

        $design->assign('currencies', Currency::getList($isWithEmpty = true));
        $design->assign('currency', $currency = get_param_protected('currency', ''));

        $design->assign('filter', $filterOption = get_param_raw('filter'));

        $where = "";
        $whereParam = [];
        if ($currency) {
            $where .= ' AND b.currency = :currency';
            $whereParam = [':currency' => $currency];
        }

        if ($filterOption == 'dateRegistrationSf') {
            $dateField = 'STR_TO_DATE(ex.ext_registration_date, \'%d-%m-%Y\')';
            $where .= ' AND ex.ext_registration_date IS NOT NULL AND ex.ext_registration_date != ""';
        }elseif ($filterOption == 'dateOutSf'){
            $dateField = 'STR_TO_DATE(ex.ext_invoice_date, \'%d-%m-%Y\')';
            $where .= ' AND ex.ext_invoice_date IS NOT NULL AND ex.ext_invoice_date != ""';
        }else{ // dateWithoutSf
            $dateField = 'b.bill_date';
            $where .= ' AND (ex.ext_registration_date IS NULL OR ex.ext_registration_date = "") AND cc.financial_type in (\'profitable\', \'yield-consumable\') and b.sum < 0';
        }

        $sql = "SELECT
  ex.ext_invoice_no as bill_no,
  b.bill_no as newbills_bill_no,
  b.bill_date,
  concat(cur.name, ' ', cur.code) as currency, 
  cg.name_full,
  cg.inn,
  cg.kpp,
  cg.legal_type,
  date_format(str_to_date(ex.ext_invoice_date, '%d-%m-%Y'), '%d.%m.%Y') as ext_invoice_date,
  (ex.ext_vat+ex.ext_sum_without_vat) AS sum,
  ex.ext_vat AS vat,
  ex.ext_sum_without_vat AS sum_without_vat,
  ex.ext_registration_date,
  (SELECT value
   FROM organization_i18n n
   WHERE n.organization_record_id = (SELECT max(id) max_id
                                     FROM `organization` o
                                     WHERE o.organization_id = b.organization_id)
         AND lang_code = 'ru-RU'
         AND field = 'name') AS orgznization_name
FROM clients c, client_contract cc, client_contragent cg, newbills b
INNER JOIN currency cur ON cur.id = b.currency 
LEFT JOIN newbills_external ex ON ex.bill_no = b.bill_no
WHERE " . $dateField . " BETWEEN :date_from AND :date_to
      AND b.organization_id = :organization_id
      AND c.id = b.client_id AND cc.id = c.contract_id AND cc.contragent_id = cg.id
      " . $where . "
ORDER BY " . $dateField . ", sum DESC";

        $query = \Yii::$app->db->createCommand($sql, [
                ':date_from' => $dateFrom->getSqlDay(),
                ':date_to' => $dateTo->getSqlDay(),
                ':organization_id' => $organizationId
            ]+ $whereParam);
        $data = $query->queryAll();

        $total = [];
        foreach ($data as  $i => $row) {

            $billCorrDate = BillOutcomeCorrection::find()
            ->where(['bill_no' => $row['newbills_bill_no']])
            ->one();

            if ($billCorrDate && $billCorrDate['correction_number'] ?? null) {
                $data[$i]['correction_number'] = $billCorrDate['correction_number'];
                $data[$i]['correction_date'] = date('d.m.Y', strtotime($billCorrDate['date_created']));
            }

            if (!isset($total[$row['currency']])) {
                $total[$row['currency']] = [
                    'sum' => 0,
                    //'vat' => 0,
                    'vat_22' => 0,
                    'vat_20' => 0,
                    'vat_7' => 0,
                    'vat_5' => 0,
                ];
            }

            $total[$row['currency']]['sum'] += $row['sum'];
            //$total[$row['currency']]['vat'] += $row['vat'];
            $vat  = (float)$row['vat'];            
            $base = (float)$row['sum_without_vat'];

            $vat22 = 0.0;
            $vat20 = 0.0;
            $vat7  = 0.0;
            $vat5  = 0.0;

            if ($vat != 0.0 && $base != 0.0) {
                $rate = $vat / $base * 100;

                if (abs($rate - 22.0) < 0.5) {
                    $vat22 = $vat;
                } elseif (abs($rate - 20.0) < 0.5) {
                    $vat20 = $vat;
                } elseif (abs($rate - 7.0) < 0.5) {
                    $vat7 = $vat;
                } elseif (abs($rate - 5.0) < 0.5) {
                    $vat5 = $vat;
                } else {
                    // дефолт — считаем как 20%, чтобы не ломать старое поведение
                    $vat20 = $vat;
                }
            }

            $data[$i]['vat_22'] = $vat22;
            $data[$i]['vat_20'] = $vat20;
            $data[$i]['vat_7']  = $vat7;
            $data[$i]['vat_5']  = $vat5;

            $total[$row['currency']]['vat_22'] += $vat22;
            $total[$row['currency']]['vat_20'] += $vat20;
            $total[$row['currency']]['vat_7']  += $vat7;
            $total[$row['currency']]['vat_5']  += $vat5;
        }

        if (get_param_raw('is_to_excel', 0) == 1) {
            $excel = new \app\classes\excel\PurchaseBookToExcel;
            $excel->data = $data;
            $excel->total = $total;
            $excel->organizationId = $organizationId;
            $excel->dateFrom = $date_from;
            $excel->dateTo = $date_to;
            $excel->openFile(Yii::getAlias('@app/templates/purchase_book_ifns.xls'));
            $excel->prepareToIfns();
            $excel->download('Книга покупок для ИФНС');
        }

        $design->assign('data', $data);
        $design->assign('totals', $total);
        $design->assign('date_from', $date_from);
        $design->assign('date_to', $date_to);
        $design->AddMain('newaccounts/ext_bills_ifns.tpl');
    }

    function newaccounts_balance_sell($fixclient)
    {

        ini_set('memory_limit', '4G');

        global $design, $db, $user;
        $dateFrom = new DatePickerValues('date_from', 'first');
        $dateTo = new DatePickerValues('date_to', 'last');
        $dateFrom->format = 'Y-m-d';
        $dateTo->format = 'Y-m-d';
        $date_from = $dateFrom->getDay();
        $date_to = $dateTo->getDay();
        $design->assign('date_from_val', $date_from_val = $dateFrom->getTimestamp());
        $design->assign('date_to_val', $date_to_val = $dateTo->getTimestamp());
        $design->assign('organizations', Organization::dao()->getList());
        $design->assign('organization_id', $organizationId = get_param_protected('organization_id', Organization::MCN_TELECOM));
        set_time_limit(0);

        $dtFrom = clone $dateFrom->day;
        $dtFrom->modify("-1 month");

        $dtTo = clone $dateTo->day;
        $dtTo->modify("+1 month");

        Bill::dao()->checkSetBillsOrganization($dtFrom, $dtTo);

        $R = [];
        $Rc = 0;
        $S = [
            'sum_without_tax' => 0,
            'sum' => 0,
            'sum_tax' => 0
        ];

        if (get_param_raw("do", "")) {

            $W = ['AND'];//,'C.status="work"');
            $W[] = 'B.sum!=0';
            $W[] = 'P.currency="RUB" OR P.currency IS NULL';

            if ($organizationId) {
                $W[] = 'B.organization_id="' . $organizationId . '"';
            }

            $W[] = "cg.legal_type in ('ip', 'legal')";

            $W_gds = $W;

            if ($date_from) {
                $W[] = 'B.bill_date>="' . $date_from . '"-INTERVAL 1 MONTH';
            }
            if ($date_to) {
                $W[] = 'B.bill_date<="' . $date_to . '"+INTERVAL 1 MONTH';
            }

            $q_service = "
            SELECT * FROM (
                SELECT
                    B.*,
                    cg.`name_full` AS company_full,
                    cg.`inn`,
                    cg.`kpp`,
                    cg.`legal_type` AS type,
                    GROUP_CONCAT(
                        DISTINCT CONCAT(P.`payment_no`, ';', DATE_FORMAT(P.`payment_date`, '%d.%m.%Y')) ORDER BY P.`payment_date` DESC SEPARATOR ', '
                    ) AS payments,
                    SUM(P.`sum`) AS pay_sum,
                    `bill_date` AS shipment_date,
                    0 AS shipment_ts,
                    18 AS min_nds
                FROM
                    `newbills` B
                LEFT JOIN `newpayments` P ON (P.`bill_no` = B.`bill_no` AND P.`client_id` = B.`client_id`)
                INNER JOIN `clients` C ON (C.`id` = B.`client_id`)
                INNER JOIN `client_contract` cr ON cr.`id` = C.`contract_id`
                INNER JOIN `client_contragent` cg ON cg.`id` = cr.`contragent_id`
                WHERE
                    " . MySQLDatabase::Generate($W) . "
                    AND (
                           B.`bill_no` RLIKE '^20[0-9]{4}-[0-9]{4}([0-9]{2})?$' /*stat old && new format bill */ 
                        OR B.`bill_no` RLIKE '^[0-9]{10}$' /* uu format*/
                        )
                    AND IF(B.`sum` < 0, cr.`contract_type_id` =2, TRUE) ### only telekom clients with negative sum
                    AND cr.`contract_type_id` != 6 ## internal office
                    AND cr.`business_process_status_id` NOT IN (22, 28, 99) ## trash, cancel
                GROUP BY
                    B.`bill_no`
                ORDER BY
                    B.`bill_no`
        ) f";

            $q_gds = "  
            SELECT *, UNIX_TIMESTAMP(shipment_date) AS shipment_ts
            FROM (
                SELECT
                    B.*,
                    cg.`name_full` AS company_full,
                    cg.`inn`,
                    IF(`doc_date` != '0000-00-00',
                        `doc_date`,
                        (
                            SELECT MIN(CAST(`date_start` AS DATE))
                            FROM `tt_troubles` t , `tt_stages` s
                            WHERE
                                t.`bill_no` = B.`bill_no`
                                AND t.`id` = s.`trouble_id`
                                AND state_id IN (SELECT `id` FROM `tt_states` WHERE `state_1c` = 'Отгружен')
                        )
                    ) AS shipment_date,
                    cg.`kpp`,
                    cg.`legal_type` AS type,
                    GROUP_CONCAT(
                        DISTINCT CONCAT(P.`payment_no`, ';', DATE_FORMAT(P.`payment_date`, '%d.%m.%Y')) ORDER BY P.`payment_date` DESC SEPARATOR ', '
                    ) AS payments,
                    SUM(P.`sum`) AS `pay_sum`,
                    (
                        SELECT MIN(nds)
                        FROM `newbill_lines` nl, `g_goods` g
                        WHERE
                            nl.`item_id` != ''
                            AND nl.`bill_no` = B.`bill_no`
                            AND `item_id` = g.`id`
                    ) AS min_nds,
                    (SELECT GROUP_CONCAT(DISTINCT gtd) FROM `newbill_lines` nl WHERE nl.`bill_no` = B.`bill_no` AND gtd > '') AS gtd
                FROM
                    (
                        SELECT DISTINCT `bill_no`
                        FROM `newbills`
                        WHERE
                            `doc_date` BETWEEN '" . $date_from . "' AND '" . $date_to . "'  #выбор счетов-фактур с утановленной датой документа

                        UNION 
                        
                        SELECT DISTINCT `bill_no`
                        FROM `tt_stages` s, `tt_troubles` t
                        WHERE
                            s.`trouble_id` = t.`id`
                            AND `date_start` BETWEEN '" . $date_from . " 00:00:00' AND '" . $date_to . " 23:59:59'
                            AND `state_id` IN (SELECT `id` FROM `tt_states` WHERE `state_1c` = 'Отгружен') #выбор счетов-фактур по дате отгрузки
                            AND t.`bill_no` IS NOT NULL
                    ) t,
                    `newbills` B
                        LEFT JOIN `newpayments` P ON (P.`bill_no` = B.`bill_no` AND P.`client_id` = B.`client_id`)
                            INNER JOIN `clients` AS C ON (C.`id` = B.`client_id`)
                                INNER JOIN `client_contract` cr ON cr.`id` = C.`contract_id`
                                    INNER JOIN `client_contragent` cg ON cg.`id` = cr.`contragent_id`
                WHERE
                    t.`bill_no` = B.`bill_no`
                    AND B.`bill_no` RLIKE '^20[0-9]{4}/[0-9]{4}([0-9]{2})?$' #только счета с товарами (выставленные через 1С)
                    AND
                        " . MySQLDatabase::Generate($W_gds) . "
                GROUP BY
                    B.`bill_no`
                ORDER BY
                    B.`bill_no`
            ) a
            WHERE
                (min_nds IS NULL OR min_nds > 0)  ###исключить счета, с товарами без НДС
                AND shipment_date BETWEEN '" . $date_from . "' AND '" . $date_to . "'";


            $AA = [];

            foreach (Yii::$app->getDb()->createCommand($q_service)/*->cache(24 * 60 * 60)*/
            ->queryAll() as $l) {
                $AA[] = $l;
            }

            foreach (Yii::$app->getDb()->createCommand($q_gds)/*->cache(24 * 60 * 60)*/
            ->queryAll() as $l) {
                $AA[] = $l;
            }

            //$res = mysqli_query($q = "select * from (".$q_service." union ".$q_gds.") a order by a.bill_no") or die(mysqli_error());

            $t = time();

            $this->bb_cache__init();

            foreach ($AA as $p) {

                //while(($p = mysqli_fetch_assoc($res))!==false){

                try {
                    $bill = new \Bill($p['bill_no']);
                } catch (\Exception $e) {
                    continue;
                }

                for ($I = 1; $I <= 3; $I++) {

                    $A = false;//$this->bb_cache__get($p["bill_no"]."--".$I);

                    if ($A === false) {
                        $A = $this->do_print_prepare($bill, 'invoice', $I, 'RUB', 0, true);
                        //$this->bb_cache__set($p["bill_no"]."--".$I, $A);
                    }

                    if ($A['bill']['sum'] <= 0) { // без нулевых и отрицательных документов
                        continue;
                    }

                    $invDate = $p['shipment_ts'] ?
                        $p['shipment_ts'] :
                        $A['inv_date'];

                    $A['bill']['inv_date'] = $invDate;

                    // get property from history
                    /** @var \app\models\ClientAccount $c */
                    $c = ClientAccount::findOne(['id' => $p['client_id']])
                        ->loadVersionOnDate(date('Y-m-d', $invDate));

                    if ($c->currency != Currency::RUB || !$c->getOptionValue(ClientAccountOptions::OPTION_UPLOAD_TO_SALES_BOOK)) { // только рублевые ЛС и ЛС с выгрузкой
                        continue;
                    }


                    if (is_array($A) && $A['bill']['sum']) {
                        $A['bill']['shipment_ts'] = $p['shipment_ts'];
                        $A['bill']['contract'] = $c->contract->contractType ?: $c->contract->business;
                        $A['bill']['contract_status'] = $c->contract->businessProcessStatus;
                        $A['bill']['payments'] = $p['payments'];
                        $A['bill']['gtd'] = $p['gtd'];

                        $p['company_full'] = trim($c['company_full']);
                        $p['inn'] = $c['inn'];
                        $p['kpp'] = $c['kpp'];

                        $k = date('Y-m-d', $A['inv_date']);

                        if ((!$date_from || $k >= $date_from) && (!$date_to || $k <= $date_to)) {
                            $A['bill']['company_full'] = $p['company_full'];
                            $A['bill']['type'] = $c['type'];

                            if ($p['type'] == 'person') {
                                $A['bill']['inn'] = '-----';
                                $A['bill']['kpp'] = '-----';
                            } elseif ($p['type'] == 'legal') {
                                $A['bill']['inn'] = trim($p['inn']);
                                $A['bill']['kpp'] = trim($p['kpp']);
                            } else { // ИП
                                $A['bill']['inn'] = trim($p['inn']);
                                $A['bill']['kpp'] = '-----';
                            }

                            $invoice = Invoice::findOne([
                                'bill_no' => $A['bill']['bill_no'],
                                'type_id' => $I,
                                'is_reversal' => 0
                            ]);

                            $A['bill']['inv_no'] = $invoice ? $invoice->number : $A['inv_no'];

                            if ($p['is_rollback']) {
                                foreach (['ts', 'sum_tax', 'sum_without_tax', 'sum'] as $f) {
                                    $A['bill'][$f] = -abs($A['bill'][$f]);
                                }
                            }

                            foreach ($S as $sk => $sv) {
                                $S[$sk] += $A['bill'][$sk];
                            }

                            $R[$A['inv_date'] . '-' . ($Rc++)] = $A['bill'];
                        }
                    }
                }
                unset($bill);
            }
            unset($p);
            ksort($R);

            //printdbg($R);

            $this->bb_cache__finish();

            //usort($R, array("self", "bb_sort_sum"));
        }

        if (get_param_raw('excel', 0) == 1) {
            $excel = new \app\classes\excel\BalanceSellToExcel;
            $excel->openFile(Yii::getAlias('@app/templates/balance_sell.xls'));
            $excel->organization = Organization::findOne(['id' => $organizationId])->name;
            $excel->dateFrom = Yii::$app->request->get('date_from');
            $excel->dateTo = Yii::$app->request->get('date_to');
            $excel->prepare($R);
            $excel->download('Книга продаж');
        }

        $design->assign('correctionList', $this->_makeCorrectionList($date_from, $date_to));

        $design->assign('data', $R);
        $design->assign('sum', $S);

        $fullscreen = get_param_protected('fullscreen', 0);
        $design->assign('fullscreen', $fullscreen);
        if ($fullscreen == 1) {
            $design->ProcessEx('newaccounts/balance_sell.tpl');
        } else {
            $design->AddMain('newaccounts/balance_sell.tpl');
        }
    }

    function bb_sort_sum($a, $b)
    {
        return $a["sum"] > $b["sum"] ? 1 : 0;
    }

    function bb_cache__init()
    {
        self::$bb_c = [];

    }

    function bb_cache__preload($year, $month)
    {
        $nFile = "/tmp/stat_cache/" . $year . "-" . $month . ".dat";

        if (!isset(self::$bb_c[$year][$month])) {
            self::$bb_c[$year][$month] = ["data" => [], "is_modify" => false];
        }

        if (file_exists($nFile)) {
            self::$bb_c[$year][$month]["data"] = unserialize(file_get_contents($nFile));
        }
    }

    function bb_cache__get($idx)
    {
        if (preg_match("/^(\d{4})(\d{2})-/", $idx, $o)) {
            if (!isset(self::$bb_c[$o[1]][$o[2]])) {
                $this->bb_cache__preload($o[1], $o[2]);
            }

            if (isset(self::$bb_c[$o[1]][$o[2]]["data"][$idx])) {
                return self::$bb_c[$o[1]][$o[2]]["data"][$idx];
            }
        }

        return false;
    }

    function bb_cache__set($idx, $val)
    {
        if (preg_match("/^(\d{4})(\d{2})-/", $idx, $o)) {
            if (!isset(self::$bb_c[$o[1]][$o[2]])) {
                $this->bb_cache__preload($o[1], $o[2]);
            }

            if (!isset(self::$bb_c[$o[1]][$o[2]]["data"][$idx])) {
                self::$bb_c[$o[1]][$o[2]]["is_modify"] = true;
                self::$bb_c[$o[1]][$o[2]]["data"][$idx] = $val;
            }

            return true;
        }
        return false;
    }

    function bb_cache__finish()
    {
        $dir = "/tmp/stat_cache/";
        if (is_dir($dir) && is_writable($dir)) {
            foreach (self::$bb_c as $year => $months) {
                foreach ($months as $month => $data) {
                    if ($data["is_modify"]) {
                        @file_put_contents($dir . $year . "-" . $month . ".dat", serialize($data["data"]));
                    }
                }
            }
        }
    }

    /**
     * Корректировочный лист
     *
     * @param string $dateFromStr
     * @param string $dateToStr
     * @return array
     */
    private function _makeCorrectionList($dateFromStr, $dateToStr)
    {
        $data = [];

        $dateFrom = new DateTime($dateFromStr);
        $dateTo = new DateTime($dateToStr);

        $query = BillCorrection::find()
            ->where(['between', 'date', $dateFrom->format(DateTimeZoneHelper::DATE_FORMAT), $dateTo->format(DateTimeZoneHelper::DATE_FORMAT)])
            ->orderBy(['date' => SORT_ASC, 'bill_no' => SORT_ASC]);

        /** @var BillCorrection $billCorrection */
        foreach ($query->each() as $billCorrection) {
            $data[((new DateTime($billCorrection->date))->getTimestamp())][] = $billCorrection;
        }

        return $data;
    }

    function newaccounts_pay_rebill($fixclient)
    {
        global $design, $db, $fixclient_data;
        if (!$fixclient) {
            trigger_error2('Не выбран клиент');
            return;
        }
        $pay = get_param_integer('pay');
        $bill = get_param_protected('bill');
        if ($bill) {
            $db->Query('UPDATE newpayments SET bill_vis_no="' . $bill . '" WHERE id=' . $pay);
        } else {
            $db->Query('UPDATE newpayments SET bill_vis_no=bill_no WHERE id=' . $pay);
        }

        header('Location: ?module=newaccounts');
        exit();
    }

    function newaccounts_first_pay($fixclient)
    {
        global $design, $db;
        $dateFrom = new DatePickerValues('date_from', 'first');
        $dateTo = new DatePickerValues('date_to', 'today');
        $dateFrom->format = 'Y-m-d';
        $dateTo->format = 'Y-m-d';
        $from = $dateFrom->getDay();
        $to = $dateTo->getDay();

        $sort = get_param_raw('sort', 'manager');
        $design->assign('sort', $sort);

        if (get_param_raw('process', 'stop') != 'stop') {

            $usersData = [];

            $query = $db->AllRecords('SELECT user, name FROM user_users');
            foreach ($query as $row) {
                $usersData[$row['user']] = $row['name'];
            }

            foreach ($query as $key => $value) {
                $channels[$value['id']] = $value['name'];
            }

            $query1 = $db->AllRecords("
                    SELECT
                        newpayments.client_id, c.client, c.id AS clientid, cg.name AS company, newpayments.`sum`, newpayments.payment_date, c.site_req_no, cr.organization_id
                    FROM
                        `newpayments`, `clients` c
                         INNER JOIN `client_contract` cr ON cr.id=c.contract_id
                         INNER JOIN `client_contragent` cg ON cg.id=cr.contragent_id
                    WHERE
                        payment_date BETWEEN '" . $from . "' AND '" . $to . "'
                        AND c.id=newpayments.client_id
                    ORDER BY
                        client,payment_date
                    ");
            $uniqData = [];
            foreach ($query1 as $row) {
                if (!isset($uniqData[$row['client']])) {
                    $uniqData[$row['client']] = $row;
                }
            }

            $sortedArray = [];
            foreach ($uniqData as $client => $row) {
                $query2 = $db->AllRecords("SELECT count(*) AS count FROM newpayments WHERE payment_date < '" . $from . "' AND client_id='" . $row['client_id'] . "' ORDER BY payment_date LIMIT 1");
                if ($query2[0]['count'] == 0) {
                    $row['telemark'] = "Телемаркетинг";
                    $row['channel'] = "Канал #1";
                    $row['organization'] = $row['company'];
                    $row['first_pay_data'] = $row['payment_date'];
                    $sortedArray[$client] = $row;
                }
            }

            foreach ($sortedArray as $client => $clientData) {
                $clientData = $db->AllRecords("
SELECT cr.manager, cr.account_manager FROM clients c
 INNER JOIN `client_contract` cr ON cr.id=c.contract_id
 INNER JOIN `client_contragent` cg ON cg.id=cr.contragent_id
 WHERE client='" . $client . "'");

                $sortedArray[$client]['manager'] = isset($usersData[$clientData[0]['manager']]) ? $usersData[$clientData[0]['manager']] : $clientData[0]['manager'];
                $sortedArray[$client]['account_manager'] = isset($usersData[$clientData[0]['account_manager']]) ? $usersData[$clientData[0]['account_manager']] : $clientData[0]['account_manager'];
                $sortedArray[$client]['voip'] = $db->AllRecords("
                        SELECT
                        tarifs_voip.name AS tarif,
                        (tarifs_voip.month_line*(usage_voip.no_of_lines-1) + tarifs_voip.month_number) AS cost,
                        usage_voip.actual_from,
                        usage_voip.actual_to,
                        log_tarif.date_activation
                        FROM
                        `usage_voip`,
                        `log_tarif`,
                        `tarifs_voip`
                        WHERE
                        usage_voip.client='" . $client . "'
                        AND usage_voip.id = log_tarif.id_service
                        AND log_tarif.id_tarif=tarifs_voip.id
                        AND service='usage_voip'");
                $sortedArray[$client]['ip_ports'] = $db->AllRecords("
                        SELECT
                        tarifs_internet.name AS tarif,
                        tarifs_internet.pay_month AS cost,
                        usage_ip_ports.actual_from,
                        usage_ip_ports.actual_to,
                        log_tarif.date_activation
                        FROM
                        `usage_ip_ports`,
                        `log_tarif`,
                        `tarifs_internet`
                        WHERE
                        usage_ip_ports.client='" . $client . "'
                        AND usage_ip_ports.id = log_tarif.id_service
                        AND log_tarif.id_tarif=tarifs_internet.id
                        AND service='usage_ip_ports'
                        ");
            }
            usort($sortedArray, create_function('$a,$b', 'return strcmp($a["' . $sort . '"], $b["' . $sort . '"]);'));

            $design->assign('data', $sortedArray);
        }
        $design->AddMain('newaccounts/first_pay.tpl');
    }

    function newaccounts_usd($fixclient)
    {
        global $design, $db;
        $tableName = CurrencyRate::tableName();
        $design->assign('rates', $db->AllRecords('SELECT * FROM ' . $tableName . ' ORDER BY date DESC LIMIT 30'));
        if (($date = get_param_protected('date')) && ($rate = get_param_protected('rate'))) {
            if ($db->QuerySelectRow($tableName, ['date' => $date, 'currency' => 'USD'])) {
                trigger_error2('Курс на эту дату уже введён');
            } else {
                trigger_error2('Курс занесён');
                $db->QueryInsert($tableName, ['date' => $date, 'currency' => 'USD', 'rate' => $rate]);
            }
        }
        $design->assign('cur_date', date('Y-m-d'));
        $design->AddMain('newaccounts/usd.tpl');

    }

    function newaccounts_pay_report()
    {
        global $design, $db;
        $def = getdate();


        $design->assign("by_day", $byDay = get_param_raw("range_by", "day") == "day");

        if ($byDay) {
            $from = strtotime(get_param_raw("from_day", date("d-m-Y")));
            $to = strtotime("+1day", $from);
        } else {
            $from = strtotime(get_param_raw("from_period", date("d-m-Y")));
            $to = strtotime(get_param_raw("to_period", date("d-m-Y", strtotime("+1day", strtotime("00:00:00")))));
            $to = strtotime("+1day", $to);
        }

        $design->assign("from_day", date("d-m-Y", $from));
        $design->assign("from_period", date("d-m-Y", $from));
        $design->assign("to_period", date("d-m-Y", strtotime("-1day", $to)));


        $user = (int)get_param_raw('user', false);
        if ($user) {
            $filter = " and P.add_user=" . $user;
        } else {
            $filter = '';
        }

        $filterBank = $filterEcash = "";

        $type = get_param_raw('type', 'payment_date');
        if ($type != 'payment_date' && $type != 'oper_date') {
            $type = 'add_date';
        }
        $design->assign('type', $type);

        $bdefault = ["mos" => true, "citi" => true, "ural" => true, "sber" => true];
        $banks = get_param_raw("banks", $bdefault);
        $design->assign("banks", $banks);

        $edefault = ["cyberplat" => true, "yandex" => true, "paypal" => true];
        $ecashs = get_param_raw("ecashs", $edefault);
        $design->assign("ecashs", $ecashs);

        $order_by = get_param_raw('order_by', 'add_date');
        $design->assign("order_by", $order_by);

        $types = '';
        foreach (['bank', 'prov', 'neprov', 'ecash', 'terminal', 'creditnote', 'api'] as $k) {
            if ($v = get_param_raw($k)) {
                $types .= ($types ? ',' : '') . '"' . $k . '"';
            }
            $design->assign($k, $v);
        }

        $filterBank = " P.bank in ('" . implode("','", array_keys($banks)) . "')";

        if (isset($types["ecash"])) {
            $filterEcash = " P.ecash_operator in ('" . implode("','", array_keys($ecashs)) . "')";
        }

        $filter .= " and (" . $filterBank . ($filterEcash ? " OR " . $filterEcash : "") . ")";

        if (!$types) {
            $R = [];
        } else {
            $R = $db->AllRecords($q = 'SELECT P.*,cr.manager,C.client,cg.name AS company,B.bill_date,U.user FROM newpayments AS P
                         INNER JOIN clients AS C ON C.id=P.client_id
                         INNER JOIN `client_contract` cr ON cr.id=C.contract_id
                         INNER JOIN `client_contragent` cg ON cg.id=cr.contragent_id
                         LEFT JOIN user_users AS U ON U.id=P.add_user
                         LEFT JOIN newbills AS B ON (B.bill_no=P.bill_no)
                         WHERE P.' . $type . '>=FROM_UNIXTIME(' . $from . ') AND P.' . $type . '<FROM_UNIXTIME(' . $to . ')
                         AND P.type IN (' . $types . ')' . $filter . ' ORDER BY ' . $order_by . ' LIMIT 5000');
        }

        $S = [
            'bRUB' => 0,
            'pRUB' => 0,
            'nRUB' => 0,
            'eRUB' => 0,
            'cRUB' => 0,
            'aRUB' => 0,
            'bUSD' => 0,
            'pUSD' => 0,
            'nUSD' => 0,
            'RUB' => 0,
            'USD' => 0
        ];

        foreach ($R as &$r) {
            $r['type'] = substr($r['type'], 0, 1);
            $S[$r['type'] . $r['currency']] += $r['sum'];
            $S[$r['currency']] += $r['sum'];
        }
        unset($r);
        $design->assign('user', $user);
        $design->assign('users',
            $db->AllRecords("SELECT id,user,name FROM user_users WHERE usergroup IN ('admin','manager','account_managers','accounts_department') AND enabled = 'yes' ORDER BY name",
                null, MYSQLI_ASSOC));
        $design->assign('payments', $R);
        $design->assign('totals', $S);
        $design->assign("fullscreen", $isFullscreen = (get_param_raw("fullscreen", "") != ""));
        if ($isFullscreen) {
            echo $design->fetch('newaccounts/pay_report.tpl');
            exit();
        } else {
            $design->AddMain('newaccounts/pay_report.tpl');
        }
    }

    function newaccounts_postreg_report()
    {
        global $design, $db;
        $dateFrom = new DatePickerValues('date_from', 'today');
        $from = $dateFrom->getTimestamp();
        $design->AddMain('newaccounts/postreg_report_form.tpl');
    }

    function newaccounts_postreg_report_do()
    {
        global $design, $db;
        $dateFrom = new DatePickerValues('date_from', 'today');
        $from = $dateFrom->getTimestamp();
        $ord = 0;
        $R = $db->AllRecords('
          SELECT B.*,cg.name AS company,C.address_post_real 
          FROM newbills AS B 
          INNER JOIN clients AS C ON C.id=B.client_id
          INNER JOIN `client_contract` cr ON cr.id=C.contract_id
          INNER JOIN `client_contragent` cg ON cg.id=cr.contragent_id
          WHERE 
                  postreg = "' . date('Y-m-d', $from) . '"
          GROUP BY C.id ORDER BY B.bill_no');
        foreach ($R as &$r) {
            $r['ord'] = ++$ord;
            if (!preg_match('|^([^,]+),([^,]+),(.+)$|', $r['address_post_real'], $m)) {
                $m = ['', '', 'Москва', $r['address_post_real']];
            }
            $r['_zip'] = $m[1];
            $r['_city'] = $m[2];
            $r['_addr'] = $m[3];
        }
        unset($r);
        $design->assign('postregs', $R);
        $design->ProcessEx('pop_header.tpl');
        $design->ProcessEx('errors.tpl');
        $design->ProcessEx('newaccounts/postreg_report.tpl');
        $design->ProcessEx('pop_footer.tpl');
    }

    function newaccounts_bill_data()
    {
        if (!isset($_REQUEST['subaction'])) {
            return null;
        }
        global $db;
        switch ($_REQUEST['subaction']) {
            case 'getItemDates': {
                $query = "
                    SELECT
                        date_from,
                        date_to
                    FROM
                        newbill_lines
                    WHERE
                        bill_no='" . addcslashes($_REQUEST['bill_no'], "\\\\'") . "'
                    AND
                        sort = " . ((int)$_REQUEST['sort_number']) . "
                ";
                $db->Query($query);
                $ret = $db->NextRecord(MYSQLI_ASSOC);
                echo '{date_from:"' . $ret['date_from'] . '",date_to:"' . $ret['date_to'] . '"}';
                break;
            }
            case 'setItemDates': {
                $date_from = trim(preg_replace('/[^0-9-]+/', '', $_REQUEST['from']));
                $date_to = trim(preg_replace('/[^0-9-]+/', '', $_REQUEST['to']));
                $date_patt = '/^\d{4}-\d{2}-\d{2}$/';

                if (!preg_match($date_patt, $date_from) || !preg_match($date_patt, $date_to)) {
                    echo "Неправильный формат даты.\nВерный формат: гггг-мм-дд";
                    break;
                }

                $transaction = \app\models\BillLine::getDb()->beginTransaction();
                try {
                    /** @var \app\models\BillLine $line */
                    if ($line = \app\models\BillLine::find()->where([
                        'bill_no' => $_REQUEST['bill_no'],
                        'sort' => (int)$_REQUEST['sort_number']
                    ])->one()) {

                        if (!$line->bill->isEditable()) {
                            echo "Содержимое счета нельзя редактировать";
                            break;
                        }

                        $line->date_from = $date_from;
                        $line->date_to = $date_to;
                        $line->tax_rate = $line->bill->clientAccount->getTaxRateOnDate($date_from);
                        $line->calculateSum($line->bill->price_include_vat);

                        if (!$line->save()) {
                            throw new ModelValidationException($line);
                        }

                        if ($line->bill->invoices) {
                            $info = Invoice::getInfo($line->bill_no);

                            return $info;
                            $line->bill->generateInvoices();
                        }
                    }

                    //Обновление списка документов
                    BillDocument::dao()->updateByBillNo($_REQUEST['bill_no']);
                    $transaction->commit();
                } catch (\Exception $e) {
                    $transaction->rollBack();
                    throw $e;
                }

                echo 'Ok';
                break;
            }
        }
        exit();
    }

    function newaccounts_make_1c_bill($client_tid)
    {
        global $db, $design, $user;

        $bill_no = (isset($_GET["bill_no"]) ? $_GET["bill_no"] : (isset($_POST["order_bill_no"]) ? $_POST["order_bill_no"] : ""));
        $isRollback = isset($_GET['is_rollback']);

        $bill = null;

        // направляем на нужную страницу редактирования счета
        if (preg_match("/20[0-9]{4}-[0-9]{4}/i", $bill_no)) {
            header("Location: ./?module=newaccounts&action=bill_edit&bill=" . $bill_no);
            exit();
        }

        //устанавливаем клиента
        if ($bill_no) {
            $bill = new \Bill($bill_no);
            if (!$bill) {
                return false;
            }
            $client_id = $bill->Client("id");

            if ($bill->IsClosed()) {
                header("Location: ./?module=newaccounts&action=bill_view&bill=" . $bill_no);
                exit();
            }
        } else {
            // если форма пересылается
            if (isset($_POST['action']) && $_POST['action'] == 'make_1c_bill') {
                $client_id = urldecode(get_param_raw("client_id"));
                // открывается
            } else {
                $client_id = $client_tid;
            }
        }

        $account = ClientAccount::find()->where('id = :id or client = :id', [':id' => $client_id])->one();
        $_SESSION['clients_client'] = $account->id;

        // инициализация
        $lMetro = \app\models\Metro::getList();
        $lLogistic = [
            "none" => "--- Не установленно ---",
            "selfdeliv" => "Самовывоз",
            "courier" => "Доставка курьером",
            "auto" => "Доставка авто",
            "tk" => "Доставка ТК",
        ];
        $design->assign("l_metro", $lMetro);
        $design->assign("l_logistic", $lLogistic);

        $storeList = [];
        foreach ($db->AllRecords("SELECT * FROM g_store WHERE is_show='yes' ORDER BY name") as $l) {
            $storeList[$l["id"]] = $l["name"];
        }
        $design->assign("store_list", $storeList);
        $storeId = get_param_raw("store_id", "8e5c7b22-8385-11df-9af5-001517456eb1");


        require_once INCLUDE_PATH . "clCards.php";
        require_once INCLUDE_PATH . "1c_integration.php";

        $bm = new \_1c\billMaker($db);

        $pt = $account->price_type;

        $positions = [
            'bill_no' => $bill_no,
            'client_id' => $account->id,
            'list' => [],
            'sum' => 0,
            'number' => '',
            'comment' => ''
        ];

        $isToRecalc = false;


        /** блок загрузки данных::старт **/
        // загружаем данные о позиция заказа
        // из _POST +удаление
        if (isset($_POST['pos'])) {
            foreach ($_POST['pos'] as $id => $varr) {
                if ($_POST["pos"][$id]["quantity"] != $_POST["pos"][$id]["quantity_saved"]) {
                    $isToRecalc = true;
                }
                if (!isset($_POST['pos'][$id]['del'])) {
                    $positions['list'][$id] = $varr;
                    $isToRecalc = true;
                }
            }

            // или из базы
        } elseif (isset($_GET['bill_no'])) {
            $positions = $bm->getStatOrder($_GET['bill_no']);
            if ($positions['is_rollback'] && !isset($_GET['is_rollback'])) {
                header('Location: ?module=newaccounts&action=make_1c_bill&bill_no=' . $_GET['bill_no'] . '&is_rollback=1');
                exit();
            }
        } elseif (isset($_GET["from_order"])) {
            $_POST = $db->GetRow("SELECT * FROM newbills_add_info WHERE bill_no = '" . $_GET["from_order"] . "'");
        }

        // добавление новой позиции
        if (isset($_POST['append'])) {
            $isToRecalc = true;
            if (!trim($_POST['new']['quantity']) || !is_numeric($_POST['new']['quantity'])) {
                $_POST['new']['quantity'] = 1;
            }
            $_POST['new']['discount_set'] = 0;
            $_POST['new']['discount_auto'] = 0;
            $_POST['new']['code_1c'] = 0;
            $_POST['new']['price'] = Good::GetPrice($_POST['new']['id'], $pt);

            $positions['list'][] = $_POST['new'];
            $buf = [];
            foreach ($positions['list'] as $k => $p) {
                if (isset($buf[$p['id']])) {
                    $buf[$p['id']]['quantity'] += $p['quantity'];
                } else {
                    $buf[$p['id']] = $p;
                }
            }
            if (count($buf) != count($positions['list'])) {
                $nl = [];
                foreach ($buf as $p) {
                    $nl[] = $p;
                }
                $positions['list'] = $nl;
                unset($nl);
            }
            unset($buf);
        }

        /** блок загрузки данных::конец **/

        // расчет
        if ($isToRecalc && !$isRollback) {
            $positions = $bm->calcOrder($account->client, $positions, $pt);
        } elseif ($isRollback) {
            $bm->calcGetedOrder($positions, true);
        } else {
            $bm->calcGetedOrder($positions);
        }


        // данные заказа (add_info)

        //список полей
        $adds = [
            'ФИО' => 'fio',
            'Адрес' => 'address',
            'НомерЗаявки' => 'req_no',
            'ЛицевойСчет' => 'acc_no',
            'НомерПодключения' => 'connum',
            'Комментарий1' => 'comment1',
            'Комментарий2' => 'comment2',
            'ПаспортСерия' => 'passp_series',
            'ПаспортНомер' => 'passp_num',
            'ПаспортКемВыдан' => 'passp_whos_given',
            'ПаспортКогдаВыдан' => 'passp_when_given',
            'ПаспортКодПодразделения' => 'passp_code',
            'ПаспортДатаРождения' => 'passp_birthday',
            'ПаспортГород' => 'reg_city',
            'ПаспортУлица' => 'reg_street',
            'ПаспортДом' => 'reg_house',
            'ПаспортКорпус' => 'reg_housing',
            'ПаспортСтроение' => 'reg_build',
            'ПаспортКвартира' => 'reg_flat',
            'Email' => 'email',
            'ПроисхождениеЗаказа' => 'order_given',
            'КонтактныйТелефон' => 'phone'

            ,
            'Метро' => 'metro_id',
            'Логистика' => 'logistic',
            "ВладелецЛинии" => 'line_owner'
        ];

        // инициализация из _POST
        $add = [];
        $addcnt = 0;
        foreach ($adds as $add_key) {
            if (isset($_POST[$add_key])) {
                $add[$add_key] = $_POST[$add_key];
                $addcnt++;
            } else {
                $add[$add_key] = '';
            }
        }


        // инициализация из базы
        if (isset($_GET['bill_no'])) {
            if (!$addcnt) {
                $adds_data = $db->GetRow($q = "SELECT * FROM newbills_add_info WHERE bill_no='" . addcslashes($_GET['bill_no'],
                        "\\'") . "'");
                $storeId = $adds_data["store_id"];
                if (count($adds_data)) {
                    foreach ($adds as $add_rkey => $add_ekey) {
                        if (isset($adds_data[$add_ekey])) {
                            $add[$add_ekey] = $adds_data[$add_ekey];
                            $add_info[$add_rkey] = $adds_data[$add_ekey];
                        } else {
                            $add_info[$add_rkey] = '';
                        }
                    }
                }
            }
        }


        //printdbg($storeList,$storeId);
        $design->assign("store_id", $storeId);

        $enableLogistic = true;
        if (\_1c\checkLogisticItems($positions["list"], $add, false)) {
            $enableLogistic = false;
            $_POST["logistic"] = $add["logistic"];
        }
        $design->assign('add', $add);

        if (isset($_POST['order_bill_no'])) {
            $positions['bill_no'] = $_POST['order_bill_no'];
        }

        if (isset($_POST["order_comment"])) {
            $positions['comment'] = $_POST["order_comment"];
        }


        if (isset($_GET['is_rollback'])) {
            $positions['is_rollback'] = true;
        } else {
            $positions['is_rollback'] = false;
        }

        // оформить заказ
        if (isset($_POST['make'])) {
            // сохранение в 1с
            $add_info = [];
            foreach ($adds as $add_rkey => $add_ekey) {
                if (isset($_POST[$add_ekey])) {
                    $add_info[$add_rkey] = $_POST[$add_ekey];
                } else {
                    $add_info[$add_rkey] = '';
                }
            }
            if (!count($add_info)) {
                $add_info = null;
            }

            #$ret = $bm->saveOrder($client_tid,$positions['number'],$positions['list'],$positions['comment'],$positions['is_rollback'],$fault);
            $saveIds = ["metro_id" => $add_info["Метро"], "logistic" => $add_info["Логистика"]];

            $add_info["Метро"] = ($add_info["Метро"] == 0 ? "" : $lMetro[$add_info["Метро"]]);
            $add_info["Логистика"] = ($add_info["Логистика"] == "none" ? "" : $lLogistic[$add_info["Логистика"]]);

            $this->compareForChanges($positions, $bm->getStatOrder($positions['bill_no']));

            $a = [
                'client_tid' => $account->client,
                'order_number' => $positions['bill_no'],
                'items_list' => (isset($positions['list']) ? $positions['list'] : false),
                //'items_list'=> $positions['list'] ,
                'order_comment' => $positions['comment'],
                'is_rollback' => $positions['is_rollback'],
                'add_info' => $add_info,
                "store_id" => $storeId
            ];

            $ret = $bm->saveOrder($a, $fault);

            if ($ret) {
                //сохранение заказа в стате
                $error = '';
                $cl = new stdClass();
                $cl->order = $ret;
                $cl->isRollback = $isRollback;


                $bill_no = $ret->{\_1c\tr('Номер')};

                if (!$bill) {
                    $bill = new \Bill($bill_no);
                }


                $sh = new \_1c\SoapHandler();
                $sh->statSaveOrder($cl, $bill_no, $error, $saveIds);

                $positions = $bm->getStatOrder($bill_no);
                if ($ttt = $db->GetRow("SELECT * FROM tt_troubles WHERE bill_no='" . $bill_no . "'")) {
                    if ($ttt['state_id'] == 15 && $bill) {
                        global $user;
                        Bill::dao()->setManager($bill->GetNo(), $user->Get("id"));
                    }
                    if (!$positions['comment']) {
                        $comment = $add_info['ПроисхождениеЗаказа'] . "<br />
                            Телефон: " . $_POST['phone'] . "<br />
                            Адрес доставки: " . $_POST['address'] . "<br />
                            Комментарий1: " . $_POST['comment1'] . "<br />
                            Комментарий2: " . $_POST['comment2'];
                    } else {
                        $comment = $positions['comment'];
                    }
                    if (trim($comment)) {
                        $db->QueryUpdate("tt_troubles", "bill_no",
                            ["problem" => $comment, "bill_no" => $ttt['bill_no']]);
                    }

                } elseif (isset($_GET['tty']) && in_array($_GET['tty'],
                        ['shop_orders', 'mounting_orders', 'orders_kp'])
                ) {
                    StatModule::tt()->createTrouble([
                        'trouble_type' => $_GET['tty'],
                        'trouble_subtype' => 'shop',
                        'client' => $client_id,
                        'problem' => @$positions['comment'],
                        'bill_no' => $bill_no,
                        'time' => date('Y-m-d')
                    ]);
                }

                if (!$ttt && $bill) {
                    Bill::dao()->setManager($bill->GetNo(), $user->Get("id"));
                }

                trigger_error2("Счет #" . $bill_no . " успешно " . ($_POST["order_bill_no"] == $bill_no ? "сохранен" : "создан") . "!");
                header("Location: ./?module=newaccounts&action=bill_view&bill=" . $bill_no);
                exit();
            } else {
                trigger_error2("Не удалось создать заказ в 1С");
            }
        }


        $R = User::dao()->getListByDepartments(['manager', 'marketing']);
        $userSelect = [0 => "--- Не установлен ---"];
        foreach ($R as $u) {
            $userSelect[$u["id"]] = $u["name"] . " (" . $u["user"] . ")";
        }
        $design->assign("managers", $userSelect);
        if ($bill) {
            $design->assign("bill_manager", Bill::dao()->getManager($bill->GetNo()));
        }

        $design->assign('show_adds',
            (in_array($account->client,
                    ['all4net', 'wellconnect']) || $account->contract->contragent->legal_type != 'legal'));
        $design->assign('order_type', isset($_GET['tty']) ? $_GET['tty'] : false);
        $design->assign('is_rollback', isset($_GET['is_rollback']) ? true : false);
        $positions["client_id"] = $client_id;
        $this->addArt($positions);
        $design->assign('positions', $positions);
        //$design->assign('pts',$pts);
        $design->assign('hide_tts', true);
        $design->assign('enable_logistic', $enableLogistic);
        $design->AddMain('newaccounts/make_1c_bill.html');
    }

    private function compareForChanges(&$posSave, $posDB)
    {
        $lSave = &$posSave["list"];
        $lDB = &$posDB["list"];

        if (count($lSave) == count($lDB)) {
            $isEqual = true;
            foreach ($lSave as $idx => $s) {
                $d = $lDB[$idx];
                foreach (["id", "quantity", "price", "discount_set", "discount_auto"] as $f) {
                    if ($f == "id") {
                        [$id, $descrId] = explode(":", $s[$f]);
                        if ($descrId == "") {
                            $descrId = "00000000-0000-0000-0000-000000000000";
                        }
                        $s[$f] = $id . ":" . $descrId;
                    }
                    if ($f == "price") {
                        $d[$f] = round($d[$f], 2);
                    }
                    if ($s[$f] != $d[$f]) {
                        $isEqual = false;
                        //echo $f.": ".$s[$f]." => ".$d[$f];
                        break 2;
                    }
                }
            }

            if ($isEqual) {
                unset($posSave["list"]);
            }
        }
    }

    function addArt(&$pos)
    {
        global $db;

        foreach ($pos["list"] as &$p) {
            [$gId, $dId] = explode(":", $p["id"] . "::");
            $p["art"] = "";
            if ($gId) {
                $g = $db->GetRow("SELECT art FROM g_goods WHERE id = '" . $gId . "'");
                $p["art"] = $g["art"];
            }
        }
    }


    function newaccounts_rpc_findProduct($fixclient)
    {
        global $db;
        if (!trim($_GET['findProduct'])) {
            exit();
        }

        $telekomGoodIds = [12469];

        $prod = get_param_raw('findProduct');
        if (strlen($prod) >= 1) {
            $ret = "";
            $prod = str_replace(['*', '%%'], ['%', '%'], $db->escape($prod));

            $storeId = get_param_protected('store_id', Store::MAIN_STORE);

            /** @var ClientAccount $account */
            $account = ClientAccount::findOne(['id' => $fixclient]);
            $priceType = $account ? $account->price_type : GoodPriceType::RETAIL;
            $currency = Currency::findOne($account ? $account->currency : Currency::RUB);

            $storeInfo = Store::findOne(['id' => $storeId]);


            foreach ($db->AllRecords($q =
                "
                        SELECT * FROM (
                        (
                        SELECT if(d.name IS NULL, concat(g.id,':'), concat(g.id,':',p.descr_id)) AS id,
                        g.id AS good_id,
                        g.name AS name,
                        if(d.name IS NOT NULL,d.name ,'') AS description,
                        g.group_id, p.descr_id AS descr_id,  p.price, d.name AS descr_name, qty_free, qty_store, qty_wait, is_service,
                        art, num_id AS code, dv.name AS division, store
                        FROM (
                            SELECT * FROM g_goods g1 WHERE g1.art = '" . $prod . "'
                            UNION SELECT * FROM g_goods g1 WHERE g1.num_id = '" . $prod . "'
                            UNION SELECT * FROM g_goods g2 WHERE g2.name LIKE '%" . $prod . "%'
                            ) g
                        LEFT JOIN g_good_price p ON (p.good_id = g.id AND p.currency = '" . $currency . "')
                        LEFT JOIN g_good_description d ON (g.id = d.good_id AND d.id = p.descr_id)
                        LEFT JOIN g_good_store s ON (s.good_id = g.id AND s.descr_id = p.descr_id AND s.store_id = '" . $storeId . "')
                        LEFT JOIN g_division dv ON (g.division_id = dv.id)

                        WHERE price_type_id = '" . $priceType . "'

                        ORDER BY length(g.name)
                        LIMIT 50 )
                        UNION
                        (
                         SELECT  if(d.name IS NULL, concat(g.id,':'), concat(g.id,':',s.descr_id)) AS id,
                         g.id AS good_id,
                        g.name AS name,
                        if(d.name IS NOT NULL,d.name ,'') AS description,
                         g.group_id, '' AS descr_id,   '--- ' AS price, NULL AS descr_name, qty_free, qty_store, qty_wait, is_service,
                         art, num_id AS code, dv.name AS division,store
                         FROM (
                             SELECT * FROM g_goods g1 WHERE g1.art = '" . $prod . "'
                             UNION SELECT * FROM g_goods g1 WHERE g1.num_id = '" . $prod . "'
                             UNION SELECT * FROM g_goods g2 WHERE g2.name LIKE '%" . $prod . "%'
                             ) g
                         LEFT JOIN g_good_store s ON (s.good_id = g.id AND s.store_id = '" . $storeId . "')
                         LEFT JOIN g_good_description d ON (g.id = d.good_id AND d.id = s.descr_id)
                         LEFT JOIN g_division dv ON (g.division_id = dv.id)

                         WHERE  g.is_allowpricezero
                         ORDER BY length(g.name)
                         LIMIT 50
                        )
                        ) a GROUP BY a.id

                        ") as $good) {
                if (strpos($good["name"], "(Архив)") !== false) {
                    continue;
                }
                if (!empty($storeInfo)) {
                    $add_fields = "store_id:'" . addcslashes($storeInfo->id, "\\'") . "'," .
                        "store_name:'" . addcslashes($storeInfo->name, "\\'") . "',";
                } else {
                    $add_fields = '';
                }

                if (in_array($good['code'], $telekomGoodIds)) {
                    $good['name'] .= ' -- ****ПРОДАЖА ОТ МСН ТЕЛЕКОМ**** ';
                }

                $ret .= "{" .
                    "id:'" . addcslashes($good['id'], "\\'") . "'," .
                    "good_id:'" . addcslashes($good['good_id'], "\\'") . "'," .
                    "name:'" . str_replace(["\r", "\n"], "", addcslashes($good['name'], "\\'")) . "'," .
                    "description:'" . addcslashes($good['description'], "\\'") . "'," .
                    "division:'" . addcslashes($good['division'], "\\'") . "'," .
                    "price:'" . addcslashes($good['price'], "\\'") . "'," .
                    "currency:'" . addcslashes($currency->symbol, "\\'") . "'," .
                    "qty_free:'" . addcslashes($good['qty_free'], "\\'") . "'," .
                    "qty_store:'" . addcslashes($good['qty_store'], "\\'") . "'," .
                    "qty_wait:'" . addcslashes($good['qty_wait'], "\\'") . "'," .
                    "art:'" . addcslashes($good['art'], "\\'") . "'," .
                    "code:'" . addcslashes($good['code'], "\\'") . "'," .
                    "store:'" . addcslashes($good['store'], "\\'") . "'," .
                    $add_fields .
                    "is_service:" . ($good['is_service'] ? 'true' : 'false') .
                    "},";
            }
            $ret = "[" . $ret . "]";

        } else {
            $ret = "false";
        }

        header('Content-Type: text/plain; charset="utf-8"');
        echo $ret;
        exit();
    }

    function newaccounts_docs($fixclient)
    {
        global $db, $design;

        $R = [];

        $dateFrom = new DatePickerValues('date_from', 'today');
        $dateTo = new DatePickerValues('date_to', 'today');
        $dateFrom->format = 'Y-m-d';
        $dateTo->format = 'Y-m-d';
        $from = $dateFrom->getDay();
        $to = $dateTo->getDay();

        if (get_param_raw("do", "") != "") {

            $from = @strtotime($from . " 00:00:00");
            $to = @strtotime($to . " 23:59:59");

            if (!$from || !$to || ($from > $to)) {
                $from = date("Y-m-d");
                $to = date("Y-m-d");
                // nothing
            } else {
                $R = $db->Allrecords("SELECT * FROM qr_code WHERE date BETWEEN '" . date("Y-m-d H:i:s",
                        $from) . "' AND '" . date("Y-m-d H:i:s", $to) . "' ORDER BY file, date");
                $from = date("Y-m-d", $from);
                $to = date("Y-m-d", $to);
            }

        } else {
            $this->_qrDocs_check();
        }

        $idx = 1;
        foreach ($R as &$r) {
            $r["idx"] = $idx++;
            $r["ts"] = $r["date"];


            $qNo = BillQRCode::decodeNo($r["code"]);

            $r["type"] = $qNo ? $qNo["type"]["name"] : "????";
            $r["number"] = $qNo ? $qNo["number"] : "????";

            $num = "";

            if ($pos = strrpos($r["file"], "_")) {
                $num = substr($r["file"], $pos + 1);
                if ($pos = strpos($num, ".")) {
                    $num = substr($num, 0, strlen($num) - $pos - 1);
                }
            }
            $r["prefix"] = $num ?: "";


        }

        $design->assign("data", $R);
        $design->assign("from", $from);
        $design->assign("to", $to);
        $design->AddMain("newaccounts/docs.html");

    }

    function _qrDocs_check()
    {
        global $db;

        if (!defined("SCAN_DOC_DIR")) {
            throw new Exception("Директория с отсканированными документами не задана");
        }

        $dir = SCAN_DOC_DIR;

        if (!is_dir($dir)) {
            throw new Exception("Директория с отсканированными документами задана неверно (" . SCAN_DOC_DIR . ")");
        }

        if (!is_readable($dir)) {
            throw new Exception("В директорию с документами доступ запрещен");
        }


        $d = dir($dir);

        $c = 0;

        $docs = [];
        while ($e = $d->read()) {
            if ($e == ".." || $e == ".") {
                continue;
            }
            if (stripos($e, ".pdf") === false) {
                exec("rm " . $dir . $e);
                continue;
            }
            $docs[] = $e;
        }

        sort($docs);

        foreach ($docs as $e) {
            $qrcode = BillQRCode::decodeFile($dir . $e);
            $qr = BillQRCode::decodeNo($qrcode);

            if ($qrcode && $qr) {
                $billNo = $qr["number"];
                $clientId = Bill::find()->where(['bill_no' => $billNo])->select('client_id')->scalar();
                $type = $qr["type"]["code"];

                $id = $db->QueryInsert("qr_code", [
                    "file" => $e,
                    "code" => $qrcode,
                    "bill_no" => $billNo,
                    "client_id" => $clientId,
                    "doc_type" => $type
                ]);

                exec("mv " . $dir . $e . " " . STORE_PATH . "documents/" . $id . ".pdf");
            } else {
                exec("mv " . $dir . $e . " " . STORE_PATH . "documents/unrecognized/" . $e);
            }


        }
        $d->close();
    }

    function newaccounts_docs_unrec($fixclient)
    {
        global $design;

        $dirPath = STORE_PATH . "documents/unrecognized/";

        if (!is_dir($dirPath)) {
            throw new Exception("Директория с нераспознаными документами задана неверно (" . $dirPath . ")");
        }

        if (!is_readable($dirPath)) {
            throw new Exception("В директорию с нераспознаными документами доступ запрещен");
        }

        if (($delFile = get_param_raw("del", "")) !== "") {
            if (file_exists($dirPath . $delFile)) {
                exec("rm " . $dirPath . $delFile);
            }
        }

        if (get_param_raw("recognize", "") == "true") {
            $this->docs_unrec__recognize();
        }

        $d = dir($dirPath);

        $c = 0;
        $R = [];
        while ($e = $d->read()) {
            if ($e == ".." || $e == ".") {
                continue;
            }
            if (stripos($e, ".pdf") === false) {
                continue;
            }

            $R[] = $e;
        }
        $d->close();

        $docType = [];
        foreach (BillQRCode::$codes as $code => $c) {
            $docType[$code] = $c["name"];
        }

        $design->assign("docs", $R);
        $design->assign("doc_type", $docType);
        $design->AddMain("newaccounts/docs_unrec.html");
    }

    function docs_unrec__recognize()
    {
        $dirDoc = STORE_PATH . "documents/";
        $dirUnrec = $dirDoc . "unrecognized/";

        $file = get_param_raw("file", "");
        if (!file_exists($dirUnrec . $file)) {
            trigger_error2("Файл не найден!");
            return;
        }

        $type = get_param_raw("type", "");
        if (!isset(BillQRCode::$codes[$type])) {
            trigger_error2("Ошибка в типе!");
            return;
        }

        $number = get_param_raw("number", "");
        if (!preg_match("/^201\d{3}[-\/]\d{4,6}$/", $number)) {

            if (!preg_match('/^(1|2)(1|2)\d{5}-\d{4,5}$/', $number)) {

                trigger_error2("Ошибка в номере!");
                return;
            }

            $invoice = Invoice::findOne(['number' => $number]);

            if (!$invoice) {
                trigger_error2("Ошибка в номере!");
                return;
            }

            $number = $invoice->bill_no;

        }

        global $db;


        $qrcode = BillQRCode::encode($type, $number);
        $qr = BillQRCode::decodeNo($qrcode);

        $billNo = "";
        $clientId = 0;
        $type = "";

        if ($qr) {
            $billNo = $qr["number"];
            $clientId = Bill::find()->where(['bill_no' => $billNo])->select('client_id')->scalar();
            $type = $qr["type"]["code"];
        }

        $id = $db->QueryInsert("qr_code", [
            "file" => $file,
            "code" => $qrcode,
            "bill_no" => $billNo,
            "client_id" => $clientId,
            "doc_type" => $type
        ]);


        exec("mv " . $dirUnrec . $file . " " . $dirDoc . $id . ".pdf");
    }

    function newaccounts_doc_file($fixclient)
    {
        $dirPath = STORE_PATH . "documents/";

        if (get_param_raw("unrecognized", "") == "true") {
            $file = get_param_raw("file", "");
            $fPath = $dirPath . "unrecognized/" . $file;

            $this->docs_echoFile($fPath, $file);
        } elseif (($id = get_param_integer("id", 0)) !== 0) {
            global $db;

            $r = $db->GetValue("SELECT file FROM qr_code WHERE id = '" . $id . "'");

            if ($r) {
                $this->docs_echoFile($dirPath . $id . ".pdf", $r);
            }
        }
    }

    function docs_echoFile($fPath, $fileName)
    {
        if (file_exists($fPath)) {
            header("Content-Type:application/pdf");
            header('Content-Transfer-Encoding: binary');
            header('Content-Disposition: attachment; filename="' . iconv("UTF-8", "CP1251", $fileName) . '"');
            header("Content-Length: " . filesize($fPath));
            echo file_get_contents($fPath);
            exit();
        } else {
            trigger_error2("Файл не найден!");
        }
        //
    }

    function newaccounts_doc_file_delete($fixclient)
    {
        $dirPath = STORE_PATH . "documents/";

        if (($id = get_param_integer("id", 0)) !== 0) {
            global $db;

            if ($db->Query("DELETE FROM qr_code WHERE id = '" . $id . "'")) {
                if (file_exists($dirPath . $id . ".pdf")) {
                    unlink($dirPath . $id . ".pdf");
                }
                echo 'ok';
            } else {
                echo 'Ошибка удаления!';
            }


        } else {
            echo 'Файл не задан!';
        }

        exit();
    }

    private function getBillBonus($billNo)
    {
        global $db;

        $r = [];
        $q = $db->AllRecords("
                SELECT code_1c AS code, round(if(b.type = '%', bl.sum*0.01*`value`, `value`*amount),2) AS bonus
                FROM newbill_lines bl
                INNER JOIN g_bonus b ON b.good_id = bl.item_id
                    AND `group` = (SELECT if(usergroup='account_managers', 'manager', usergroup) FROM newbill_owner nbo, user_users u WHERE nbo.bill_no = bl.bill_no AND u.id=nbo.owner_id) WHERE bl.bill_no='" . $billNo . "'");
        if ($q) {
            foreach ($q as $l) {
                $r[$l["code"]] = $l["bonus"];
            }
        }
        return $r;
    }

    function newaccounts_recalc_entry($fixclient)
    {
        global $fixclient_data;

        if (!$fixclient_data || !$fixclient_data->id) {
            trigger_error2('Выберите клиента');
            return;
        }

        /** @var ClientAccount $account */
        $account = $fixclient_data;

        $accountTariffIds = \app\modules\uu\models\AccountTariff::find()->where(['client_account_id' => $account->id])->select('id')->column();
        $date = (new DateTime('now'))->modify('first day of this month')->format(DateTimeZoneHelper::DATE_FORMAT);

        set_time_limit(0);
        session_write_close();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        echo '<pre>';

        $params = [];
        $query = \Yii::$app->db->queryBuilder->delete(\app\modules\uu\models\AccountLogResource::tableName(), [
            'AND',
            ['account_tariff_id' => $accountTariffIds],
            ['>=', 'date_from', $date]
        ], $params);
        echo PHP_EOL . 'AccountLogResource: ' . Yii::$app->db->createCommand($query, $params)->execute() .' row deleted';
        ob_flush();


        $params = [];
        $query = 'DELETE FROM ' . \app\modules\uu\models\AccountEntry::tableName() . ' WHERE ' . \app\modules\uu\models\AccountEntry::getDb()->queryBuilder->buildCondition([
                'AND',
                ['account_tariff_id' => $accountTariffIds],
                ['>', 'type_id', 0],
                ['>=', 'date', $date]
            ], $params);


        echo PHP_EOL . 'AccountEntry: ' . Yii::$app->db->createCommand($query, $params)->execute() .' row deleted';
        ob_flush();


        $accountTariffs = \app\modules\uu\models\AccountTariff::findAll(['id' => $accountTariffIds]);

        foreach($accountTariffs as $accountTariff) {
            $accountTariff->account_log_resource_utc = null;
            if (!$accountTariff->save()) {
                throw new ModelValidationException($accountTariff);
            }

            echo PHP_EOL . $accountTariff->getName() . \app\modules\uu\behaviors\AccountTariffBiller::recalc([
                'account_tariff_id' => $accountTariff->id,
                'client_account_id' => $accountTariff->client_account_id,
            ], true);
            ob_flush();
        }



        echo '</pre>';

        exit();
    }

    public function newaccounts_create_draft($fixclient)
    {
        $invoiceId = get_param_protected('invoice_id');
        $billNo = get_param_protected('bill');
        $invoice = Invoice::findOne(['id' => $invoiceId]);

        if (!$invoice) {
            throw new InvalidArgumentException('Invoice not found');
        }

        \Yii::$app->session->addFlash('success', 'Сгенерирован черновик документа в СБИС по с/ф №' . $invoice->number);
        \app\modules\sbisTenzor\helpers\SBISDataProvider::checkInvoiceForExchange($invoice->id);

        header('Location: /index.php?module=newaccounts&action=bill_view&bill=' . urlencode($invoice->bill_no));
        exit;
    }
}