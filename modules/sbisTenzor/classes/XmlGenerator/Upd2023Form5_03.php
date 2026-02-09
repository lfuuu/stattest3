<?php

namespace app\modules\sbisTenzor\classes\XmlGenerator;

use app\helpers\DateTimeZoneHelper;
use app\models\Invoice;

class Upd2023Form5_03 extends Invoice2025Form5_03
{
    /** @var string */
    protected $xsdFile = 'upd_2023-1115131_5_03.xsd';


    protected function getDocumentTitle($isShort = false)
    {
        if ($this->invoice->type_id == Invoice::TYPE_GOOD) {
            return 'ТОРГ12';
        }

        $text = "Документ об отгрузке товаров (выполнении работ), передаче имущественных прав (документ об оказании услуг)";
        if (!$isShort) {
            return $text;
        }

        if ($this->invoice->date >= '2026-01-31') {
            return "УПД";
        } else {
            return $text;
        }
    }

    /**
     * Создает свойство Файл.Документ
     *
     * @param \DOMDocument $dom
     * @return \DOMElement
     */
    protected function createElementDocument(\DOMDocument $dom)
    {
        /*
        <Документ
            ВремИнфПр="08.50.38"
            ДатаИнфПр="10.06.2024"
            КНД="1115131"
            НаимЭконСубСост="ООО "Наша компания""

            НаимДокОпр="Документ об отгрузке товаров (выполнении работ), передаче имущественных прав (документ об оказании услуг)"
            ПоФактХЖ="Документ об отгрузке товаров (выполнении работ), передаче имущественных прав (документ об оказании услуг)"
            Функция="ДОП"
        >
        */

        $elDoc = parent::createElementDocument($dom);
        $elDoc->setAttribute('НаимДокОпр', $this->getDocumentTitle());
        $elDoc->setAttribute('ПоФактХЖ', $this->getDocumentTitle());
        $elDoc->setAttribute('Функция', 'СЧФДОП');

        return $elDoc;
    }

    protected function addPaymentDocuments(\DOMDocument $dom, \DOMElement $elInvoiceInfo)
    {
        foreach ($this->invoice->getMatchedPayments() as $payment) {
            $elPayment = $dom->createElement('СвПРД');
            $elPayment->setAttribute('НомерПРД', $payment->getEffectivePaymentNo());
            $elPayment->setAttribute('ДатаПРД', (new \DateTime($payment->payment_date))->format('d.m.Y'));
            $elPayment->setAttribute('СуммаПРД', $this->formatNumber($payment->sum));
            $elInvoiceInfo->appendChild($elPayment);
        }
    }

    protected function getFileDocumentContentsOfTheEconomicFact(\DOMDocument $dom)
    {
        $elPass = null;

        $billDateTime = new \DateTime($this->bill->date);
        $contract = $this->client->contract->getContractInfo($billDateTime);
        if (!$contract) {
            return $elPass;
        }


        // Файл.Документ.СвПродПер
        $elPass = $dom->createElement('СвПродПер');
        $elPassInfo = $dom->createElement('СвПер');
        $elPassInfo->setAttribute('СодОпер', 'Реализация');
        $elPass->appendChild($elPassInfo);

        $elPassInfoMain = $dom->createElement('ОснПер');

        if ($this->invoice->date >= '2026-01-31') {
            // по счету
            $billDateTime = new \DateTime($this->bill->bill_date, new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_DEFAULT));
            $billDate = $billDateTime->format('d.m.Y');

            $elPassInfoMain->setAttribute('РеквДатаДок', $billDate);
            $elPassInfoMain->setAttribute('РеквНомерДок', $this->bill->bill_no);
            $elPassInfoMain->setAttribute('РеквНаимДок', sprintf('Счет №%s от %s', $this->bill->bill_no, $billDate));
        } else {
            // по договору
            $contractDateTime = new \DateTime($contract->contract_date, new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_DEFAULT));
            $contractDate = $contractDateTime->format('d.m.Y');

            $elPassInfoMain->setAttribute('РеквДатаДок', $contractDate);
            $elPassInfoMain->setAttribute('РеквНомерДок', $contract->contract_no);
            $elPassInfoMain->setAttribute('РеквНаимДок', sprintf('%s от %s', $contract->contract_no, $contractDate));
        }

        $elPassInfo->appendChild($elPassInfoMain);

        $firstDayOfMonth = (clone $this->invoiceDate)->modify('first day of this month')->setTime(0,0,0);
        $lastDayOfMonth = (clone $this->invoiceDate)->setTime(0,0,0);

        $filterCb = function($dateStr) use ($firstDayOfMonth, $lastDayOfMonth) {
            if (!$dateStr) {
                return null;
            }
            $date = new \DateTimeImmutable($dateStr);

            if (!$date || $date > $lastDayOfMonth || $date < $firstDayOfMonth) {
                return null;
            }

            return $date;
        };

        $minDateFrom = min(array_filter(array_map($filterCb, array_map(fn($l) => $l['date_from'], $this->invoice->lines)))) ?: $firstDayOfMonth;
        $maxDateFrom = max(array_filter(array_map($filterCb, array_map(fn($l) => $l['date_to'], $this->invoice->lines)))) ?: $lastDayOfMonth;

        $elPassInfo->setAttribute('ДатаПер', $this->invoiceDate->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED));
        $elPassInfo->setAttribute('ДатаНачПер', $minDateFrom->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED));
        $elPassInfo->setAttribute('ДатаОконПер', $maxDateFrom->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED));

        return $elPass;
    }
}