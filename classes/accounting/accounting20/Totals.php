<?php

namespace app\classes\accounting\accounting20;

use app\models\BillExternal;
use app\models\ClientAccount;
use app\models\Invoice;
use app\models\Saldo;
use yii\base\BaseObject;

/**
 * Class totals
 *
 * @property float totalPlus
 * @property float totalMinus
 * @property float totalBills
 */
class Totals extends BaseObject
{
    public ?ClientAccount $account = null;
    public float $invSum = 0;

    public float $billSumPlus = 0;
    public float $billSumMinus = 0;

    public float $paysPlusSum = 0;
    public float $paysMinusSum = 0;

    public float $invoiceExtSum = 0;
    public float $invoiceExtPays = 0;

    public float $paysPlusBills = 0;
    public float $paysMinusBills = 0;
    public float $paysPlusInv = 0;

    public ?Lists $lists = null;

    private ?string $saldoDate = null;

    public function getTotalPlus(): float
    {
        return $this->paysPlusSum - $this->billSumPlus;
    }

    public function getTotalMinus(): float
    {
        return $this->billSumMinus - $this->paysMinusSum;
    }

    public function getTotalBills(): float
    {
        return $this->totalPlus - $this->totalMinus;
    }

    public function __construct($config = [])
    {
        parent::__construct($config);

        if (!$this->lists) {
            throw new \InvalidArgumentException('Lists not set');
        }

        $lists = $this->lists;

        $saldo = Saldo::getLastSaldo($this->account->id);
        $this->saldoDate = $saldo ? $saldo->ts : null;

        $this->invSum = $this->filterAndReduce(
            array_filter($lists->invoices, fn(Invoice $i) => $i->type_id != Invoice::TYPE_PREPAID),
            fn($acum, $i) => $acum + $i->sum
        );
        $this->billSumPlus = $this->filterAndReduce($lists->billsPlus, fn($acum, $i) => $acum + $i->sum);
        $this->billSumMinus = $this->filterAndReduce($lists->billsMinus, fn($acum, $i) => $acum + $i->sum);
        $this->invoiceExtSum = $this->filterAndReduce($lists->invoiceExt, fn($acum, BillExternal $i) => $acum + $i->ext_vat + $i->ext_sum_without_vat);
        $this->paysPlusSum = $this->filterAndReduce($lists->paysPlus, fn($acum, $i) => $acum + $i->sum);
        $this->paysMinusSum = $this->filterAndReduce($lists->paysMinus, fn($acum, $i) => $acum + $i->sum);

        $this->paysPlusInv = $this->paysPlusBills = $this->paysPlusSum;
        $this->invoiceExtPays = $this->paysMinusBills = $this->paysMinusSum;
    }

    public function filterAndReduce(array $list, \Closure $fn)
    {
        return array_reduce($this->filterByDate($list, $this->saldoDate), $fn, 0);
    }

    public function filterByDate($list, $date)
    {
        if (!$date || !$list) {
            return $list;
        }

        $el = reset($list);

        if (!(new \ReflectionClass(get_class($el)))->hasConstant('dateField')) {
            throw new \RuntimeException(sprintf('В модели %s не найдена константа с названием поля с датой', get_class($el)));
        }

        return array_filter($list, function($el) use ($date) { return $el->{$el::dateField} >= $date;});
    }

}