<?php

use app\classes\grid\GridView;
use app\classes\Html;
use app\models\BillExternal;
use app\models\ClientAccount;
use app\models\ClientContract;
use app\models\Invoice;
use app\models\OperationType;
use app\modules\uu\models\AccountEntryCorrection;
use yii\data\ArrayDataProvider;
use yii\db\Expression;
use yii\helpers\Url;
use yii\widgets\Breadcrumbs;

/** @var ClientAccount $account */

?>

<?= app\classes\Html::formLabel($this->title = 'Счета 2.0') ?>
<?= Breadcrumbs::widget([
    'links' => [
        ['label' => 'Бухгалтерия'],
        ['label' => $this->title, 'url' => '/accounting/'],
        ['label' => $account->getAccountTypeAndId(), 'url' => '/accounting/?account_id=' . $account->id],
        ['label' => 'Тип договора: ' . ClientContract::$financialTypes[$account->clientContractModel->financial_type], 'url' => '/accounting/?account_id=' . $account->id],
        ['label' => 'Обновить баланс', 'url' => ['/', 'module' => 'newaccounts', 'action' => 'bill_balance', 'returning' => 'accounting'], 'class' => 'btn btn-success btn-xs'],
        ['label' => 'Обновить баланс (новая версия)', 'url' => ['/', 'module' => 'newaccounts', 'action' => 'bill_balance2', 'returning' => 'accounting'], 'class' => 'btn btn-info btn-xs'],
    ],
]) ?>
<style>


</style>

<div class="row">
    <div class="col-sm-8">
        <?php

        $finType = $account->clientContractModel->financial_type;

        if (!$finType || $finType == ClientContract::FINANCIAL_TYPE_PROFITABLE || $finType == ClientContract::FINANCIAL_TYPE_YIELD_CONSUMABLE) {
            echo Html::a("Создать доходный счёт", Url::to(['/', 'module' => 'newaccounts', 'action' => 'bill_create_income']), ['class' => 'btn btn-info btn-xs']) . ' ';
        }

        if ($finType == ClientContract::FINANCIAL_TYPE_CONSUMABLES || $finType == ClientContract::FINANCIAL_TYPE_YIELD_CONSUMABLE) {
            echo Html::a("Создать расходный счёт", Url::to(['/', 'module' => 'newaccounts', 'action' => 'bill_create_outcome']), ['class' => 'btn btn-primary btn-xs']);
        }
        ?>

    </div>
    <div class="col-sm-4 text-right">
        <div class="btn-group btn-group-sm">
            <?= Html::a('Доходный', Url::to(['/accounting/', 'set' => 'listFilter', 'is' => 'income']), ["class" => "btn btn-xs btn-" . ($listFilter == 'income' ? 'info' : 'default')]) ?>
            <?= Html::a('Полный', Url::to(['/accounting/', 'set' => 'listFilter', 'is' => 'full']), ["class" => "btn btn-xs btn-" . ($listFilter == 'full' ? 'info' : 'default')]) ?>
            <?= Html::a('Расходный', Url::to(['/accounting/', 'set' => 'listFilter', 'is' => 'outcome']), ["class" => "btn btn-xs btn-" . ($listFilter == 'outcome' ? 'info' : 'default')]) ?>
        </div>
        <div class=" form-check form-switch">
            <input id="docs_checkbox" class="form-check-input" type="checkbox"
                   id="flexSwitchCheckChecked"<?= $billOperations ? " checked" : "" ?>>
            <label class="form-check-label" for="flexSwitchCheckChecked">Отправка документов</label>
        </div>
    </div>
</div>
<script>
    $('#docs_checkbox').on('change', function (event) {
        location.href = '/accounting/?set=billOperations&is=' + ($(event.currentTarget).is(':checked') ? "1" : "0");
    });
</script>

<?php


$report = new \app\classes\accounting\AccountingTwoZero($account);

$t = $report->totals;

function nf($d)
{
    $v = number_format($d, 2, '.', ' ');
    return preg_replace('/(^0.00|\.?[0]+)$/', '<span style="color: lightgrey;">$1</span>', $v);
}

$currencyLabel = $account->currencyModel->symbol;

?>

