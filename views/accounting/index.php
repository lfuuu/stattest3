<?php

use app\classes\grid\GridView;
use app\classes\Html;
use app\models\BillExternal;
use app\models\ClientAccount;
use app\models\ClientContract;
use app\models\Invoice;
use app\models\InvoicePaymentLink;
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
        ['label' => 'Обновить баланс (новая версия)', 'url' => ['/', 'module' => 'newaccounts', 'action' => 'bill_balance', 'returning' => 'accounting'], 'class' => 'btn btn-info btn-xs'],
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

$invoiceNumbersById = [];
foreach ($invoices as $invoice) {
    $invoiceNumbersById[$invoice->id] = $invoice->number;
}

$paymentInfoById = [];
foreach (array_merge($paysPlus, $paysMinus) as $pay) {
    $paymentInfoById[$pay->id] = [
        'no' => $pay->headerShort,
        'sum' => round($pay->sum, 2),
        'is_valid_no' => $pay->isPaymentNoValid(),
    ];
}

$paymentInfoByInvoiceId = [];
$paymentIdsByInvoiceId = [];
$invoiceNumbersByPaymentId = [];
$invoiceIdsByPaymentId = [];

$invoiceIds = array_keys($invoiceNumbersById);

if ($invoiceIds) {
    $query = InvoicePaymentLink::find()
        ->select(['invoice_id', 'payment_id', 'sum', 'is_matched'])
        ->where(['client_account_id' => $account->id]);

    $links = $query->all();

    foreach ($links as $link) {
        $invoiceId = (int)$link->invoice_id;
        $paymentId = (int)$link->payment_id;
        $linkSum = (float)$link->sum;

        if (isset($paymentInfoById[$paymentId])) {
            $paymentInfoByInvoiceId[$invoiceId][$paymentId] = [
                'no' => $paymentInfoById[$paymentId]['no'] ?? null,
                'sum' => $linkSum,
                'is_matched' => (bool) $link->is_matched,
                'is_valid_no' => $paymentInfoById[$paymentId]['is_valid_no'] ?? false,
            ];
        }

        if (isset($invoiceNumbersById[$invoiceId])) {
            $invoiceNumbersByPaymentId[$paymentId][] = $invoiceNumbersById[$invoiceId];
        }

        $paymentIdsByInvoiceId[$invoiceId][] = $paymentId;
        $invoiceIdsByPaymentId[$paymentId][] = $invoiceId;
    }
}

foreach ($paymentInfoByInvoiceId as $invoiceId => $items) {
    $paymentInfoByInvoiceId[$invoiceId] = array_values($items);
}

foreach ($paymentIdsByInvoiceId as $invoiceId => $ids) {
    $paymentIdsByInvoiceId[$invoiceId] = array_values(array_unique(array_filter($ids)));
}

foreach ($invoiceNumbersByPaymentId as $paymentId => $numbers) {
    $invoiceNumbersByPaymentId[$paymentId] = array_values(array_unique(array_filter($numbers)));
}

foreach ($invoiceIdsByPaymentId as $paymentId => $ids) {
    $invoiceIdsByPaymentId[$paymentId] = array_values(array_unique(array_filter($ids)));
}


