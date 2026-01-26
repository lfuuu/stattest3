<?php

namespace app\classes\excel;

use app\helpers\DateTimeZoneHelper;
use app\models\ClientContragent;
use app\models\filter\SaleBookFilter;
use app\models\Invoice;
use DateTime;
use app\models\Organization;
use app\modules\uu\models\ServiceType;
use app\helpers\SaleBookHelper;

/** @var SaleBookFilter $filter */
class BalanceSellToExcel extends Excel
{

    private
        $insertPosition = 12;

    public
        /** @var \app\models\Organization $organization */
        $organization,
        $dateFrom,
        $dateTo,
        $filter,
        $skipping_bps;


    public function init()
    {
        $this->openFile(\Yii::getAlias('@app/templates/balance_sell.xls'));

        $this->organization = Organization::find()
            ->byId($this->filter->organization_id)
            ->actual()
            ->one();
        $this->dateFrom = $this->filter->date_from;
        $this->dateTo = $this->filter->date_to;

        $data = $this->_dataConversionToStandard();

        $this->prepare($data);
    }


    /**
     * @return array
     */
    private function _dataConversionToStandard()
    {
        $data = [];
        $query = $this->filter->search();
//if($query){
    //$query->andWhere(['inv.number' => ['1251001-0984', '2250901-3329', '1251101-0668', '1251001-1088','1251001-0984','1251101-0668', '1251101-2452']]);
    //$query->limit(10);
    //}
        foreach ($query->each() as $invoice) {

            if (!$this->filter->check($invoice)) {
                continue;
            }

            /** @var \app\models\filter\SaleBookFilter $invoice */
            $account = $invoice->bill->clientAccount;
            $contract = $account->contract;
            $currencyModel = $account->currencyModel;

            $contragent = $contract->contragent;
            $currencyId = $currencyModel->id;
            $currencyName = $currencyModel->name;
            $currencyCode = $currencyModel->code;
//            $taxRate = $account->getTaxRate();
            $paymentsStr = $invoice->getPaymentsStr();

            $sumTax = 0;

            $lineData = [
                'sum20' => 0, 'tax20' => 0,
                'sum18' => 0, 'tax18' => 0,
                'sum10' => 0, 'tax10' => 0,
                'sum7' => 0, 'tax7' => 0,
                'sum5' => 0, 'tax5' => 0,
                'sum0' => 0, 'tax0' => 0,
                'sum0_agent' => 0,
            ];
            foreach ($invoice->lines as $line) {
                if ($line->tax_rate > 0) {
                    $sumTax += $line['sum'];
                }

                $taxRate  = (int)$line->tax_rate;
                $isVatsTs = SaleBookHelper::isTelephonyService($line, $this->filter);

                if ($taxRate === 0) {
                    if ($isVatsTs) {
                        $lineData['sum0'] += $line->sum_without_tax;
                        $lineData['tax0'] += $line->sum_tax;
                    } else {
                        $lineData['sum0_agent'] += $line->sum_without_tax;
                    }
                } else {
                    if (!isset($lineData['sum' . $taxRate])) {
                        $lineData['sum' . $taxRate] = 0;
                        $lineData['tax' . $taxRate] = 0;
                    }
                    $lineData['sum' . $taxRate] += $line->sum_without_tax;
                    $lineData['tax' . $taxRate] += $line->sum_tax;
                }
            }

            $data[] = [
                'code' => $invoice->type_id == Invoice::TYPE_PREPAID ? '02' : '01',
                'sum' => $invoice->sum,
                'sum_without_tax' => $invoice->type_id !== Invoice::TYPE_PREPAID ? $invoice->sum_without_tax : null,
                'sum_tax' => $invoice->sum_tax,
                'company_full' => trim($contragent->name_full),
                'inn' => trim($contragent->inn),
                'kpp' => trim($contragent->kpp),
                'inv_no' => $invoice->number . '; ' . $invoice->getDateImmutable()->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED),
                'type' => $contragent->legal_type,
                'correction' => ($invoice->correction_idx ? $invoice->correction_idx . '; ' . $invoice->getDateImmutable()->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED) : ''),
                'currency_id' => $currencyId,
                'currency_name' => $currencyName,
                'currency_code' => $currencyCode,
                'payments_str' => $paymentsStr,
                'sumTax' => $sumTax,
                'tax_regime' => ClientContragent::$taxRegtimeTypes[$contragent->tax_regime],
                'lineData' => $lineData,
            ];
        }
        return $data;
    }

    
    public function prepare(array $data)
    {
        /** @var \PHPExcel_Worksheet $worksheet */
        $worksheet = $this->document->getActiveSheet();

        $data = array_values($data);
        $worksheet->insertNewRowBefore($this->insertPosition, count($data) - 1);

        $this->setCompanyName($worksheet);
        $this->setTaxRegistration($worksheet);
        $this->setDateRange($worksheet);

        $l = function ($pColumn = 0, $pRow = 1, $pValue = null, $returnCell = false) use ($worksheet) {
            return $worksheet->setCellValueByColumnAndRow($pColumn, $pRow, $pValue, $returnCell);
        };

        $p = fn($value) => sprintf('%0.2f', round($value, 2));


        for ($i = 0, $t = count($data); $i < $t; $i++) {
            $row = $data[$i];

            $line = $i + $this->insertPosition - 1;
            $companyName = str_replace(['«', '»'], '"', html_entity_decode($row['company_full']));

            $l(0, $line, ($i + 1));
            $l(1, $line, $row['code']);
            $l(2, $line, $row['inv_no']);
            $l(5, $line, $row['correction']);
            $l(8, $line, $companyName);
            $l(9, $line,$row['inn'] . ($row['type'] == 'legal' ? '/' . ($row['kpp'] ?: '') : ''));
            $l(12, $line, $row['payments_str']);
            $l(13, $line, $row['currency_id'] == 'RUB' ? ' ' : $row['currency_name'] .' '. $row['currency_code']);
            $l(14, $line, $row['currency_id'] == 'RUB' ? '' : $p($row['sum']));
            $l(15, $line, $p($row['sum']));

            $lineData = $row['lineData'];
            $l(16, $line, $lineData['sum20'] ? $p($lineData['sum20']) : '');
            $l(17, $line, $lineData['sum18'] ? $p($lineData['sum18']) : '');
            $l(18, $line, $lineData['sum10'] ? $p($lineData['sum10']) : '');
            $l(19, $line, $lineData['sum7'] ? $p($lineData['sum7']) : '');
            $l(20, $line, $lineData['sum5'] ? $p($lineData['sum5']) : '');
            $l(21, $line, $lineData['sum0'] ? $p($lineData['sum0']) : '');

            $l(22, $line, $lineData['tax20'] ? $p($lineData['tax20']) : '');
            $l(23, $line, $lineData['tax18'] ? $p($lineData['tax18']) : '');
            $l(24, $line, $lineData['tax10'] ? $p($lineData['tax10']) : '');
            $l(25, $line, $lineData['tax7'] ? $p($lineData['tax7']) : '');
            $l(26, $line, $lineData['tax5'] ? $p($lineData['tax5']) : '');
            $l(27, $line, ($row['sumTax'] ?? 0) > 0 ? $p($row['sumTax']) : '');
            $l(28, $line, $lineData['sum0_agent'] ? $p($lineData['sum0_agent']) : '');
            $l(29, $line, $row['tax_regime']);
        }
    }

    private function setCompanyName(\PHPExcel_Worksheet $worksheet)
    {
        if (!($this->organization instanceof Organization)) {
            return false;
        }

        $cell = $worksheet->getCell('A4');
        $value = str_replace('{Name}', $this->organization->name, $cell->getValue());
        $worksheet->setCellValue('A4', $value);
    }

    private function setTaxRegistration(\PHPExcel_Worksheet $worksheet)
    {
        if (!($this->organization instanceof Organization)) {
            return false;
        }

        $cell = $worksheet->getCell('A5');
        $value =
            str_replace(
                '{InnKpp}',
                $this->organization->tax_registration_id .
                (
                $this->organization->tax_registration_reason
                    ? '/' . $this->organization->tax_registration_reason
                    : ''
                ),
                $cell->getValue()
            );
        $worksheet->setCellValue('A5', $value);
    }

    private function setDateRange(\PHPExcel_Worksheet $worksheet)
    {
        $cell = $worksheet->getCell('A6');
        $value = $cell->getValue();

        if ($this->dateFrom) {
            $value = str_replace('{DateFrom}', (new DateTime($this->dateFrom))->format('d.m.Y'), $value);
        }

        if ($this->dateTo) {
            $value = str_replace('{DateTo}', (new DateTime($this->dateTo))->format('d.m.Y'), $value);
        }

        $worksheet->setCellValue('A6', $value);
    }

}