<?php if ($saldo): ?>
<div class="row">
    <div class="col-sm-6">
        <div class="row">
        <div class="col-sm-3"><b>Сальдо</b></div>
        <div class="col-sm-3">Сумма: <?= nf($saldo->saldo) ?></div>
        <div class="col-sm-3">На дату: <?= $saldo->ts ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-sm-3">
        <div class="row text-center"><h2>Доходные (с/ф)</h2></div>
        <div class="row">
            <div class="col-sm-6">Сумма с/ф:</div>
            <div class="col-sm-6 text-right"><?= nf($t->invSum) ?></div>
        </div>
        <div class="row">
            <div class="col-sm-6">платежи "+":</div>
            <div class="col-sm-6 text-right"><?= nf($t->paysPlusSum) ?></div>
        </div>
        <div class="row">
            <div class="col-sm-6">Баланс:</div>
            <div class="col-sm-6 text-right"
                 style="color: <?= (abs($t->paysPlusSum - $t->invSum) < 0.01 ? 'black' : ($t->paysPlusSum - $t->invSum > 0 ? 'green' : 'red')) ?>"><?= nf($t->paysPlusSum - $t->invSum) ?></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="row text-center"><h2>Доходные (счета)</h2></div>
        <div class="row">
            <div class="col-sm-6">Сумма счетов:</div>
            <div class="col-sm-6 text-right"><?= nf($t->billSumPlus) ?></div>
        </div>
        <div class="row">
            <div class="col-sm-6">платежи "+":</div>
            <div class="col-sm-6 text-right"><?= nf($t->paysPlusSum) ?></div>
        </div>
        <div class="row">
            <div class="col-sm-6">Баланс:</div>
            <div class="col-sm-6 text-right"
                 style="color: <?= (abs($t->totalPlus) < 0.01 ? 'black' : ($t->totalPlus > 0 ? 'green' : 'red')) ?>"><?= nf($t->totalPlus) ?></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="row text-center"><h2>Расходные (счета)</h2></div>
        <div class="row">
            <div class="col-sm-6">Сумма счетов:</div>
            <div class="col-sm-6 text-right"><?= nf($t->billSumMinus) ?></div>
        </div>
        <div class="row">
            <div class="col-sm-6">платежи "-":</div>
            <div class="col-sm-6 text-right"><?= nf($t->paysMinusSum, 2, '.', ' ') ?></div>
        </div>
        <div class="row">
            <div class="col-sm-6">Баланс:</div>
            <div class="col-sm-6 text-right"
                 style="color: <?= (abs($t->totalMinus) < 0.01 ? 'black' : ($t->totalMinus > 0 ? 'green' : 'red')) ?>"><?= nf($t->totalMinus) ?></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="row text-center"><h2>Расходные (с/ф)</h2></div>
        <div class="row">
            <div class="col-sm-6">Сумма с/ф:</div>
            <div class="col-sm-6 text-right"><?= nf($t->invoiceExtSum) ?></div>
        </div>
        <div class="row">
            <div class="col-sm-6">платежи "-":</div>
            <div class="col-sm-6 text-right"><?= nf($t->invoiceExtPays) ?></div>
        </div>
        <div class="row">
            <div class="col-sm-6">Баланс:</div>
            <div class="col-sm-6 text-right"
                 style="color: <?= (abs($t->paysPlusSum - $t->invSum) < 0.01 ? 'black' : ($t->paysPlusSum - $invSum > 0 ? 'green' : 'red')) ?>"><?= nf($t->invoiceExtPays + $t->invoiceExtSum) ?></div>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-sm-3"></div>
    <div class="col-sm-6">
        <div class="text-center" style="border-top: 1px solid gray; padding: 5px;">Итого по счетам: <span
                    style="color: <?= (abs($t->totalBills) < 0.01 ? 'black' : ($t->totalBills > 0 ? 'green' : 'red')) ?>"><?= nf($t->totalBills) ?></span>
        </div>
    </div>
    <div class="col-sm-3"></div>
</div>
<?php

$d = [];

$dataInv = [];

$sumInvoice = [];

$paysPlus = $report->list->paysPlus;
$paysMinus = $report->list->paysMinus;
$invoices = $report->list->invoices;
$billsPlus = $report->list->billsPlus;
$billsMinus = $report->list->billsMinus;
$invoiceExt = $report->list->invoiceExt;
$billCorrections = $report->list->billCorrections;
$billInvoiceCorrections = $report->list->billInvoiceCorrections;


/** @var Invoice $invoice */
foreach ($invoices as $invoice) {

    $v = [
        'number' => $invoice->number,
        'link' => $invoice->link,
        'date' => $invoice->date,
        'sum' => round($invoice->sum, 2),
//        'is_paid' => $paysPlusInv > $invoice->sum ? 1 : ($paysPlusInv > 0 ? 2 : 0),
        'is_paid' => $invoice->is_payed,
        'type' => 'invoice',
    ];

    if (!isset($sumInvoice[$invoice->bill_no])) {
        $sumInvoice[$invoice->bill_no] = 0;
    }

    $sumInvoice[$invoice->bill_no] += $v['sum'];


    $dataInv[] = $v;
    $paysPlusInv -= $invoice->sum;

    $date = new DateTimeImmutable($invoice->date);
    addItem($d, $v, $date);

    if (isset($billInvoiceCorrections[$invoice->bill_no][$invoice->type_id])) {
        $billInvoiceCorrections[$invoice->bill_no][$invoice->type_id]['is_found'] = $invoice;
    }
}


