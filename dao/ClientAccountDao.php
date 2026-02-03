<?php

namespace app\dao;

use app\classes\api\ApiPhone;
use app\classes\Assert;
use app\classes\HandlerLogger;
use app\classes\Singleton;
use app\dao\account\UpdateBalanceHelper;
use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\Bill;
use app\models\Business;
use app\models\ClientAccount;
use app\models\ClientContract;
use app\models\ClientContragent;
use app\models\GoodsIncomeOrder;
use app\models\important_events\ImportantEvents;
use app\models\important_events\ImportantEventsNames;
use app\models\Invoice;
use app\models\Param;
use app\models\Payment;
use app\models\PaymentOrder;
use app\models\Saldo;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\ServiceType;
use app\modules\uu\tarificator\RealtimeBalanceTarificator;
use app\modules\uu\tarificator\RealtimeBalanceTarificatorWithSaldo;
use DateTime;
use DateTimeZone;
use Yii;
use yii\db\Exception;
use yii\db\Query;

/**
 * @method static ClientAccountDao me($args = null)
 */
class ClientAccountDao extends Singleton
{

    private $_voipNumbers;

    /**
     * @param ClientAccount $clientAccount
     * @return string
     */
    public function getLastBillDate(ClientAccount $clientAccount)
    {
        $billDate = ClientAccount::getDb()->createCommand('
                select max(b.bill_date)
                from newbills b, newbill_lines bl
                where
                        b.client_id=:clientAccountId
                    and day(b.bill_date) = 1
                    and b.bill_no=bl.bill_no
                    and bl.id_service > 0
                    and biller_version = :billerVersion
            ',
            [
                ':clientAccountId' => $clientAccount->id,
                ':billerVersion' => ClientAccount::VERSION_BILLER_USAGE
            ]
        )->queryScalar();

        if (!$billDate) {
            $billDate = '2000-01-01';
        }

        $billDate = new DateTime($billDate, $clientAccount->timezone);
        $billDate->setTimezone(new DateTimeZone('UTC'));

        return $billDate->format(DateTimeZoneHelper::DATETIME_FORMAT);
    }

    /**
     * @param ClientAccount $clientAccount
     * @return false|null|string
     */
    public function getLastPayedBillMonth(ClientAccount $clientAccount)
    {
        return ClientAccount::getDb()->createCommand('
                select b.bill_date - interval day(b.bill_date)-1 day
                from newbills b left join newbill_lines bl on b.bill_no=bl.bill_no
                where b.client_id=:clientAccountId and b.is_payed=1 and bl.id_service > 0 and biller_version = :billerVersion
                group by b.bill_date
                order by b.bill_date desc
                limit 1
            ',
            [
                ':clientAccountId' => $clientAccount->id,
                ':billerVersion' => ClientAccount::VERSION_BILLER_USAGE
            ]
        )->queryScalar();
    }

    /**
     * @param string $search
     * @param int|null $exceptClientAccountId
     * @param int|null $clientAccountVersion
     * @return Query
     */
    public function clientAccountSearch($search, $exceptClientAccountId = null, $clientAccountVersion = null)
    {
        $query = (new Query)
            ->select([
                'client.id',
                'contragent.name',
                'client.account_version',
            ])
            ->from(['client' => ClientAccount::tableName()])
            ->innerJoin(['contract' => ClientContract::tableName()], 'contract.id = client.contract_id')
            ->innerJoin(['contragent' => ClientContragent::tableName()], 'contragent.id = contract.contragent_id')
            ->where([
                'OR',
                ['LIKE', 'client.client', $search],
                ['client.id' => $search],
                ['LIKE', 'contragent.name', $search],
            ])
            ->orderBy([
                'contragent.name' => SORT_DESC,
                'client.id' => SORT_DESC,
            ])
            ->limit(10);

        $exceptClientAccountId && $query->andWhere(['!=', 'client.id', (int)$exceptClientAccountId]);
        $clientAccountVersion && $query->andWhere(['client.account_version' => $clientAccountVersion]);

        return $query;
    }