/** @var Invoice $invoice */
foreach ($invoices as $invoice) {

    $v = [
        'id' => $invoice->id,
        'number' => $invoice->number,
        'link' => $invoice->link,
        'date' => $invoice->date,
        'sum' => round($invoice->sum, 2),
        'payment_info' => $paymentInfoByInvoiceId[$invoice->id] ?? [],
        'linked_payment_ids' => $paymentIdsByInvoiceId[$invoice->id] ?? [],
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

function getPaymentInfoHeaderShort(\app\models\Payment $pay)
{
    return getPaymentInfoHeader($pay, false);
}
function getPaymentInfoHeaderFull(\app\models\Payment $pay)
{
    return getPaymentInfoHeader($pay, false);
}

function getPaymentInfoHeader(\app\models\Payment $pay, $isFull = true)
{
    $type = ($pay->type == 'ecash' ? substr($pay->ecash_operator, 0, 4) : substr($pay->type, 0, 1));

    if ($type == 'b') {
        $type .= ' (' . $pay->bank . ')';
    }

    $info = '';
    if ($pay->type == 'api') {
        $infoJson = json_decode($pay->apiInfo->info_json, true);
        if (isset($infoJson['id']) && isset($infoJson['date']) && isset($infoJson['payerName'])) {
            $info = ($infoJson['id'] ?? $pay->payment_no) . ' от ' . (new DateTime($infoJson['date'] ?? $pay->payment_date))->format(\app\helpers\DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED)  . ($isFull ? ' / банк: API/' . $pay->apiChannel->name : '');
        }
    }


//        $info = var_export($pay->getAttributes(), true);
    if (!$info && $pay->type == 'bank') {
        $info = ($pay->payment_no ? $pay->payment_no . ' от ' . (new DateTime($pay->payment_date))->format(\app\helpers\DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED) : '') . ($isFull ? ' / банк: ' . $pay->bank : '');
    } else if (!$info) {
        $info = ($pay->payment_no ? '&#8470;' . $pay->payment_no . ($isFull ? ' / ' : '') : '') . ($isFull ? $type : '');
    }

    if ($isFull && $pay->add_user) {
        $name = explode(" ", trim($pay->addUser->name));
        $info .= ' / ' . $name[0];
    }
    return $info;
}

function formatInvoiceNumbersLabel(array $invoiceNumbers)
{
    $invoiceNumbers = array_values(array_unique(array_filter($invoiceNumbers)));
    if (!$invoiceNumbers) {
        return '';
    }

    $numbers = implode(', ', array_map(function ($n) {
        return '&#8470;' . $n;
    }, $invoiceNumbers));

    return 'с/ф: ' . $numbers;
}

function formatPaymentInfoBaseParts($infoBase)
{
    if ($infoBase === null || $infoBase === '') {
        return ['', ''];
    }
    $parts = explode(' / ', $infoBase, 2);
    $head = $parts[0] ?? '';
    $tail = $parts[1] ?? '';

    return [$head, $tail];
}

function formatPaymentNumbersSuffix(array $paymentInfo)
{
    if (!$paymentInfo) {
        return '';
    }

    $items = [];
    foreach ($paymentInfo as $item) {
        $number = $item['no'] ?? null;
        $sum = $item['sum'] ?? null;
        $isMatched = $item['is_matched'] ?? false;
        $isValidNo = $item['is_valid_no'] ?? false;

        $label = $number;
        if ($sum !== null && $sum !== '') {
            $label .= ($label !== '' ? ' ' : '') . '(' . nf($sum) . ')';
        }

        if ($label !== '') {
            if (!$isMatched) {
                $label = Html::tag('span', $label, ['class' => 'text-warning', 'title' => 'Не совпадает по дате']);
            } elseif (!$isValidNo) {
                $label = Html::tag('span', $label, ['class' => 'text-danger', 'title' => 'Нецифровой номер платежа']);
            }
            $items[] = $label;
        }
    }

    if (!$items) {
        return '';
    }

    $numbers = implode(', ', $items);

    return ' ' . Html::tag('small', $numbers, ['class' => 'text-muted']);
}

function getPaymentInfoJson(\app\models\Payment $pay)
{
    return \app\models\PaymentInfo::getInfoText($pay);
}

/** @var \app\models\Payment $pay */
foreach ($paysPlus as $pay) {

    $invoiceNumbers = $invoiceNumbersByPaymentId[$pay->id] ?? [];
    $paymentHeader = in_array($listFilter, ['income', 'full'], true)
        ? $pay->headerFull
        : '';
    $paymentHeaderMain = '';
    $paymentHeaderTail = '';
    if ($paymentHeader) {
        $parts = explode(' / ', $paymentHeader, 2);
        $paymentHeaderMain = $parts[0] ?? '';
        $paymentHeaderTail = $parts[1] ?? '';
    }
    $invoiceLabel = formatInvoiceNumbersLabel($invoiceNumbers);

    $v = [
        'id' => $pay->id,
        'number' => $pay->payment_no,
        'link' => "",
        'date' => $pay->payment_date,
        'sum' => round($pay->sum, 2),
        'payment_header_main' => $paymentHeaderMain,
        'payment_header_tail' => $paymentHeaderTail,
        'invoice_label' => $invoiceLabel,
        'info_json' => getPaymentInfoJson($pay),
        'invoice_numbers' => $invoiceNumbers,
        'linked_invoice_ids' => $invoiceIdsByPaymentId[$pay->id] ?? [],
        'is_paid' => null,
        'type' => 'payment',
    ];

    $date = new DateTimeImmutable($pay->payment_date);
    addItem($d, $v, $date);
}

$vv = [];
/** @var \app\models\Payment $pay */
foreach ($paysMinus as $pay) {

    $invoiceNumbers = $invoiceNumbersByPaymentId[$pay->id] ?? [];
    $paymentHeader = in_array($listFilter, ['income', 'full'], true)
        ? $pay->headerFull
        : '';
    $paymentHeaderMain = '';
    $paymentHeaderTail = '';
    if ($paymentHeader) {
        $parts = explode(' / ', $paymentHeader, 2);
        $paymentHeaderMain = $parts[0] ?? '';
        $paymentHeaderTail = $parts[1] ?? '';
    }
    $invoiceLabel = formatInvoiceNumbersLabel($invoiceNumbers);

    $v = [
        'id' => $pay->id,
        'number' => $pay->payment_no,
        'link' => "",
        'date' => $pay->payment_date,
        'sum' => round($pay->sum, 2),
        'payment_header_main' => $paymentHeaderMain,
        'payment_header_tail' => $paymentHeaderTail,
        'invoice_label' => $invoiceLabel,
        'info_json' => getPaymentInfoJson($pay),
        'invoice_numbers' => $invoiceNumbers,
        'linked_invoice_ids' => $invoiceIdsByPaymentId[$pay->id] ?? [],
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
    public $payment_type_change = '';

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

class ChangePaymentType
{
    private $changes = [];
    private $idx = 0;

    public function __construct($arr)
    {
        $this->changes = array_values($arr ?: []);
    }

    public function get()
    {
        if (!isset($this->changes[$this->idx])) {
            return false;
        }

        $change = $this->changes[$this->idx++];
        $date = $change['date'] ?? null;
        if (!$date) {
            return $this->get();
        }

        return (object)[
            'date' => (new DateTimeImmutable($date))->setTime(0, 0, 0),
            'from' => $change['from'] ?? null,
            'to' => $change['to'] ?? null,
        ];
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

/** @var array $changePaymentScheme */
$chPay = new ChangePaymentType($changePaymentScheme ?? []);
$nextPay = $chPay->get();
$paymentTypeLabel = (new ClientAccount())->getAttributeLabel('is_postpaid');

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

            $paymentTypeChangeText = '';
            while ($nextPay && $date >= $nextPay->date) {
                $to = $nextPay->to;
                $toLabel = ($to !== null && $to !== '') ? (ClientAccount::$paymentTypes[$to] ?? $to) : $to;
                $paymentTypeChangeText = $paymentTypeLabel . ': ' . $toLabel;

                $nextPay = $chPay->get();
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
            $row->payment_type_change = $paymentTypeChangeText;

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
                    $row->payment_type_change = '';
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

    .accounting-col-bill {
        width: 160px;
        max-width: 160px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .accounting-col-date {
        width: 80px;
        max-width: 80px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .accounting-col-wide {
        width: 480px;
        max-width: 480px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .text-sum-invoice-info {
        color: #c4d3c3;
    }

    .text-co {
        background-color: #9edbf0;
        text-align: center;
        font-size: 7pt;
    }

    .text-payment-type-change {
        background-color: #f2e6b4;
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
                        return $model->comment || $model->co || $model->payment_type_change || $model->saldo ? GridView::ROW_EXPANDED : GridView::ROW_COLLAPSED;
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

                        if ($model->payment_type_change) {
                            $items = is_array($model->payment_type_change) ? $model->payment_type_change : [$model->payment_type_change];
                            foreach ($items as $item) {
                                $return .= Html::tag('div', $item, ['class' => 'text-payment-type-change' . $addClass]);
                            }
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
                    'headerOptions' => ['class' => 'accounting-col-date'],
                    'contentOptions' => ['class' => 'accounting-col-date'],
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
                        'headerOptions' => ['class' => 'accounting-col-bill'],
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
                            $options = cellContentOptions($row->bill_is_paid, 'accounting-col-bill');
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
                        'headerOptions' => ['class' => 'accounting-col-wide'],
                        'contentOptions' => function ($row) {
                            return cellContentOptions($row->invoice_is_paid, 'accounting-col-wide');
                        },

                        'value' => function (row $row) {
                            if ($row instanceof rowCorrection) {
                                return 'корректировка счета';
                            }
                            if ($row->invoice_for_correction) {
                                return $row->invoice['number'];
                            }
                            if (!$row->invoice) {
                                return '';
                            }
                            $content = Html::a($row->invoice['number'], $row->invoice['link'], ['class' => 'linked-entity-target'])
                                . formatPaymentNumbersSuffix($row->invoice['payment_info'] ?? []);

                            $attrs = [
                                'class' => 'js-linked-entity js-linked-invoice',
                                'data-invoice-id' => $row->invoice['id'] ?? '',
                                'data-linked-payment-ids' => implode(',', $row->invoice['linked_payment_ids'] ?? []),
                            ];

                            return Html::tag('span', $content, $attrs);
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
                        'headerOptions' => ['class' => 'accounting-col-wide'],
                        'value' => function (row $row) {
                            if (!$row->payment) {
                                return '';
                            }

                            $paymentHeaderHead = $row->payment['payment_header_main'] ?? '';
                            $paymentHeaderTail = $row->payment['payment_header_tail'] ?? '';
                            $invoiceLabel = $row->payment['invoice_label'] ?? '';
                            $linkedIds = implode(',', $row->payment['linked_invoice_ids'] ?? []);
                            $paymentHeaderLabel = $paymentHeaderHead !== ''
                                ? Html::tag(
                                    'small',
                                    Html::tag('span', Html::encode($paymentHeaderHead), ['class' => 'linked-entity-target'])
                                    . ($paymentHeaderTail !== '' ? ' / ' . Html::encode($paymentHeaderTail) : '')
                                )
                                : '';

                            if ($row->payment['info_json']) {
                                $buttonLabel = $paymentHeaderLabel !== '' ? $paymentHeaderLabel : 'детали';
                                $button = Html::tag(
                                    'button',
                                    $buttonLabel,
                                    [
                                        'class' => 'btn btn-xs',
                                        'data-toggle' => 'popover',
                                        'data-html' => 'true',
                                        'data-placement' => 'bottom',
                                        'data-content' => Html::tag('pre', $row->payment['info_json']),
                                    ]
                                );
                                if ($invoiceLabel !== '') {
                                    $button .= ' ' . Html::tag('small', $invoiceLabel, ['class' => 'text-muted']);
                                }
                                return Html::tag('span', $button, [
                                    'class' => 'js-linked-entity js-linked-payment',
                                    'data-payment-id' => $row->payment['id'] ?? '',
                                    'data-linked-invoice-ids' => $linkedIds,
                                ]);
                            }

                            $label = $paymentHeaderLabel !== '' ? $paymentHeaderLabel : '';
                            if ($invoiceLabel !== '') {
                                $label .= ($label !== '' ? ' ' : '') . Html::tag('small', $invoiceLabel, ['class' => 'text-muted']);
                            }
                            return Html::tag('span', $label, [
                                'class' => 'js-linked-entity js-linked-payment',
                                'data-payment-id' => $row->payment['id'] ?? '',
                                'data-linked-invoice-ids' => $linkedIds,
                            ]);
                        },
                        'contentOptions' => ['class' => 'info accounting-col-wide'],
                    ],
                    [
                        'label' => $currencyLabel . ' +',
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
                            return cellContentOptions($row->bill_minus_is_paid, 'accounting-col-bill');
                        },
                        'headerOptions' => ['class' => 'accounting-col-bill'],
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
                        'headerOptions' => ['class' => 'accounting-col-wide'],
                        'value' => function (row $row) {
                            return $row->invoice_minus ? Html::a($row->invoice_minus['number'], $row->invoice_minus['link']) : '';
                        },
                        'contentOptions' => function ($row) {
                            return cellContentOptions($row->invoice_minus_is_paid, 'accounting-col-wide');
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
                        'headerOptions' => ['class' => 'accounting-col-wide'],
                        'value' => function (row $row) {
                            if (!$row->payment_minus) {
                                return '';
                            }

                            $paymentHeaderHead = $row->payment_minus['payment_header_main'] ?? '';
                            $paymentHeaderTail = $row->payment_minus['payment_header_tail'] ?? '';
                            $invoiceLabel = $row->payment_minus['invoice_label'] ?? '';
                            $linkedIds = implode(',', $row->payment_minus['linked_invoice_ids'] ?? []);
                            $paymentHeaderLabel = $paymentHeaderHead !== ''
                                ? Html::tag(
                                    'small',
                                    Html::tag('span', Html::encode($paymentHeaderHead), ['class' => 'linked-entity-target'])
                                    . ($paymentHeaderTail !== '' ? ' / ' . Html::encode($paymentHeaderTail) : '')
                                )
                                : '';

                            if ($row->payment_minus['info_json']) {
                                $buttonLabel = $paymentHeaderLabel !== '' ? $paymentHeaderLabel : 'детали';
                                $button = Html::tag(
                                    'button',
                                    $buttonLabel,
                                    [
                                        'class' => 'btn btn-xs',
                                        'data-toggle' => 'popover',
                                        'data-html' => 'true',
                                        'data-placement' => 'bottom',
                                        'data-content' => Html::tag('pre', $row->payment_minus['info_json']),
                                    ]
                                );
                                if ($invoiceLabel !== '') {
                                    $button .= ' ' . Html::tag('small', $invoiceLabel, ['class' => 'text-muted']);
                                }
                                return Html::tag('span', $button, [
                                    'class' => 'js-linked-entity js-linked-payment',
                                    'data-payment-id' => $row->payment_minus['id'] ?? '',
                                    'data-linked-invoice-ids' => $linkedIds,
                                ]);
                            }

                            $label = $paymentHeaderLabel !== '' ? $paymentHeaderLabel : '';
                            if ($invoiceLabel !== '') {
                                $label .= ($label !== '' ? ' ' : '') . Html::tag('small', $invoiceLabel, ['class' => 'text-muted']);
                            }
                            return Html::tag('span', $label, [
                                'class' => 'js-linked-entity js-linked-payment',
                                'data-payment-id' => $row->payment_minus['id'] ?? '',
                                'data-linked-invoice-ids' => $linkedIds,
                            ]);
                        },
                        'contentOptions' => ['class' => 'info accounting-col-wide'],
                    ],
                    [
                        'label' => $currencyLabel . ' -',
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
        $('[data-toggle="popover"]').popover();

        function parseIds(raw) {
            if (!raw) {
                return [];
            }
            return String(raw)
                .split(',')
                .map(function (v) { return v.trim(); })
                .filter(function (v) { return v.length > 0; });
        }

        function toggleHighlight($el, shouldAdd) {
            var $targets = $el.find('.linked-entity-target');
            if ($targets.length === 0) {
                return;
            }
            $targets.toggleClass('linked-entity-highlight', shouldAdd);
        }

        $(document).on('mouseenter', '.js-linked-entity', function () {
            var $el = $(this);
            var linkedPayments = parseIds($el.data('linkedPaymentIds'));
            var linkedInvoices = parseIds($el.data('linkedInvoiceIds'));

            toggleHighlight($el, true);

            linkedPayments.forEach(function (id) {
                toggleHighlight($('[data-payment-id="' + id + '"]'), true);
            });

            linkedInvoices.forEach(function (id) {
                toggleHighlight($('[data-invoice-id="' + id + '"]'), true);
            });
        });

        $(document).on('mouseleave', '.js-linked-entity', function () {
            var $el = $(this);
            var linkedPayments = parseIds($el.data('linkedPaymentIds'));
            var linkedInvoices = parseIds($el.data('linkedInvoiceIds'));

            toggleHighlight($el, false);

            linkedPayments.forEach(function (id) {
                toggleHighlight($('[data-payment-id="' + id + '"]'), false);
            });

            linkedInvoices.forEach(function (id) {
                toggleHighlight($('[data-invoice-id="' + id + '"]'), false);
            });
        });
    });
</script>
<style type="text/css">
    .popover {
        max-width: 600px;
    }

    .linked-entity-highlight {
        background: #fff3b0;
        border-radius: 2px;
    }
</style>