$billInvoiceCorrectionIds = array_reduce($billInvoiceCorrections, function ($accum, $value) {
    array_map(function ($val) use (&$accum) {
        if ($val['is_found']) {
            $accum[$val['bill']->id] = $val['is_found']; // $val['is_found'] == invoice
        }
    }, $value);
    return $accum;
}, []);


$dataBillsPlus = [];
$vv = [];
$lastBillPayStatus = null;
/** @var \app\models\Bill $bill */
foreach ($billsPlus as $bill) {

    $v = [
        'id' => $bill->id,
        'is_correction' => $bill->operation_type_id == OperationType::ID_CORRECTION,
        'comment' => $bill->operation_type_id == OperationType::ID_CORRECTION ? '' : $bill->comment,
        'number' => $bill->bill_no,
        'link' => $bill->link,
        'date' => $bill->bill_date,
        'sum' => $bill->sum,
        'is_paid' => $bill->is_payed, //$paysPlusBills > $bill->sum ? 1 : ($paysPlusBills > 0 ? 2 : 0),
        'is_show_in_lk' => $bill->is_show_in_lk,
        'type' => 'bill',
    ];
    $lastBillPayStatus = $v['is_paid'];

    $vv[] = $v;

    $invoice = $billInvoiceCorrectionIds[$bill->id] ?? null;
    if ($invoice) {
        $v = [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'is_correction' => false,
//            'link' => $invoice->link,
            'date' => $bill->bill_date,
            'sum' => round($invoice->sum, 2),
            'is_paid' => null,
            'type' => 'invoice_correction',
//            'type' => 'invoice',
        ];

        $vv[] = $v;
    }
}

$currentStatement = [];
if ($account->account_version == ClientAccount::VERSION_BILLER_UNIVERSAL) {
    $statementSum = number_format(\app\modules\uu\models\Bill::getUnconvertedAccountEntries($account->id)->sum('price_with_vat'), 2, '.', '');

    $v = [
        'id' => PHP_INT_MAX,
        'is_correction' => false,
        'comment' => '',
        'number' => 'Текущая выписка',
        'link' => \app\models\Bill::makeLink('current_statement'),
        'date' => date('Y-m-d'),
        'sum' => $statementSum,
        'is_paid' => $lastBillPayStatus, //$paysPlusBills > $statementSum ? 1 : ($paysPlusBills > 0 ? 2 : 0),
        'type' => 'bill',
    ];
    $vv[] = $v;

    $currentStatement = [
        'sum' => $statementSum,
        'bill_date' => date('Y-m-d'),
        'bill_no' => 'current_statement',
    ];
}


usort($vv, function ($a, $b) {
    $aDate = new DateTimeImmutable($a['date']);
    $bDate = new DateTimeImmutable($b['date']);

    if ($aDate == $bDate) {
        $corrA = $a['is_correction'] ?? false;
        $corrB = $b['is_correction'] ?? false;
        if ($corrA != $corrB) {
            return $corrB ? 1 : -1;
        }
        return $a['id'] > $b['id'] ? 1 : -1;
    }

    return $aDate > $bDate ? 1 : -1;
});

foreach ($vv as $v) {

    $dataBillsPlus[] = $v;
    $paysPlusBills -= $bill->sum;

    $date = new DateTimeImmutable($v['date']);
    addItem($d, $v, $date);
}


$dataBillsMinus = [];
/** @var \app\models\Bill $bill */
foreach ($billsMinus as $bill) {

    $v = [
        'number' => $bill->bill_no,
        'link' => $bill->link,
        'date' => $bill->bill_date,
        'sum' => $bill->sum,
        'is_paid' => $bill->is_payed, //$paysMinusBills <= $bill->sum ? 1 : (round($paysMinusBills, 4) < 0 ? 2 : 0),
        'type' => 'bill_minus',
        'is_show_in_lk' => $bill->is_show_in_lk,
    ];

    $dataBillsMinus[] = $v;
    $paysMinusBills -= $bill->sum;

    $date = new DateTimeImmutable($bill->bill_date);
    addItem($d, $v, $date);
}