    /**
     * Обновление баланса ЛС
     *
     * @param int $clientAccountId
     * @param bool $isForce
     * @throws \yii\db\Exception
     */
    public function updateBalance($clientAccountId, $isForce = true)
    {
        if (!$isForce && Param::findOne(Param::DISABLING_RECALCULATION_BALANCE_WHEN_EDIT_BILL)) {
            return;
        }

        if ($clientAccountId instanceof ClientAccount) {
            $clientAccount = $clientAccountId;
        } else {
            $clientAccount = ClientAccount::findOne(['id' => $clientAccountId]);
        }

        Assert::isObject($clientAccount);

        $saldo = $this->_getSaldo($clientAccount);

        $R1 = $this->_enumBillsFullSum($clientAccount, $saldo['ts']);
        $R2 = $this->_enumPayments($clientAccount, $saldo['ts']);
        $invoiceAll = $this->_getInvoices($clientAccount->id);


        $sum = -$saldo['saldo'];

        $balance = 0;

        if ($sum > 0) {
//            array_unshift($R2, [
//                'id' => '0',
//                'client_id' => $clientAccount->id,
//                'payment_no' => 0,
//                'bill_no' => 'saldo',
//                'bill_vis_no' => 'saldo',
//                'payment_date' => $saldo['ts'],
//                'oper_date' => $saldo['ts'],
//                'comment' => '',
//                'add_date' => $saldo['ts'],
//                'add_user' => 0,
//                'sum' => $sum,
//                'is_billpay' => 0,
//            ]);
        } elseif ($sum < 0) {
//            array_unshift($R1, [
//                'bill_no' => 'saldo',
//                'is_payed' => 1,
//                'sum' => -$sum,
//                'new_is_payed' => 0,
//            ]);
        }

        $paymentsOrders = [];

        foreach ($R1 as $r) {
            $balance = $balance - $r['sum'];
        }

        foreach ($R2 as $r) {
            if (!$r['is_billpay']) {
                $balance = $balance + $r['sum'];
            }
        }

        // Цикл оплачивает минусовые счета
        foreach ($R2 as $kp => $r) {
            if ($r['sum'] >= 0) {
                continue;
            }

            $bill_no = $r['bill_no'];

            if (isset($R1[$bill_no])) {
                $sum = $r['sum'];

                $paymentsOrders[] = [
                    'payment_id' => $r['id'],
                    'bill_no' => $bill_no,
                    'sum' => $sum,
                ];

                $R1[$bill_no]['sum'] -= $sum;

                $R2[$kp]['sum'] = 0;
            }
        }

        // в магазине оплата привязывается с счету, у всех остальных - привязки нет - оплата размазывается
        if ($clientAccount->contract->business_id == Business::INTERNET_SHOP) { // магазин
            // Цикл оплачивает счета для которых существует оплата с жестко указанным номером счета
            foreach ($R2 as $kp => $r) {
                if ($r['sum'] < 0.01) {
                    continue;
                }

                if ($r['bill_no'] == '') {
                    continue;
                }

                $bill_no = $r['bill_no'];

                if (isset($R1[$bill_no]) && ($R1[$bill_no]['new_is_payed'] == 0 || $R1[$bill_no]['new_is_payed'] == 2) && $R1[$bill_no]['sum'] >= 0) {
                    if ($this->_sumMore($r['sum'], $R1[$bill_no]['sum'])) {
                        $sum = round($R1[$bill_no]['sum'], 2);

                        $paymentsOrders[] = [
                            'payment_id' => $r['id'],
                            'bill_no' => $bill_no,
                            'sum' => $sum,
                        ];


                        $R2[$kp]['sum'] -= $sum;

                        if ($R2[$kp]['sum'] < 0.01) {
                            $R2[$kp]['sum'] = 0;
                        }

                        $R1[$bill_no]['new_is_payed'] = 1;
                        $R1[$bill_no]['sum'] = 0;

                    } elseif ($r['sum'] >= 0.01) {

                        $sum = $r['sum'];

                        $paymentsOrders[] = [
                            'payment_id' => $r['id'],
                            'bill_no' => $bill_no,
                            'sum' => $sum,
                        ];

                        $R2[$kp]['sum'] = 0;

                        $R1[$bill_no]['new_is_payed'] = 2;
                        $R1[$bill_no]['sum'] -= $sum;

                        if ($R1[$bill_no]['sum'] < 0.01) {
                            $R1[$bill_no]['sum'] = 0;
                            $R1[$bill_no]['new_is_payed'] = 1;
                        }
                    }
                }
            }

            // Цикл оплачивает счета для которых существует оплата с жестко указанным номером счета ПРИВЯЗКИ.
            // Новых счетов с привязкой не будет. Нужно для совместимости
            foreach ($R2 as $kp => $r) {
                if ($r['sum'] < 0.01) {
                    continue;
                }

                $bill_no = $r['bill_vis_no'];

                if (isset($R1[$bill_no]) && ($R1[$bill_no]['new_is_payed'] == 0 || $R1[$bill_no]['new_is_payed'] == 2) && $R1[$bill_no]['sum'] >= 0) {
                    if ($this->_sumMore($r['sum'], $R1[$bill_no]['sum'])) {
                        $sum = round($R1[$bill_no]['sum'], 2);

                        if (abs($sum) >= 0.01) {
                            $paymentsOrders[] = [
                                'payment_id' => $r['id'],
                                'bill_no' => $bill_no,
                                'sum' => $sum,
                            ];
                        }


                        $R2[$kp]['sum'] -= $sum;

                        if ($R2[$kp]['sum'] < 0.01) {
                            $R2[$kp]['sum'] = 0;
                        }


                        $R1[$bill_no]['new_is_payed'] = 1;
                        $R1[$bill_no]['sum'] = 0;

                    } elseif ($r['sum'] >= 0.01) {
                        $sum = $r['sum'];

                        if (abs($sum) >= 0.01) {
                            $paymentsOrders[] = [
                                'payment_id' => $r['id'],
                                'bill_no' => $bill_no,
                                'sum' => $sum,
                            ];
                        }

                        $R2[$kp]['sum'] = 0;

                        $R1[$bill_no]['new_is_payed'] = 2;
                        $R1[$bill_no]['sum'] -= $sum;

                        if ($R1[$bill_no]['sum'] < 0.01) {
                            $R1[$bill_no]['sum'] = 0;
                            $R1[$bill_no]['new_is_payed'] = 1;
                        }
                    }
                }
            }
        } else { // не магазин

            // Раскидываем остатки оплаты по неоплаченным счетам
            foreach ($R2 as $kp => $r) {
                if ($r['sum'] < 0.01) {
                    continue;
                }

                foreach ($R1 as $kb => $rb) {

                    if ($rb['new_is_payed'] == 1 || $rb['new_is_payed'] == 3 || $rb['sum'] < 0 || $r['sum'] < 0.01) {
                        continue;
                    }

                    if ($this->_sumMore($r['sum'], $rb['sum'])) {

                        $sum = $rb['sum'];

                        if (abs($sum) >= 0.01) {
                            $paymentsOrders[] = [
                                'payment_id' => $r['id'],
                                'bill_no' => $rb['bill_no'],
                                'sum' => $sum,
                            ];
                        }


                        $r['sum'] -= $sum;
                        $R2[$kp]['sum'] -= $sum;

                        if ($R2[$kp]['sum'] < 0.01) {
                            $R2[$kp]['sum'] = 0;
                            $r['sum'] = 0;
                        }


                        $R1[$kb]['new_is_payed'] = 1;
                        $R1[$kb]['sum'] = 0;

                    } elseif ($r['sum'] >= 0.01) {

                        $sum = $r['sum'];

                        if (abs($sum) >= 0.01) {
                            $paymentsOrders[] = [
                                'payment_id' => $r['id'],
                                'bill_no' => $rb['bill_no'],
                                'sum' => $sum,
                            ];
                        }

                        $r['sum'] = 0;
                        $R2[$kp]['sum'] = 0;

                        $R1[$kb]['new_is_payed'] = 2;
                        $R1[$kb]['sum'] -= $sum;

                    }
                }
            }

            // Если все счета оплачены и осталась лишняя оплата то в любом случае закидываем ее на последний счет, даже если будет переплата.
            $last_payment = null;
            foreach ($R1 as $k => $r) {

                if (($r['new_is_payed'] == 0 || $r['new_is_payed'] == 2) && $this->_sumMore(0, $r['sum'], 1)) {
                    $R1[$k]['new_is_payed'] = 1;
                }

                if ($r['sum'] < 0) {
                    $R1[$k]['new_is_payed'] = 1;
                }

                $last_payment = $r;
            }

            foreach ($R2 as $k => $v) {
                if ($v['sum'] == 0) {
                    continue;
                }

                $sum = $v['sum'];

                if (abs($sum) >= 0.01) {
                    $paymentsOrders[] = [
                        'payment_id' => $v['id'],
                        'bill_no' => $last_payment['bill_no'],
                        'sum' => $sum,
                    ];
                }
            }
        }


        $paysAll = $this->_enumPayments($clientAccount, $saldo['ts'], true);
        $paysIncome = array_filter($paysAll, function ($p) {
            return !isset($p['payment_type']) || $p['payment_type'] != Payment::PAYMENT_TYPE_OUTCOME;
        });

        $invoiceCleared = array_filter($invoiceAll, fn($inv) => !isset($inv['_is_reversed']));
        $invoiceRejected = array_filter($invoiceAll, fn($inv) => isset($inv['_is_reversed']));

        UpdateBalanceHelper::mergePaymentIntoBills($invoiceCleared, $paysIncome);


        $transaction = Bill::getDb()->beginTransaction();

        UpdateBalanceHelper::saveInvoicesIfPayed($invoiceCleared);
        UpdateBalanceHelper::saveInvoicesRejected($invoiceRejected);

        $savedBills = [];

        foreach ($R1 as $billNo => $v) {
            if ($v['bill_no'] == 'saldo') {
                continue;
            }

            if ($v['is_payed'] != $v['new_is_payed']) {
                $documentType = Bill::dao()->getDocumentType($billNo);
                if ($documentType['type'] == 'bill') {
                    /** @var Bill $bill */
                    $bill = Bill::findOne(['bill_no' => $billNo]);
                    if ($bill->is_payed != $v['new_is_payed']) {
                        $bill->is_payed = $v['new_is_payed'];
                        $savedBills[$bill->bill_no] = 1;
                        if (!$bill->save()) {
                            throw new ModelValidationException($bill);
                        }
                    }
                } elseif ($documentType['type'] == 'incomegood') {
                    $order = GoodsIncomeOrder::findOne(['number' => $billNo]);
                    $order->is_payed = $v['new_is_payed'];
                    $order->save();
                }
            }
        }

        // проверяем изменение оплаты счета
        $savedPaymentOrders = PaymentOrder::find()
            ->select(['sum', 'bill_no'])
            ->where(['client_id' => $clientAccount->id])
            ->indexBy('bill_no')
            ->column();

        $resortPaymentOrders = [];
        foreach ($paymentsOrders as $order) {
            if (!isset($order['bill_no']) || !$order['bill_no']) {
                continue;
            }

            if (!isset($resortPaymentOrders[$order['bill_no']])) {
                $resortPaymentOrders[$order['bill_no']] = $order;
            } else {
                $resortPaymentOrders[$order['bill_no']]['sum'] += $order['sum'];
            }
        }

        $batchInsertPaymentOrders = array_map(function ($order) use ($clientAccount) {
            return [$order['payment_id'], $order['bill_no'], $clientAccount->id, $order['sum']];
        }, $resortPaymentOrders);


        // пересохранение PaymentOrder
        PaymentOrder::deleteAll(['client_id' => $clientAccount->id]);

        if ($batchInsertPaymentOrders) {
            Yii::$app->db->createCommand()
                ->batchInsert(
                    PaymentOrder::tableName(),
                    ['payment_id', 'bill_no', 'client_id', 'sum'],
                    $batchInsertPaymentOrders
                )->execute();
        }


        // проверка изменения частичной оплаты счета
        foreach (array_intersect(array_keys($savedPaymentOrders), array_keys($resortPaymentOrders)) as $billNo) {
            if (isset($savedBills[$billNo])) {
                continue;
            }

            if ($savedPaymentOrders[$billNo] == $resortPaymentOrders[$billNo]['sum']) {
                continue;
            }

            $bill = Bill::findOne(['bill_no' => $billNo]);

            if ($bill) {
                $bill->trigger(Bill::TRIGGER_CHECK_OVERDUE);
                if ($bill->isSetPayOverdue !== null) {
                    if (!$bill->save()) {
                        throw new ModelValidationException($bill);
                    }
                }
            }
        }

        if ($clientAccount->account_version == ClientAccount::VERSION_BILLER_UNIVERSAL) {

            (new RealtimeBalanceTarificator)->tarificate($clientAccount->id);

        } else {

            $lastBillDate = ClientAccount::dao()->getLastBillDate($clientAccount);
            $lastPayedBillMonth = ClientAccount::dao()->getLastPayedBillMonth($clientAccount);

            $p = [
                ':clientAccountId' => $clientAccount->id,
                ':balance' => $balance,
                ':lastBillDate' => $lastBillDate,
                ':lastPayedBillMonth' => $lastPayedBillMonth
            ];

            ClientAccount::getDb()
                ->createCommand('
                UPDATE clients
                SET balance = :balance,
                    last_account_date = :lastBillDate,
                    last_payed_voip_month = :lastPayedBillMonth
                WHERE id = :clientAccountId', $p
                )
                ->execute();
        }

        $transaction->commit();
    }

