<?php

namespace app\modules\sbisTenzor\classes\XmlGenerator;

use app\helpers\DateTimeZoneHelper;
use app\models\Invoice;
use app\modules\uu\models_light\InvoiceBillLight;

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
        $elDoc->setAttribute('НаимДокОпр', $this->getDocumentTitle(true));
        $elDoc->setAttribute('ПоФактХЖ', $this->getDocumentTitle());
        $elDoc->setAttribute('Функция', 'СЧФДОП');

        return $elDoc;
    }

    protected function addPaymentDocuments(\DOMDocument $dom, \DOMElement $elInvoiceInfo)
    {
        if ($this->invoice->is_hide_payment_number) {
            return;
        }

        foreach ($this->invoice->getMatchedPayments() as $payment) {
            $elPayment = $dom->createElement('СвПРД');
            $elPayment->setAttribute('НомерПРД', $payment->getEffectivePaymentNo());
            $elPayment->setAttribute('ДатаПРД', (new \DateTime($payment->getEffectivePaymentDate()))->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED));
            $elPayment->setAttribute('СуммаПРД', $this->formatNumber($payment->sum));
            $elInvoiceInfo->appendChild($elPayment);
        }
    }

    protected function getFileDocumentContentsOfTheEconomicFact(\DOMDocument $dom)
    {
        $reasonForTransfer = InvoiceBillLight::reasonForTransferUpd($this->client, $this->bill);


        // Файл.Документ.СвПродПер
        $elPass = $dom->createElement('СвПродПер');
        $elPassInfo = $dom->createElement('СвПер');
        $elPassInfo->setAttribute('СодОпер', 'Реализация');
        $elPass->appendChild($elPassInfo);

        $elPassInfoMain = $dom->createElement('ОснПер');
        $elPassInfoMain->setAttribute('РеквДатаДок', $reasonForTransfer['date_human']);
        $elPassInfoMain->setAttribute('РеквНомерДок', $reasonForTransfer['number']);
        $elPassInfoMain->setAttribute('РеквНаимДок', $reasonForTransfer['name']);

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