$dataInvoiceExt = [];
/** @var BillExternal $inv */
foreach ($invoiceExt as $inv) {

    $sum = $inv->ext_vat + $inv->ext_sum_without_vat;
    $date = new DateTimeImmutable($inv->ext_invoice_date);
    $v = [
        'number' => $inv->ext_invoice_no,
        'link' => $inv->bill->link,
        'date' => $date->format('Y-m-d'),
        'sum' => $sum,
        'is_paid' => $invoiceExtPays <= $sum ? 1 : (round($invoiceExtPays, 4) < 0 ? 2 : 0),
        'type' => 'invoice_minus',
    ];

    $dataInvoiceExt[] = $v;
    $invoiceExtPays -= $sum;

    addItem($d, $v, $date);
}

static $userCache = [];

function getPaymentInfo(\app\models\Payment $pay)
{
    $type = ($pay->type == 'ecash' ? substr($pay->ecash_operator, 0, 4) : substr($pay->type, 0, 1));
    if ($type == 'b') {
        $type .= ' (' . $pay->bank . ')';
    }
    $info = ($pay->payment_no ? '&#8470;' . $pay->payment_no . ' / ' : '') . $type;
    if ($pay->add_user) {
        $name = explode(" ", trim($pay->addUser->name));
        $info .= ' / ' . $name[0];
    }
    return $info;
}

function getPaymentInfoJson(\app\models\Payment $pay)
{
    return \app\models\PaymentInfo::getInfoText($pay);
}

/** @var \app\models\Payment $pay */
foreach ($paysPlus as $pay) {

    $v = [
        'number' => $pay->payment_no,
        'link' => "",
        'date' => $pay->payment_date,
        'sum' => round($pay->sum, 2),
        'info' => in_array($listFilter, ['income', 'full'], true) ? getPaymentInfo($pay) : '',
        'info_json' => getPaymentInfoJson($pay),
        'is_paid' => null,
        'type' => 'payment',
    ];

    $date = new DateTimeImmutable($pay->payment_date);
    addItem($d, $v, $date);
}

$vv = [];
/** @var \app\models\Payment $pay */
foreach ($paysMinus as $pay) {

    $v = [
        'number' => $pay->payment_no,
        'link' => "",
        'date' => $pay->payment_date,
        'sum' => round($pay->sum, 2),
        'info' => in_array($listFilter, ['income', 'full'], true) ? getPaymentInfo($pay) : '',
        'info_json' => getPaymentInfoJson($pay),
        'is_paid' => null,
        'type' => 'payment_minus',
    ];

    $vv[] = $v;
    $date = new DateTimeImmutable($pay->payment_date);
    addItem($d, $v, $date);
}


foreach ($d as $year => &$yearData) {
    foreach ($yearData as $month => &$monthData) {
        ksort($monthData);
    }
    ksort($yearData);
}
ksort($d);

function addItem(&$data, $item, $date)
{
    $y = (int)$date->format('Y');
    $m = (int)$date->format('m');
    $d = (int)$date->format('d');
    $type = $item['type'];

    if (!isset($data[$y][$m][$d][$type])) {
        $data[$y][$m][$d][$type] = [];
    }
    $data[$y][$m][$d][$type][] = $item;
}

foreach ($d as $year => &$yearData) {
    foreach ($yearData as $month => &$monthData) {
        ksort($monthData);

        foreach ($monthData as $day => $dayData) {
            $nDayData = [];
            foreach ($dayData as $type => $values) {
                foreach ($values as $idx => $value) {
                    if (!isset($nDayData[$idx])) {
                        $nDayData[$idx] = [];
                    }
                    $nDayData[$idx][$value['type']] = $value;
                }
            }
            $monthData[$day] = $nDayData;
        }
    }
    ksort($yearData);
}


class row
{
    public $year = '';
    public $month = '';
    public $day = '';

    public $bill = '';
    public $bill_is_correction = false;
    public $bill_minus = '';
    public $invoice = '';
    public $invoice_minus = '';

    public $payment = '';
    public $payment_minus = '';

    public $bill_is_paid = '';
    public $bill_minus_is_paid = '';
    public $invoice_is_paid = '';
    public $invoice_minus_is_paid = '';

    public $comment = '';
    public $invoice_for_correction = null;

    public $co = '';

    public $saldo = '';
    public $isListCutoffByBalance = false;

}

class rowCorrection extends row
{
    public $bill = '';
    public $sum = '';
    public $date = '';
}


class ChangeCompanyFounder
{
    private $changes = [];
    private $orgs = [];