    /**
     * Обновление баланса ЛС
     *
     * @param int $clientAccountId
     * @param bool $isForce
     * @throws \yii\db\Exception
     */
    public function updateBalanceNew($clientAccountId, $isForce = true)
    {
        if (!$isForce && Param::findOne(Param::DISABLING_RECALCULATION_BALANCE_WHEN_EDIT_BILL)) {
            return;
        }

        $clientAccount = $clientAccountId instanceof ClientAccount ? $clientAccountId : ClientAccount::findOne(['id' => $clientAccountId]);

        Assert::isObject($clientAccount);

        $saldo = $this->_getSaldo($clientAccount);

        $billsAll = $this->_enumBillsFullSum($clientAccount, $saldo['ts']);
        $paysAll = $this->_enumPayments($clientAccount, $saldo['ts'], true);
        $invoiceAll = $this->_getInvoices($clientAccount->id);

        $sum = -$saldo['saldo'];

        $balance = 0;

        if ($sum > 0) {
            array_unshift($paysAll, [
                'id' => '0',
                'client_id' => $clientAccount->id,
                'payment_no' => 0,
                'bill_no' => 'saldo',
                'bill_vis_no' => 'saldo',
                'payment_date' => $saldo['ts'],
                'oper_date' => $saldo['ts'],
                'comment' => '',
                'add_date' => $saldo['ts'],
                'add_user' => 0,
                'sum' => $sum,
            ]);
        } elseif ($sum < 0) {
            array_unshift($billsAll, [
                'bill_no' => 'saldo',
                'is_payed' => 1,
                'sum' => -$sum,
                'new_is_payed' => 0,
            ]);
        }


        $paysOutcome = array_filter($paysAll, fn($p) => isset($p['payment_type']) && $p['payment_type'] == Payment::PAYMENT_TYPE_OUTCOME);
        $paysIncome = array_filter($paysAll, fn($p) => !isset($p['payment_type']) || $p['payment_type'] != Payment::PAYMENT_TYPE_OUTCOME);

        $billsPlus = array_filter($billsAll, fn($b) => $b['sum'] >= 0);
        $billsMinus = array_filter($billsAll, fn($b) => $b['sum'] < 0);

        $invoiceCleared = array_filter($invoiceAll, fn($inv) => !isset($inv['_is_reversed']));
        $invoiceRejected = array_filter($invoiceAll, fn($inv) => isset($inv['_is_reversed']));

        UpdateBalanceHelper::mergePaymentIntoBills($invoiceCleared, $paysIncome);
        UpdateBalanceHelper::mergePaymentIntoBills($billsPlus, $paysIncome);
        UpdateBalanceHelper::mergePaymentIntoBills($billsMinus, $paysOutcome);

        $plusPaymentOrders = UpdateBalanceHelper::paymentOrders_extractFromBills($billsPlus);
        $minusPaymentOrders = UpdateBalanceHelper::paymentOrders_extractFromBills($billsMinus);

        $paymentOrders = array_merge($plusPaymentOrders, $minusPaymentOrders);


        $transaction = Bill::getDb()->beginTransaction();

//        \Yii::$app->db->createCommand("update newbills set is_payed=0, payment_date = null where client_id={$clientAccountId} and sum < 0")->execute();
        UpdateBalanceHelper::paymentOrders_save($clientAccountId, $paymentOrders);

        $bills = array_merge($billsPlus, $billsMinus);
        UpdateBalanceHelper::saveBillIfPayed($bills);
        UpdateBalanceHelper::saveInvoicesIfPayed($invoiceCleared);
        UpdateBalanceHelper::saveInvoicesRejected($invoiceRejected);


        if ($clientAccount->account_version == ClientAccount::VERSION_BILLER_UNIVERSAL) {
            (new RealtimeBalanceTarificatorWithSaldo())->tarificate($clientAccount->id);
        } else {
            $fnGetSum = function ($a) {
                return $a['sum'];
            };
            $balance -= array_sum(array_map($fnGetSum, $billsAll));
            $balance += array_sum(array_map($fnGetSum, $paysAll));


            $lastBillDate = ClientAccount::dao()->getLastBillDate($clientAccount);
            $lastPayedBillMonth = ClientAccount::dao()->getLastPayedBillMonth($clientAccount);

            $p = [
                ':clientAccountId' => $clientAccount->id,
                ':balance' => $balance,
                ':lastBillDate' => $lastBillDate,
                ':lastPayedBillMonth' => $lastPayedBillMonth
            ];

            ClientAccount::getDb()
                ->createCommand('
                UPDATE clients
                SET balance = :balance,
                    last_account_date = :lastBillDate,
                    last_payed_voip_month = :lastPayedBillMonth
                WHERE id = :clientAccountId', $p
                )
                ->execute();
        }

        $transaction->commit();
    }

