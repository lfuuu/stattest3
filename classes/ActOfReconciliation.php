<?php

namespace app\classes;

use app\classes\documents\DocumentReport;
use app\dao\BillDocumentDao;
use app\dao\TroubleDao;
use app\helpers\DateTimeZoneHelper;
use app\models\BalanceByMonth;
use app\models\Bill;
use app\models\BillLine;
use app\models\ClientAccount;
use app\models\Country;
use app\models\document\PaymentTemplate;
use app\models\Invoice;
use app\models\Language;
use app\models\OperationType;
use app\models\Payment;
use app\models\Saldo;
use app\modules\uu\models\Bill as uuBill;
use app\modules\uu\models_light\InvoiceLight;
use Exception;
use yii\db\Expression;
use yii\db\Query;

class ActOfReconciliation extends Singleton
{
    /**
     * @param ClientAccount $account
     * @param string $dateFrom
     * @param string $dateTo
     * @param int $startSaldo
     * @param bool $sortByBillDate
     * @param bool $isWithBills
     * @param bool $isWithPrepayedBills
     * @return array
     * @throws Exception
     */
    public function getRevise(ClientAccount $account, $dateFrom, $dateTo, $startSaldo = 0, $sortByBillDate = false, $isWithBills = false, $isWithPrepayedBills = false)
    {
        $dateFrom = DateTimeZoneHelper::getDateTime($dateFrom, DateTimeZoneHelper::DATE_FORMAT, false);
        $dateTo = DateTimeZoneHelper::getDateTime($dateTo, DateTimeZoneHelper::DATE_FORMAT, false);
        if (!$dateFrom || !$dateTo) {
            throw new Exception('Заполните дату');
        }

        $result = [];
        $period = [
            'income_sum' => 0,
            'outcome_sum' => 0
        ];

        $dateFromFormated = (new \DateTimeImmutable($dateFrom))->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED);
        $dateToFormated = (new \DateTimeImmutable($dateTo))->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED);
        $lang = $account->contragent->lang_code;

        $result[] = [
            'type' => 'saldo',
            'date' => $dateFrom,
            'description' => $lang == Language::LANGUAGE_RUSSIAN ? 'Сальдо на ' . $dateFromFormated : 'Balance as of ' . $dateFromFormated,
            'income_sum' => -$startSaldo > 0 ? -$startSaldo : 0,
            'outcome_sum' => $startSaldo > 0 ? $startSaldo : 0
        ];

        $paymentsQuery = Payment::find()
            ->alias('p')
            ->select([
                'p.id',
                'sum',
                'type' => new Expression('"payment"'),
                'payment_type' => 'type',
                'date' => 'payment_date',
                'number' => 'payment_no',
                'correction_idx' => new Expression('""'),
                'bill_date' => 'payment_date',
                'add_datetime' => 'add_date',
                'is_payed' => new Expression('null'),
            ])
            ->where([
                'client_id' => $account->id,
                'currency' => $account->currency
            ])
            ->andWhere(['between', 'payment_date', $dateFrom, $dateTo]);

        $billQuery = Bill::find()
            ->alias('b')
            ->select([
                'b.id',
                'sum',
                'type' => new Expression('"bill"'),
                'payment_type' => new Expression('""'),
                'date' => 'bill_date',
                'number' => 'bill_no',
                'correction_idx' => new Expression('""'),
                'bill_date' => 'bill_date',
                'add_datetime' => 'bill_date',
                'b.is_payed',
            ])
            ->where([
                'client_id' => $account->id,
                'currency' => $account->currency,
                'operation_type_id' => OperationType::ID_PRICE,
                'is_show_in_lk' => 1,
            ])
            ->andWhere([($isWithPrepayedBills ? '>=' : '>'), 'sum', 0])
            ->andWhere(['between', 'bill_date', $dateFrom, $dateTo]);


        $query = Invoice::find()
            ->alias('i')
            ->select([
                'i.id',
                'i.sum',
                'type' => new Expression('"invoice"'),
                'payment_type' => 'type_id',
                'i.date',
                'number' => new Expression('if (i.type_id = 3, i.bill_no, i.number)'),
                'correction_idx',
                'bill_date',
                'add_datetime' => 'add_date',
                'i.is_payed',
            ])
            ->joinWith('bill b')
            ->where([
                'client_id' => $account->id,
                'currency' => $account->currency
            ])
            ->andWhere(['!=', 'i.sum', 0])
            ->andWhere(['NOT', ['i.number' => null]])
            ->andWhere(['NOT', ['b.state_1c' => TroubleDao::me()->getRejectStatusesName()]])
            ->andWhere(['NOT', ['i.type_id' => Invoice::TYPE_PREPAID]])
            ->andWhere(['between', 'date', $dateFrom, $dateTo])
            ->union('SELECT
  b.id,
  -if(ext_vat != 0,
        ext_vat + ext_sum_without_vat,
        ext_sum_without_vat      
  )                                          AS sum,   
  \'invoice\'                                 AS type,
  \'\'                                        AS payment_type,
  STR_TO_DATE(ext_invoice_date, \'%d-%m-%Y\') AS date,
  ex.ext_invoice_no                         AS number,
  \'\'                                        AS correction_idx,
  b.bill_date                               AS bill_date,
  b.bill_date                               AS add_datetime,
  null                                      AS is_payed
FROM newbills b
  JOIN `newbills_external` ex USING (bill_no)
WHERE b.client_id = ' . $account->id . '
      AND b.currency = \'' . $account->currency . '\'
      AND length(trim(coalesce(ext_invoice_date, \'\'))) > 0
      AND trim(coalesce(ext_invoice_no, \'\')) != \'\'
      AND STR_TO_DATE(ext_invoice_date, \'%d-%m-%Y\') BETWEEN \'' . $dateFrom . '\' AND \'' . $dateTo . '\'', true);

        $query->union($paymentsQuery, true);
        $isWithBills && $query->union($billQuery, true);


        // сортировка работает отдельно от union
        $arr = (new Query())
            ->from(['a' => $query])
            ->orderBy([($sortByBillDate ? 'bill_date' : 'date') => SORT_ASC, 'a.id' => SORT_ASC])
            ->all();

        foreach ($arr as $item) {
            $isInvoice = $item['type'] == 'invoice';
            $isBill = $item['type'] == 'bill';
            $date = (new \DateTimeImmutable($item['date']))->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED);
            $sum = $isInvoice ? $item['sum'] : -$item['sum'];

            if ($lang == Language::LANGUAGE_RUSSIAN) {

                $descrType = ($item['payment_type'] == Invoice::TYPE_GOOD ? 'Накладная' : 'Акт');
                if ($isInvoice && $item['date'] >= '2026-01-01') {
                    $descrType = 'УПД';
                }

                $description = $isInvoice ? $descrType . ' (' . $date . ', №' . $item['number'] . ')'
                    : (
                    ($item['payment_type'] == 'creditnote')
                        ? 'Кредит-нота от ' . $date
                        : ($item['type'] == 'bill' ? 'Счет' : 'Оплата') . ' (' . $date . ', №' . $item['number'] . ')'
                    );
            } else {
                $description = $isInvoice
                    ? ($item['payment_type'] == Invoice::TYPE_GOOD ? 'Waybill' : 'Invoice') . ' (' . $date . ', №' . $item['number'] . ')'
                    : (
                    ($item['payment_type'] == 'creditnote')
                        ? 'Credit note from ' . $date
                        : 'Payment' . ' (' . $date . ', №' . $item['number'] . ')'
                    );
            }

            if ($isBill) {
                // select count(*) from newbill_lines where bill_no = '202012-018854' and id_service is not null
                $isServiceBill = BillLine::find()->where(['bill_no' => $item['number']])->andWhere(['NOT', ['type' => BillLine::LINE_TYPE_ZADATOK]])->exists();
                $isInvoiceCreated = Invoice::find()->where(['bill_no' => $item['number']])->exists();

                if (!$isServiceBill) {
                    $sum = BillLine::find()->where(['bill_no' => $item['number']])->sum('sum');

                    if ($sum) {
                        $sum = -$sum;
                    }
                }
            }

            $result[] = [
                    'id' => $item['id'],
                    'type' => $item['payment_type'] == 'creditnote' ? 'creditnote' : $item['type'],
                    'date' => $item['date'],
                    'bill_date' => $item['bill_date'],
                    'number' => $item['number'],
                    'description' => $description,
                    'income_sum' => $sum > 0 ? $sum : '',
                    'outcome_sum' => $sum < 0 ? -$sum : '',
                ] + ($isInvoice ? ['correction_idx' => $item['correction_idx']] : ['add_datetime' => $item['add_datetime']])
                + ($item['type'] == 'bill' ? ['is_invoice_created' => $isServiceBill && $isInvoiceCreated] : [])
                + (in_array($item['type'], ['bill', 'invoice', 'act']) ? ['payment_status' => (Payment::$paymentStatusPaid[$item['is_payed']] ?? Payment::$paymentStatusPaid[Payment::PAYMENT_STATUS_REJECTED])] : []);

            if ($item['type'] != 'bill') {
                $period[$isInvoice ? 'income_sum' : 'outcome_sum'] += $item['sum'];
            }
        }

        $result[] = ['type' => 'period', 'description' => $lang == Language::LANGUAGE_RUSSIAN ? 'Обороты за период' : 'Period transactions'] + $period;
        $ressaldo = $period['income_sum'] - $period['outcome_sum'] - $startSaldo;
        $result[] = [
            'type' => 'saldo',
            'date' => $dateTo,
            'description' => $lang == Language::LANGUAGE_RUSSIAN ? 'Сальдо на ' . $dateToFormated : 'Balance as of ' . $dateToFormated,
            'income_sum' => $ressaldo > 0 ? $ressaldo : 0,
            'outcome_sum' => -$ressaldo > 0 ? -$ressaldo : 0
        ];

        $deposits = \Yii::$app->db->createCommand("
        SELECT n.client_id,concat(n.bill_no,'-3') AS inv_no,n.bill_date,nb.bill_no,nb.item,nb.sum FROM newbills n
        JOIN newbill_lines nb ON n.bill_no = nb.bill_no
        WHERE client_id = $account->id AND nb.type = 'zalog'")->queryAll();

        $depositSum = 0;
        foreach ($deposits as $item) {
            $depositSum += $item['sum'];
        }

        $depositBalance = $depositSum + $ressaldo;

        return [
            'data' => $result,
            'deposit' => $deposits,
            'deposit_balance' => $depositBalance
        ];
    }

    public function getData(ClientAccount $account, $dateFrom, $dateTo, $isWithCorrection = true, $isWithPrepaymentBills = false, $countryCodeParam = null)
    {
        $countryCode = $countryCodeParam ?? $account->getUuCountryId();

        $isNotRussia = $countryCode != Country::RUSSIA;
        if (!$dateFrom) {
            $dateFrom = $isNotRussia ? '2019-07-31' : date('Y-01-01', strtotime('-3 year'));
        }

        $dirtyData = $this->getRevise($account, $dateFrom, $dateTo, 0, $isNotRussia, true /*!$isNotRussia */, $isWithPrepaymentBills);

        $data = array_reverse(
            array_filter($dirtyData['data'], function ($a) {
                return $a['type'] != 'saldo' && $a['type'] != 'period';
            })
        );

        // У клиентов вне России стоит неправильная дата. Её надо брать из счета.
        if ($isNotRussia) {
            $data =
                array_filter(
                    array_map(
                        function ($value) use ($account) {
                            if ($value['type'] == 'invoice') {
                                $value['date'] = $value['bill_date'];
                            }
                            return $value;
                        }, $data
                    ),
                    function ($value) use ($dateFrom) {
                        return $value['bill_date'] >= $dateFrom;
                    }
                );
        }

        $result = [];
        $balance = $account->billingCounters->realtimeBalance;
        $accountingBalance = $account->balance;

        $billBalanceDiff = $this->getBillBalanceDiff($account->id);

        if (abs($billBalanceDiff) < 0.05) {
            $accountingBalance -= $billBalanceDiff;
        }

        $diffBalance = $accountingBalance - $balance;

        $clientTimeZone = new \DateTimeZone($account->timezone_name);
        $utcTimeZone = new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC);

        $setDateTime = function ($dateTime, $isInTimeZoneClient = false) use ($clientTimeZone, $utcTimeZone) {
            return (new \DateTimeImmutable($dateTime, $isInTimeZoneClient ? $clientTimeZone : $utcTimeZone))
                ->setTimezone($clientTimeZone)
                ->format(DateTimeZoneHelper::DATETIME_FORMAT);
        };

        $d = [];
//        array_filter($data, function ($d) {
//            return $d['type'] == 'bill' && !$d['is_invoice_created'];
//        });

        $sumNotInInvoice = array_reduce($d, function ($sumNotInInvoice, $v) {
            return $sumNotInInvoice + ((float)$v['outcome_sum'] - (float)$v['income_sum']);
        }, 0);

        $result[] = [
            'type' => 'current_balance',
            'date' => date(DateTimeZoneHelper::DATE_FORMAT),
            'add_datetime' => $setDateTime('now'),
            'balance' => $balance,
            'description' => 'current_balance',
        ];

        $currentStatementSum = 0;
        if ($account->account_version == ClientAccount::VERSION_BILLER_UNIVERSAL) {
            $currentStatementSum = uuBill::getUnconvertedAccountEntries($account->id)->sum('price_with_vat') ?: 0;
        }

        $currentStatementSum += $diffBalance + $sumNotInInvoice;

        $result[] = [
            'type' => 'current_statement',
            'date' => date(DateTimeZoneHelper::DATE_FORMAT),
            'add_datetime' => $setDateTime('now'),
            'income_sum' => (float)$currentStatementSum,
            'description' => 'current_statement',
        ];
        $balance += $currentStatementSum;

        $firstMonthDate = date(DateTimeZoneHelper::DATE_FORMAT, strtotime('first day of this month'));
        $firstDataDate = $data ? reset($data)['date'] : false;

        if ($firstDataDate && $firstDataDate < $firstMonthDate) {
            $result[] = [
                'type' => 'month',
                'date' => $firstMonthDate,
                'add_datetime' => $setDateTime($firstMonthDate, true),
                'balance' => $balance,
                'description' => 'month_balance',
            ];
        }

        $findDate = null;


        if (!$isNotRussia) {
            foreach ($data as $idx => &$row) {
                if ($row['type'] == 'act' || $row['type'] == 'invoice') {
                    if ($row['bill_date'] >= '2026-01-01') {
                        $row['type'] = 'upd';
                    }
                }
            }
        }

        $this->addingLinks($account, $data, !$isNotRussia, $countryCode);
        $this->addingLinks($account, $result, !$isNotRussia, $countryCode);

        foreach ($data as $idx => &$row) {
            if ($row['type'] == 'invoice') {
                $row['type'] = 'act';
            }

            unset($row['id']);

            $row['description'] = $row['type'];

            if (!$findDate) {
                $findDate = date('Y-m-d', strtotime('first day of this month', strtotime($row['date'])));
                $result[] = $row;

                if (!(isset($row['is_invoice_created']) && !$row['is_invoice_created'])) { // в балансе не участвуют счета без с/ф
                    $balance += (float)$row['income_sum'] - (float)$row['outcome_sum'];
                }
                continue;
            }

            $date = $row['date'];
            // месячная линия,  должна быть после всех документов в месяце
            if (
                $date < $findDate && (
                    !isset($data[$idx + 1])
                    || (
                        isset($data[$idx + 1])
                        && $data[$idx + 1]['date'] < $findDate
                    )
                )
            ) {
                while ($date < $findDate) {
                    $result[] = [
                        'type' => 'month',
                        'date' => $findDate,
                        'add_datetime' => $setDateTime($findDate, true),
                        'balance' => $balance,
                        'description' => 'month_balance',
                    ];

                    $findDate = date('Y-m-d', strtotime('first day of previous month', strtotime($findDate)));
                }
            }
            $result[] = $row;
            if ($row['type'] != 'bill') {
                $balance += (float)$row['income_sum'] - (float)$row['outcome_sum'];
            }

        }
        unset($row);

        if ($findDate) {
            $result[] = [
                'type' => 'month',
                'date' => $findDate,
                'add_datetime' => $setDateTime($findDate, true),
                'balance' => round($balance, 2),
                'description' => 'month_balance',
            ];
        }

        if (!$isWithCorrection) {
            $this->_typeCast($result);
            return $result;
        }

        $result = $this->makingAdjustments($account, $result);
        $this->_typeCast($result);

        /** @var Saldo $saldo */
        $saldo = Saldo::getLastSaldo($account->id);

        if ($saldo) {
            $result = array_filter($result, fn($l) => $l['date'] >= $saldo->ts);
        }

        return $result;
    }

    public function addingLinks(ClientAccount $account, &$data, $isRussia, $countryCodeParam = null)
    {
        $clientTimeZone = new \DateTimeZone($account->timezone_name);
        $utcTimeZone = new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC);

        $setDateTime = function ($dateTime, $isInTimeZoneClient = false) use ($clientTimeZone, $utcTimeZone) {
            return (new \DateTimeImmutable($dateTime, $isInTimeZoneClient ? $clientTimeZone : $utcTimeZone))
                ->setTimezone($clientTimeZone)
                ->format(DateTimeZoneHelper::DATETIME_FORMAT);
        };

//        $countryCodeAddLink = $countryCodeParam ? ['co' => $countryCodeParam] : [];
        $countryCodeAddLink = ['co' => $countryCodeParam];

        foreach ($data as $idx => &$row) {
            $row['add_datetime'] = isset($row['add_datetime'])
                ? $setDateTime($row['add_datetime'])
                : $setDateTime($row['date'] . ' 00:00:00', true);

            if ($row['type'] == 'invoice') {
                $row['type'] = 'act';
            }

            if ($row['type'] == 'act') {
                $row['link'] = Encrypt::encodeArray([
                    'is_pdf' => 1,
                    'tpl1' => 1,
                    'client' => $account->id,
                    'invoice_id' => $row['id'],
                    'is_act' => 1,
                ]);

                $docKey = !$isRussia ? ($row['outcome_sum'] > 0 ? 'storno' : 'invoice') : 'act';

                $this->_mkLink($row, $docKey, [
                        'tpl' => 'b',
                        'a' => $account->id,
                        ($docKey == 'invoice' ? 'i' : $docKey) => $row['id'],
                        'is_pdf' => 1,
                    ] + $countryCodeAddLink);

                if ($account->getTaxRateOnDate($row['bill_date']) > 0) {
                    $row['link_invoice'] = Encrypt::encodeArray([
                        'is_pdf' => 1,
                        'tpl1' => 1,
                        'client' => $account->id,
                        'invoice_id' => $row['id'],
                    ]);

                    if ($docKey != 'storno' && $docKey != 'invoice') {
                        $this->_mkLink($row, 'invoice', [
                                'tpl' => 'b',
                                'a' => $account->id,
                                'i' => $row['id'],
                                'is_pdf' => 1,
                            ] + $countryCodeAddLink);
                    }
                }
            } elseif ($row['type'] == 'current_statement') {
                $row['link'] = Encrypt::encodeArray([
                    'client' => $account->id,
                    'doc_type' => DocumentReport::DOC_TYPE_CURRENT_STATEMENT,
                    'tpl1' => 2,
                    'is_pdf' => 1,
                ]);

                $this->_mkLink($row, 'statement', [
                        'tpl' => 'b',
                        'a' => $account->id,
                        'cur_st' => 1,
                        'is_pdf' => 1,
                    ] + $countryCodeAddLink);


            } elseif ($row['type'] == 'bill') {
//                if (!$row['is_invoice_created']) {
//                    $row['outcome_sum'] = 0;
//                    $row['income_sum'] = $row['outcome_sum'];
//                    $row['outcome_sum'] = 0;
//                }

                if ($isRussia) { // Russia
                    $row['link'] = Encrypt::encodeArray([
                        'bill' => $row['number'],
                        'object' => 'bill-2-RUB',
                        'client' => $account->id,
                        'is_pdf' => 1,
                    ]);
                } else {
                    $row['link'] = Encrypt::encodeArray([
                        'doc_type' => 'proforma',
                        'bill' => $row['number'],
                        'object' => 'bill-2-RUB',
                        'client' => $account->id,
                        'is_pdf' => 1,
                    ]);
                }
                $this->_mkLink($row, 'bill', [
                        'tpl' => 'b',
                        'b' => $row['number'],
                        'a' => $account->id,
                        'is_pdf' => 1,
                    ] + $countryCodeAddLink);
            } elseif($row['type'] == 'upd') {
                $invoice = Invoice::findOne(['number' => $row['number']]);
                if (!$invoice) {
                    continue;
                }
                $row['link'] = Encrypt::encodeArray($invoice->getDocumentLinkData());
            }
        }
    }

    private function _typeCast(&$result)
    {
        array_walk($result, function (&$v) {
            foreach (['income_sum', 'outcome_sum', 'balance'] as $field) {
                if (isset($v[$field]) && $v[$field] !== '') {
                    $v[$field] = round($v[$field], 2);
                }
            }
        });
    }

    private function _mkLink(&$row, $section, $array)
    {
        static $country = null;

        if ($country === null) {
            $clientAccount = ClientAccount::findOne(['id' => $array['a']]);
            $country = $array['co'] ?? Country::findOne(['code' => $clientAccount->getUuCountryId() ?: Country::RUSSIA])->code;
        }

        static $cache = [];

        $docTypeId = BillDocumentDao::me()->getTpl4DocTypeByR($array);

        if (!isset($cache[$docTypeId ?? 'null'][$country ?? 'null'])) {
            $cache[$docTypeId ?? 'null'][$country ?? 'null'] = PaymentTemplate::getDefaultByTypeIdAndCountryCodeViaShortName($docTypeId, $country);
        }

        $tpl = $cache[$docTypeId ?? 'null'][$country ?? 'null'];


        $row['links'][$section] = [
            'template' => [
                'engine_version' => 4,
                'country' => $country,
                'doc_type' => $docTypeId ? [
                    'id' => $docTypeId,
                    'code' => InvoiceLight::$typeName[$docTypeId] ?? null,
                ] : null,
                'tpl' => $tpl ? $tpl->getAttributes(['id', 'version']) : null,
            ],
            'link' => Encrypt::encodeArray($array),
        ];
    }

    protected function makingAdjustments(ClientAccount $account, $result)
    {
        /** @var BalanceByMonth $mBalance */
        $mBalance = BalanceByMonth::find()
            ->where(['account_id' => $account->id])
            ->orderBy(['year' => SORT_DESC, 'month' => SORT_DESC])
            ->one();

        if (!$mBalance) {
            return $result;
        }

        $date = (new \DateTimeImmutable())
            ->setTime(0, 0, 0)
            ->setDate($mBalance->year, $mBalance->month, 1)
//            ->modify('+1 month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);

        $result = array_reverse($result);

        $diffBalance = 0;

        $isStart = false;
        $r = [];
        $submitted = 0;
        $currentBalance = null;
        foreach ($result as $value) {
            if ($value['type'] == 'month' && $value['date'] == $date) {
                $diffBalance = round($value['balance'] - $mBalance->balance, 2);
                $isStart = true;
                continue;
//                break;
            }

            if ($isStart && in_array($value['type'], ['act', 'payment'])) {
                if (isset($value['outcome_sum']) && abs($value['outcome_sum']) > 0) {
                    $submitted += $value['outcome_sum'];
                }
                if (isset($value['income_sum']) && abs($value['income_sum']) > 0) {
                    $submitted -= $value['income_sum'];
                }

                $r[] = $value;
            }

            if ($value['type'] == 'current_balance') {
                $currentBalance = $value['balance'];
            }
        }

        if (abs($diffBalance) < 0.01) {
            return array_reverse($result);
        }

        foreach ($result as &$value) {
            if ($value['type'] == 'month') {
                $value['balance'] -= $diffBalance;
            } elseif ($value['type'] == 'current_statement') {
//                $value['income_sum'] -= $diffBalance + $d;
                unset($value['income_sum'], $value['outcome_sum']);

                $v = $mBalance->balance - $currentBalance + $submitted;

                if ($v >= 0) {
                    $value['income_sum'] = $v;
                } else {
                    $value['outcome_sum'] = $v;
                }
            }
        }

        return array_reverse($result);
    }

    /**
     * Сохраняем балансы в ЛС по месяцам
     */
    public function saveBalances($accountId = 0)
    {
        $clientQuery = ClientAccount::find()->where(['is_active' => 1]);

        if ($accountId) {
            $clientQuery->andWhere(['id' => $accountId]);
        }

        foreach ($clientQuery->each() as $account) {
            $data = $this->getData($account, null, (date('Y') + 1) . '-01-01', false);

            $data = array_filter($data, function ($row) {
                return $row['type'] == 'month';
            });

            $data = array_map(function ($value) use ($account) {
                $dateAr = explode('-', $value['date']);
                return [$account->id, $dateAr[0], $dateAr[1], $value['balance']];
            }, $data);

            BalanceByMonth::getDb()->transaction(function ($db) use ($account, $data) {
                /** @var Connection $db */
                $db->createCommand()->delete(BalanceByMonth::tableName(), ['account_id' => $account->id])->execute();
                $db->createCommand()->batchInsert(BalanceByMonth::tableName(), ['account_id', 'year', 'month', 'balance'], $data)->execute();
            });
        }
    }

    public function getBillBalanceDiff($accountId)
    {
        $billSum = round(Bill::find()->where(['client_id' => $accountId])->sum('sum'), 2);
        $uBillSum = round(\app\modules\uu\models\Bill::find()->where(['client_account_id' => $accountId])->sum('price'), 2);

        return $billSum - $uBillSum;

    }
}