    public function __construct($arr)
    {
        $this->changes = $arr;
        $this->orgs = \app\models\Organization::dao()->getList();
    }

    public function get()
    {
        if (!$this->changes) {
            return false;
        }
        $keys = array_keys($this->changes);

        $date = $keys[0];

        $co = $this->changes[$date];

        unset($this->changes[$date]);

        return (object)['date' => (new DateTimeImmutable($date))->setTime(0, 0, 0), 'co' => $this->orgs[$co]];
    }
}

class SaldoHelper
{
    private $saldo = [];

    public function __construct(?\app\models\Saldo $saldo)
    {
        if (!$saldo) {
            return;
        }
        $saldo = $saldo->getAttributes(['ts', 'saldo']);
        $saldo['date'] = (new \DateTimeImmutable($saldo['ts']))->setTime(0, 0, 0);

        $this->saldo = $saldo;
    }

    public function getDate()
    {
        if (!$this->saldo) {
            return null;
        }

        return $this->saldo['date'];
    }

    public function __toString()
    {
        if (!$this->saldo) {
            return 'Салдо не установленно';
        }

        return sprintf('Сальдо: %s на %s', $this->saldo['saldo'], $this->saldo['ts']);
    }
}

/** @var array $changeCompany */
$chCo = new ChangeCompanyFounder($changeCompany);

$nextCo = $chCo->get();

$saldoHelper = (new SaldoHelper($saldo));

$rr = [];

$bill_is_paid = null;
$bill_minus_is_paid = null;
$invoice_is_paid = null;
$invoice_minus_is_paid = null;

$prevCo = '';
$isSaldoShown = (bool)$saldo;
foreach ($d as $year => &$yearData) {
    foreach ($yearData as $month => &$monthData) {
        ksort($monthData);

        foreach ($monthData as $day => $dayData) {

            $date = (new DateTimeImmutable($year . '-' . $month . '-' . $day))->setTime(0, 0, 0);
            $co = '';

            $isSetCo = false;
            while ($nextCo && $date >= $nextCo->date) {
//                $co .= $nextCo->co . ' / ' . $nextCo->date->format('Y-m-d') . ' # ';
                $co = $nextCo->co;
                $prevCo = $co;
                $nextCo = $chCo->get();
                $isSetCo = true;
            }

            $row = new row();
            $row->year = $year;
            $row->month = $month;
            $row->day = $day;

            $row->bill_is_paid = $bill_is_paid;
            $row->bill_minus_is_paid = $bill_minus_is_paid;
            $row->invoice_is_paid = $invoice_is_paid;
            $row->invoice_minus_is_paid = $invoice_minus_is_paid;
            $row->co = $isSetCo ? $prevCo : '';

            if ($saldo && $isSaldoShown) {
                if ($saldoHelper->getDate() <= $date) {
                    $row->saldo = $saldoHelper;
                    $isSaldoShown = false;
                }
            }

            $row->isListCutoffByBalance = $isSaldoShown;


            foreach ($dayData as $idx => $typeData) {

                if ($idx > 0) {
                    $rr[] = $row;

                    $row = new row();
                    $row->year = $year;
                    $row->month = $month;
                    $row->day = $day;

                    $row->bill_is_paid = $bill_is_paid;
                    $row->bill_minus_is_paid = $bill_minus_is_paid;
                    $row->invoice_is_paid = $invoice_is_paid;
                    $row->invoice_minus_is_paid = $invoice_minus_is_paid;
                    $row->co = '';
                    $row->isListCutoffByBalance = $isSaldoShown;
                }

                foreach ($typeData as $type => $value) {
                    switch ($type) {
                        case 'bill':
                            $bill_is_paid = $value['is_paid'];
                            $row->bill_is_paid = $bill_is_paid;
                            $row->bill = $value;
                            $row->bill_is_correction = $value['is_correction'];
                            $row->comment = $value['comment'];

                            if (isset($billCorrections[$value['number']])) {
                                $bc = $billCorrections[$value['number']];
                                $rc = new rowCorrection();
                                $rc->bill = $bc['bill_no'];
                                $rc->date = new DateTimeImmutable($bc['created_at']);
                                $rc->sum = $bc['sum'];

                                $rc->bill_is_paid = $bill_is_paid;
                                $rc->bill_minus_is_paid = $bill_minus_is_paid;
                                $rc->invoice_is_paid = $invoice_is_paid;
                                $rc->invoice_minus_is_paid = $invoice_minus_is_paid;
                                $rc->isListCutoffByBalance = $isSaldoShown;

                                $rr[] = $rc;
                            }

                            break;

                        case 'bill_minus':
                            $bill_minus_is_paid = $value['is_paid'];
                            $row->bill_minus_is_paid = $bill_minus_is_paid;
                            $row->bill_minus = $value;
                            break;

                        case 'invoice':
                            $invoice_is_paid = $value['is_paid'];
                            $row->invoice_is_paid = $invoice_is_paid;
                            $row->invoice = $value;
                            break;

                        case 'invoice_correction':
                            $invoice_is_paid = $value['is_paid'];
                            $row->invoice_is_paid = $invoice_is_paid;
                            $row->invoice = $value;
                            $row->invoice_for_correction = true;
                            break;

                        case 'invoice_minus':
                            $invoice_minus_is_paid = $value['is_paid'];
                            $row->invoice_minus_is_paid = $invoice_minus_is_paid;
                            $row->invoice_minus = $value;
                            break;

                        case 'payment':
                            $row->payment = $value;
                            break;

                        case 'payment_minus':
                            $row->payment_minus = $value;
                            break;
                    }
                }
            }
            $rr[] = $row;
        }
    }
}