    /**
     * @param ClientAccount $clientAccount
     * @return array
     */
    private function _getSaldo(ClientAccount $clientAccount)
    {
        $saldo = Saldo::find()
            ->andWhere([
                'client_id' => $clientAccount->id,
                'is_history' => 0,
                'currency' => $clientAccount->currency
            ])
            ->orderBy('id desc')
            ->limit(1)
            ->one();

        if ($saldo) {
            return ['ts' => $saldo->ts, 'saldo' => $saldo->saldo];
        }

        return ['ts' => 0, 'saldo' => 0];
    }

    /**
     * @param ClientAccount $clientAccount
     * @param string $saldoDate
     * @return array
     */
    private function _enumBillsFullSum(ClientAccount $clientAccount, $saldoDate)
    {
        $sql = '
            SELECT * FROM (
                SELECT
                    B.bill_no,
                    B.bill_date,
                    B.currency as currency,
                    B.is_payed,
                    B.sum,
                    ' . ($saldoDate ? ' CASE B.bill_date>="' . $saldoDate . '" WHEN true THEN 0 ELSE 3 END ' : '0') . ' as new_is_payed
                FROM
                    newbills B
                WHERE
                    B.client_id = :clientAccountId
                    and B.currency = :currency
                    and B.bill_date >= :saldoDate
                    and B.is_show_in_lk

                UNION

                SELECT
                    G.number as bill_no,
                    cast(G.date as date) bill_date,
                    currency as currency,
                    G.is_payed,
                    G.sum,
                    ' . ($saldoDate ? ' CASE G.date>="' . $saldoDate . '" WHEN true THEN 0 ELSE 3 END ' : '0') . ' as new_is_payed

                  FROM g_income_order G
                  WHERE
                        G.client_card_id = :clientAccountId
                    and G.currency = :currency
                    and G.date >= :saldoDate
                    and G.deleted = 0
                    and G.active = 1
                    and if (40 != ifnull((
                        select 
                            state_id 
                        from 
                            tt_troubles t, 
                            tt_stages s 
                        WHERE 
                                G.id = t.bill_id 
                            AND s.stage_id = t.cur_stage_id)
                       , 40), true, false)

                
            


            ) as B

            GROUP BY B.bill_no, B.is_payed, B.sum, B.bill_date, B.currency
            ORDER BY bill_date asc, bill_no asc';

        $bills = Bill::getDb()->createCommand(
            $sql,
            [
                ':clientAccountId' => $clientAccount->id,
                ':currency' => $clientAccount->currency,
                ':saldoDate' => $saldoDate,
            ]
        )
            ->queryAll();

        $result = [];
        foreach ($bills as $bill) {
            $result[$bill['bill_no']] = $bill;
        }

        return $result;
    }

    /**
     * @param ClientAccount $clientAccount
     * @param string $saldoDate
     * @return array
     */
    private function _enumPayments(ClientAccount $clientAccount, $saldoDate, $isOnlyPayments = false)
    {
        $sql = '
            select P.*, 0 as is_billpay  
            from newpayments as P
            left join newbills as B ON P.client_id = B.client_id
            where
                P.client_id = :clientAccountId
                and P.payment_date >= :saldoDate
                and B.bill_no IS NULL

            UNION

            select P.*, 0 as is_billpay
            from newpayments as P
            left join newbills as B ON P.client_id = B.client_id
            where
                P.client_id = :clientAccountId
                and B.bill_no=P.bill_no
                and B.currency = :currency
                and B.bill_date >= :saldoDate
            order by payment_date asc
        ';

        $payments = Bill::getDb()->createCommand(
            $sql,
            [
                ':clientAccountId' => $clientAccount->id,
                ':currency' => $clientAccount->currency,
                ':saldoDate' => $saldoDate
            ]
        )->queryAll();

        $paymentsById = [];
        foreach ($payments as $payment) {
            $paymentsById[$payment['id']] = $payment;
        }

        if ($isOnlyPayments) {
            return $paymentsById;
        }

        $sql = "
                        SELECT B.*
                        FROM newbills as B
                        LEFT JOIN clients as C ON C.id = B.client_id
                        WHERE
                            B.client_id = :clientAccountId
                            and B.bill_date >= :saldoDate
                            and B.sum < 0
                            and B.currency = 'RUB'
                            and C.status NOT IN ('operator', 'distr')
        ";

        $billPayments = Bill::getDb()->createCommand(
            $sql,
            [
                ':clientAccountId' => $clientAccount->id,
                ':saldoDate' => $saldoDate,
            ]
        )->queryAll();

        $paymentsAndChargebacks = [];
        foreach ($paymentsById as $v) {
            foreach ($paymentsById as $v2) {
                if ($v['bill_no'] == $v2['bill_no'] && $v['sum'] == -$v2['sum']) {
                    $paymentsAndChargebacks[$v['bill_no']] = 1;
                }
            }
        }

        foreach ($billPayments as $v) {

            if (isset($paymentsAndChargebacks[$v['bill_no']])) {
                continue;
            }

            foreach ($paymentsById as $v2) {
                if ($v['bill_no'] == $v2['bill_no'] && $v['sum'] < 0 && $v2['sum'] < 0) {
                    $v['sum'] -= $v2['sum'];
                }
            }

            if ($v['sum'] < 0) {
                $pay = [
                    'id' => $v['bill_no'],
                    'client_id' => $v['client_id'],
                    'payment_date' => $v['bill_date'],
                    'payment_id' => $v['bill_no'],
                    'currency' => $v['currency'],
                    'sum' => -$v['sum'],
                    'bill_no' => '',
                    'bill_vis_no' => '',
                    'is_billpay' => 1
                ];
                $paymentsById[$v['bill_no']] = $pay;
            }
        }
        return $paymentsById;
    }