?>




<?php

function cellContentOptions($is_paid, $addClass = '')
{
    return $is_paid === null
        ? ($addClass ? ['class' => $addClass] : [])
        : ['class' => ($is_paid == 1 ? 'success' : ($is_paid == 2 ? 'warning' : ($is_paid == -1 ? 'info' : 'danger'))) . ($addClass ? ' ' . $addClass : '')];
}

function contentNotShowInLkSpan()
{
    return Html::tag('span', '', ['class' => 'glyphicon glyphicon-eye-close', 'style' => 'padding-left: 5px;', 'title' => 'Не показывать в ЛК']);
}

?>
<style>
    td {
        padding: 4px !important;
        height: 5px !important;
    }

    .correction_bill {
        color: #0d52bf;
    }

    .text-comment {
        margin-left: 9.2%;
    }

    .text-sum-invoice-info {
        color: #c4d3c3;
    }

    .text-co {
        background-color: #9edbf0;
        text-align: center;
        font-size: 7pt;
    }

    .text-saldo {
        background-color: #dbf09e;
        text-align: center;
        font-size: 7pt;
    }

    td.is_not_show_in_lk > a, td.is_not_show_in_lk > span {
        color: #888;
    }

    .list-cutoff-by-balance-tr-class {
        opacity: 50%;
    }

</style>
<?php if ($billOperations) : ?>
<script>

    function setAction(value) {
        $('#action').val(value);
        form = $('#formsend');
        url = form.attr('action');
        if (value == 'bill_mprint') {
            form.prop("target", "_blank");
        } else if (value == 'bill_postreg') {
            form[0].addEventListener('submit', function (event) {
                event.preventDefault();
            });
            $.ajax({
                type: "GET",
                url: url,
                data: form.serialize(),
                complete: function (data) {
                    if (data.status == 0 || data.status == 200) {
                        alert('Выбранные элементы были успешно зарегистрированы');
                    } else {
                        alert('Произошла ошибка');
                    }
                    location.reload();
                }
            });
        }
    }