    /**
     * @param float $pay
     * @param float $bill
     * @param float $diff
     * @return bool
     */
    private function _sumMore($pay, $bill, $diff = 0.01)
    {
        return ($pay - $bill > -$diff);
    }

    private function _getInvoices($clientAccountId)
    {
        // tested on d138400
        $invoices = Invoice::find()
            ->alias('i')
            ->joinWith('bill b', true, 'INNER JOIN')
            ->where(['not', ['i.number' => null]])
            ->andWhere(['b.client_id' => $clientAccountId])
            ->orderBy(['i.date' => SORT_ASC, 'i.number' => SORT_ASC, 'i.add_date' => SORT_ASC, 'i.id' => SORT_ASC])
            ->asArray()
            ->indexBy('id')
            ->all();

        $c = [];

        foreach ($invoices as $id => $invoice) {

            if (!$invoice['is_reversal']) {
                if ($invoice['sum'] == 0) {
                    $invoices[$id]['_is_reversed'] = -1;
                    continue;
                }
                $c[$invoice['number']] = $id;
                continue;
            }

            // && $invoice['is_reversal']
            if (isset($c[$invoice['number']])) {
                $invoices[$c[$invoice['number']]]['_is_reversed'] = 1;
                $invoices[$id]['_is_reversed'] = 0;
                unset($c[$invoice['number']]);
            }
        }

        foreach ($invoices as $id => $invoice) {
            unset ($invoice['bill']);
        }

        return $invoices;
    }

    /**
     * Делает ЛС активным при наличии включенных услуг
     *
     * @param ClientAccount|integer $clientAccount
     * @throws ModelValidationException
     */
    public function updateIsActive($clientAccount)
    {
        if (!($clientAccount instanceof ClientAccount)) {
            $clientAccount = ClientAccount::findOne(['id' => (int)$clientAccount]);
            Assert::isObject($clientAccount);
        }

        $now = new \DateTime();

        if ($clientAccount->account_version == ClientAccount::VERSION_BILLER_USAGE) {
            $hasUsage = Yii::$app->db->createCommand(
                '
            SELECT EXISTS (
            select id
            from usage_extra u
            where u.client = :client and u.actual_to >= :date

            union all

            select id
            from usage_welltime u
            where u.client = :client and u.actual_to >= :date

            union all

            select id
            from usage_ip_ports u
            where u.client = :client and u.actual_to >= :date

            union all

            select id
            from usage_sms u
            where u.client = :client and u.actual_to >= :date

            union all

            select id
            from usage_virtpbx u
            where u.client = :client and u.actual_to >= :date

            union all

            select id
            from usage_voip u
            where u.client = :client and u.actual_to >= :date

            union all

            select id
            from usage_trunk u
            where u.client_account_id = :client_account_id and u.actual_to >= :date
            ) isex

            ', [
                ':client' => $clientAccount->client,
                ':client_account_id' => $clientAccount->id,
                ':date' => $now->format(DateTimeZoneHelper::DATE_FORMAT),
            ])->queryScalar();
        } else {
            $hasUsage = Yii::$app->db->createCommand('
            SELECT EXISTS (
            select id
            from uu_account_tariff u
            where u.client_account_id = :client_account_id and tariff_period_id is not null 
            and prev_account_tariff_id is null
            ) isex
        ', [
                ':client_account_id' => $clientAccount->id,
            ])->queryScalar();
        }

        $newIsActive = $hasUsage;
        if ($clientAccount->is_active != $newIsActive) {
            $clientAccount->is_active = $newIsActive;
            if (!$clientAccount->save()) {
                throw new ModelValidationException($clientAccount);
            }
        }

        return (bool)$newIsActive;
    }

    /**
     * @param ClientAccount $clientAccount
     * @return array
     */
    public function getClientVoipNumbers(ClientAccount $clientAccount)
    {
        if (is_null($this->_voipNumbers)) {
            $this->_voipNumbers = ApiPhone::me()->getNumbersInfo($clientAccount);
        }

        return $this->_voipNumbers;
    }

    /**
     * Перепривязать все платежи к счетам. Для всех аккантов
     *
     * @throws ModelValidationException
     */
    public function relinkPaymentToBill()
    {
        $clientAccountQuery = ClientAccount::find()
            ->where(['account_version' => ClientAccount::VERSION_BILLER_UNIVERSAL]);

        /** @var ClientAccount $clientAccount */
        foreach ($clientAccountQuery->each() as $clientAccount) {
            $this->relinkPaymentToBillByAccount($clientAccount);
        }
    }

    /**
     * Перепривязать все платежи к счетам. Для указанного аккаунта
     *
     * @param ClientAccount $clientAccount
     * @throws ModelValidationException
     */
    public function relinkPaymentToBillByAccount(ClientAccount $clientAccount)
    {
        // "покраснить" счета
        Bill::updateAll(
            ['is_payed' => Bill::PAY_NOT_PAYED, 'is_pay_overdue' => Bill::PAY_NOT_PAYED],
            ['client_id' => $clientAccount->id]
        );

        /** @var Bill[] $bills */
        $bills = Bill::find()
            ->where(['client_id' => $clientAccount->id])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        /** @var Payment[] $payments */
        $payments = Payment::find()
            ->where(['client_id' => $clientAccount->id])
            ->orderBy(['id' => SORT_ASC])
            ->indexBy('id')
            ->all();

        $balance = 0;
        foreach ($bills as $bill) {

            if ($bill->sum <= 0) {
                continue;
            }

            $balance -= $bill->sum;

            // найти платеж на ту же сумму (в день первого платежа)
            $foundPayments = [];
            $paymentDate = null;
            foreach ($payments as $payment) {

                if (!$paymentDate) {
                    // дата первого необработанного платежа
                    $paymentDate = $payment->payment_date;
                }

                if ($paymentDate != $payment->payment_date) {
                    // уже другая дата - дальше не ищем
                    break;
                }

                if ($payment->sum == -$balance) {
                    // найден платеж на нужную сумму
                    $foundPayments[] = $payment;
                    $balance += $payment->sum;
                    break;
                }
            }

            unset($paymentDate);

            if (!$foundPayments) {
                // платеж на нужную сумму не найден
                // привязывать все платежи подряд, пока их сумма не покроет сумму счета
                foreach ($payments as $payment) {
                    $foundPayments[] = $payment;
                    $balance += $payment->sum;
                    if ($balance >= 0) {
                        // "горшочек, не вари"
                        break;
                    }
                }
            }


            foreach ($foundPayments as $foundPayment) {
                // привязать платеж к счету
                unset($payments[$foundPayment->id]); // исключить платеж из дальнейшего поиска
                $foundPayment->bill_no
                    = $foundPayment->bill_vis_no
                    = $bill ? $bill->bill_no : null;
                if (!$foundPayment->save()) {
                    throw new ModelValidationException($foundPayment);
                }

                // @todo тут могло бы "позеленение" счетов по-новому, но его надо "сертифицировать". Поэтому пока используем по-старому
            }
        }

        // "позеленить" счета по-старому
        ClientAccount::dao()->updateBalance($clientAccount->id);
    }

    /**
     * Есть ли мультитранк на ЛС
     *
     * @param integer $accountId
     * @return bool
     */
    public function isMultitrunkAccount($accountId)
    {
        if ($accountId instanceof ClientAccount) {
            $accountId = $accountId->id;
        }

        return AccountTariff::isServiceExists($accountId, ServiceType::ID_VOIP)
            && AccountTariff::isServiceExists($accountId, ServiceType::ID_TRUNK);

    }

    /**
     * Получаем дату установки последней финансовой блокировки
     *
     * @param integer $accountId
     * @return string
     */
    public function getDateLastFinanceLock($accountId)
    {
        return ImportantEvents::find()
            ->select('date')
            ->where([
                'client_id' => $accountId,
                'event' => ImportantEventsNames::ZERO_BALANCE
            ])
            ->orderBy(['id' => SORT_DESC])
            ->limit(1)
            ->scalar();
    }

    public function billBalanceMass()
    {
        $dateStr = (new \DateTimeImmutable())->modify('-2 month')->format(DateTimeZoneHelper::DATETIME_FORMAT);

        $queryPayment = Payment::find()->where(['>=', 'oper_date', $dateStr])->select('client_id')->distinct();
        $queryBill = Bill::find()->where(['>=', 'bill_date', $dateStr])->select('client_id')->distinct();
        $queryAccount = ClientAccount::find()->where(['is_active' => 1])->select(['client_id' => 'id'])->distinct();

        $query = (new Query())
            ->from(['c' => $queryAccount->union($queryBill)->union($queryPayment)])
            ->orderBy(['client_id' => SORT_ASC])
            ->select('client_id')
            ->distinct();

        $clientAccountQuery = ClientAccount::find()->where([ClientAccount::tableName() . '.id' => $query])->orderBy(['id' => SORT_ASC]);

        if (($organizationId = get_param_integer('organizationId'))) {
            $clientAccountQuery->leftJoin(['cc' => ClientContract::tableName()], 'cc.id = ' . ClientAccount::tableName() . '.contract_id');
            $clientAccountQuery->andWhere(['cc.organization_id' => $organizationId]);
        }

        set_time_limit(0);
//        session_write_close();
//
//        while (ob_get_level() > 0) {
//            ob_end_clean();
//        }

        /** @var ClientAccount $clientAccount */
        foreach ($clientAccountQuery->each() as $clientAccount) {
            try {
                ClientAccount::dao()->updateBalance($clientAccount);
            } catch (Exception $e) {
                HandlerLogger::me()->add("!!! ERROR !!!" . $clientAccount->id . ': ' . $e->getMessage());
            }
            flush();
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_DEFAULT)));
        Param::setParam(
            Param::NOTIFICATIONS_SWITCH_ON_DATE,
            $now->modify("+2 minutes")
                ->format(DateTimeZoneHelper::DATETIME_FORMAT),
            $isRawValue = true
        );
    }
}