</script>
<form action="?" method="get" name="formsend" id="formsend" target="_blank">
    <input type="hidden" name="module" value="newaccounts"/>
    <input type="hidden" name="action" id="action" value=""/>
    <input type="hidden" name="document_reports[]" value="bill"/>
    <input type="hidden" name="akt-1" value="1"/>
    <input type="hidden" name="akt-2" value="1"/>
    <input type="hidden" name="akt-3" value="1"/>
    <input type="hidden" name="invoice-1" value="1"/>
    <input type="hidden" name="invoice-2" value="1"/>
    <input type="hidden" name="invoice-3" value="1"/>
    <input type="hidden" name="isBulkPrint" value="1"/>
    <div class="pull-right">
        <button type="submit" class="button" onclick="setAction('bill_email')">Отправить на e-mail</button>
        <button type="submit" class="button" onclick="setAction('bill_mprint')" name="isLandscape" value="1">Печать в
            альбомной ориентации
        </button>
        <button type="submit" class="button" onclick="setAction('bill_mprint')" name="isPortrait" value="1">Печать в
            книжной ориентации
        </button>
        <button type="submit" class="button" onclick="setAction('bill_postreg')">Зарег-ть</button>
    </div>
    <?php endif; ?>
    <div class="row">
        <div class="col-xs-12">
            <?php

            $columns = [
                [
                    'class' => \kartik\grid\ExpandRowColumn::class,
                    'width' => '50px',
//                        'disabled' => true,
                    'hidden' => true,
                    'value' => function ($model) {
                        return $model->comment || $model->co || $model->saldo ? GridView::ROW_EXPANDED : GridView::ROW_COLLAPSED;
                    },
                    'detail' => function ($model) {
                        $return = '';
                        $addClass = (fn(row $row) => ($row->isListCutoffByBalance ? ' list-cutoff-by-balance-tr-class' : ''))($model);

                        if ($model->comment) {
                            $return .= Html::tag('div', $model->comment, ['class' => 'text-comment' . $addClass]);
                        }

                        if ($model->co) {
                            $return .= Html::tag('div', $model->co, ['class' => 'text-co' . $addClass]);
                        }

                        if ($model->saldo) {
                            $return .= Html::tag('div', $model->saldo, ['class' => 'text-saldo' . $addClass]);
                        }

                        return $return;
                    },
                    'headerOptions' => ['class' => 'kartik-sheet-style'],
                    'detailOptions' => ['class' => 'detail-class other'],
                    'detailRowCssClass' => \kartik\grid\GridView::TYPE_ACTIVE,
                ],
                [
                    'label' => 'Дата',
                    'value' => function ($row) {
                        if ($row instanceof rowCorrection) {
                            return '';
                        }
                        $date = (new DateTimeImmutable())->setDate($row->year, $row->month, $row->day)->setTime(0, 0, 0);
                        return Yii::$app->formatter->asDate($date, 'php:Y-m-d');

                    }
                ],
            ];

            if ($listFilter == 'income' || $listFilter == 'full') {
                $columns = array_merge($columns, [
                    [
                        'label' => 'Счет +',
                        'format' => 'raw',
                        'value' => function (row $row) {
                            if ($row instanceof rowCorrection) {
                                return Yii::$app->formatter->asDate($row->date, 'php:Y-m-d');
                            }

                            $isNotShowInLk = $row->bill && isset($row->bill['is_show_in_lk']) && !$row->bill['is_show_in_lk'];

                            return $row->bill
                                ? Html::a($row->bill['number'], $row->bill['link']) . ($isNotShowInLk ? contentNotShowInLkSpan() : '')
                                . ' ' . ($row->bill_is_correction ? Html::tag('span', '(К)', ['title' => 'Корректировочный счет', 'class' => 'correction_bill']) : '')
                                : '';
                        },
                        'contentOptions' => function ($row) {
                            $options = cellContentOptions($row->bill_is_paid);
                            if ($row->bill && isset($row->bill['is_show_in_lk']) && !$row->bill['is_show_in_lk']) {
                                $options['class'] .= ' is_not_show_in_lk';
                            }
                            return $options;
                        },
                    ],
                    [
                        'label' => $currencyLabel,
                        'format' => 'raw',
                        'value' => function (row $row) use ($sumInvoice) {
                            if ($row instanceof rowCorrection) {
                                return Html::tag('span', nf($row->sum), ['class' => 'text-warning',]);
                            }

                            $return = '';
                            $sumInv = null;
                            if ($row->bill) {
                                $sumInv = $sumInvoice[$row->bill['number']] ?? null;
                            }
                            if ($sumInv !== null && abs($row->bill['sum'] - $sumInv) > 0.01) {
                                $return .= Html::tag('span', nf($sumInvoice[$row->bill['number']] ?? ''), [
                                            'class' => 'text-danger',
                                            'style' => ['padding-right' => '10px'],
                                            'title' => 'Расхождение между суммой счета и суммой во всех с/ф этого счета',
                                        ]
                                    ) . ' ';
                            }

                            $return .= $row->bill ? Html::tag('span', nf($row->bill['sum']), ['title' => 'Сумма счета']) : '';

                            return $return;
                        },
                        'contentOptions' => ['class' => 'text-right'],
                    ],
                    [
                        'label' => 'С/ф +',
                        'format' => 'raw',
                        'contentOptions' => function ($row) {
                            return cellContentOptions($row->invoice_is_paid);
                        },

                        'value' => function (row $row) {
                            if ($row instanceof rowCorrection) {
                                return 'корректировка счета';
                            }
                            if ($row->invoice_for_correction) {
                                return $row->invoice['number'];
                            }
                            return $row->invoice ? Html::a($row->invoice['number'], $row->invoice['link']) : '';
                        },
                    ],
                    [
                        'label' => $currencyLabel . ' +',
                        'format' => 'raw',
                        'value' => function (row $row) {
                            if ($row->invoice_for_correction) {
                                return 'корректировка с/ф';
                            }
                            return $row->invoice ? nf($row->invoice['sum']) : '';
                        },
                        'contentOptions' => ['class' => 'text-right'],
                    ],

                    [
                        'label' => 'Платеж +',
                        'format' => 'raw',
                        'value' => function (row $row) {
                            if (!$row->payment) {
                                return '';
                            }

                            $info = $row->payment['info'] ?? '';

                            if ($row->payment['info_json']) {
                                return Html::tag(
                                    'button',
                                    $info !== '' ? Html::tag('small', $info) : 'детали',
                                    [
                                        'class' => 'btn btn-xs',
                                        'data-toggle' => 'popover',
                                        'data-html' => 'true',
                                        'data-placement' => 'bottom',
                                        'data-content' => Html::tag('pre', $row->payment['info_json']),
                                    ]
                                );
                            }

                            return $info !== '' ? Html::tag('small', $info) : '';
                        },
                    ],
                    [
                        'label' => 'Сумма +',
                        'format' => 'raw',
                        'value' => function (row $row) {
                            return $row->payment ? nf($row->payment['sum']) : '';
                        },
                        'contentOptions' => ['class' => 'text-right'],
                    ],
                ]);
            }

            if ($listFilter == 'full' || $listFilter == 'outcome') {
                $columns = array_merge($columns, [
                    [
                        'label' => 'Счет -',
                        'format' => 'raw',
                        'value' => function (row $row) {
                            return $row->bill_minus ? Html::a($row->bill_minus['number'], $row->bill_minus['link']) : '';
                        },
                        'contentOptions' => function ($row) {
                            return cellContentOptions($row->bill_minus_is_paid);
                        },
                    ],
                    [
                        'label' => $currencyLabel . ' -',
                        'format' => 'raw',
                        'value' => function (row $row) {
                            return $row->bill_minus ? nf($row->bill_minus['sum']) : '';
                        },
                        'contentOptions' => ['class' => 'text-right'],
                    ],
                    [
                        'label' => 'С/ф -',
                        'format' => 'raw',
                        'value' => function (row $row) {
                            return $row->invoice_minus ? Html::a($row->invoice_minus['number'], $row->invoice_minus['link']) : '';
                        },
                        'contentOptions' => function ($row) {
                            return cellContentOptions($row->invoice_minus_is_paid);
                        },
                    ],
                    [
                        'format' => 'raw',
                        'value' => function (row $row) {
                            return $row->invoice_minus ? nf($row->invoice_minus['sum']) : '';
                        },
                        'label' => $currencyLabel . ' -',
                        'contentOptions' => ['class' => 'text-right'],
                    ],
                    [
                        'label' => 'Платеж -',
                        'format' => 'raw',
                        'value' => function (row $row) {
                            if (!$row->payment_minus) {
                                return '';
                            }

                            $info = $row->payment_minus['info'] ?? '';

                            if ($row->payment_minus['info_json']) {
                                return Html::tag(
                                    'button',
                                    $info !== '' ? Html::tag('small', $info) : 'детали',
                                    [
                                        'class' => 'btn btn-xs',
                                        'data-toggle' => 'popover',
                                        'data-html' => 'true',
                                        'data-placement' => 'bottom',
                                        'data-content' => Html::tag('pre', $row->payment_minus['info_json']),
                                    ]
                                );
                            }

                            return $info !== '' ? Html::tag('small', $info) : '';
                        },
                    ],
                    [
                        'label' => 'Сумма -',
                        'format' => 'raw',
                        'value' => function (row $row) {
                            return $row->payment_minus ? nf($row->payment_minus['sum']) : '';
                        },
                        'contentOptions' => ['class' => 'text-right'],
                    ],
                ]);
            }

            if ($billOperations) {
                $columns[] = [
                    'class' => 'kartik\grid\CheckboxColumn',
                    'checkboxOptions' => function (Row $row) {
                        return [
                            'hidden' => !($row->bill && ($row->bill['number'] ?? false) && !($row instanceof rowCorrection)),
                            'value' => $row->bill['number']
                        ];
                    },
                    'name' => 'bill',
                ];
            }

            echo GridView::widget([
                    'dataProvider' => new ArrayDataProvider([
                        'allModels' => array_reverse($rr),
//                'allModels' => $rr,
                        'pagination' => false,
                    ]),
                    'panelHeadingTemplate' => '',
                    'rowOptions' => fn(row $row) => $row->isListCutoffByBalance ? ['class' => 'list-cutoff-by-balance-tr-class'] : [],

                    'columns' => $columns,
                ]
            );
            ?>
        </div>
    </div>
</form>
<script>
    $(function () {
        $('[data-toggle="popover"]').popover()
    })
</script>
<style type="text/css">
    .popover {
        max-width: 600px;
    }
</style>
