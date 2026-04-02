<?php

namespace app\modules\sbisTenzor\classes\XmlGenerator;

class Invoice2019Form5_01 extends Invoice2016Form5_02
{
    const KND_CODE = 1115131;

    /** @var string */
    protected $softName = 'MCN-Stat';
    /** @var string */
    protected $formVersion = '5.01';
    /** @var int */
    protected $kndCode = self::KND_CODE;
    /** @var string */
    protected $fileIdPattern = 'ON_NSCHFDOPPR_{A}_{O}_{GGGGMMDD}_{N}';
    /** @var string */
    protected $xsdFile = 'invoice_2019-1115131_5_01.xsd';

    /**
     * Информация о Покупателе - ФЛ
     *
     * @param \DOMElement $elInfoBuyerId
     */
    protected function addBuyerInfoPerson(\DOMElement $elInfoBuyerId)
    {
        $dom = $elInfoBuyerId->ownerDocument;

        $elInfoBuyerIdType = $dom->createElement('СвФЛУчастФХЖ');
        if ($this->client->contragent->inn) {
            $elInfoBuyerIdType->setAttribute('ИННФЛ', $this->client->contragent->inn);
        }
        $elInfoBuyerId->appendChild($elInfoBuyerIdType);

        $initials = $this->getInitials($this->client->contragent->name_full);
        $elInfoBuyerIdTypeData = $this->createFioElement(
            $dom,
            ($this->client->contragent->person->last_name ? : $initials[0]),
            ($this->client->contragent->person->first_name ? : $initials[1]),
            ($this->client->contragent->person->middle_name ? : $initials[2])
        );
        $elInfoBuyerIdType->appendChild($elInfoBuyerIdTypeData);
    }

    /**
     * Добавить таблицу товаров Файл.Документ.ТаблСчФакт
     *
     * @param \DOMDocument $dom
     * @return \DOMElement
     */
    protected function addElementItemsTable(\DOMDocument $dom)
    {
        $elInvoiceTable = $dom->createElement('ТаблСчФакт');

        $index = 1;
        $maxVatRate = 0;
        foreach ($this->invoice->lines as $line) {
            $elLine = $dom->createElement('СведТов');
            $elLine->setAttribute('НомСтр', $index++);
            $elLine->setAttribute('НалСт', $line->getVat_rate() ? $line->getVat_rate() . '%' : 'без НДС');// optional
            $elLine->setAttribute('НаимТов', $this->prepareText($line->getFullName()));
            $elLine->setAttribute('СтТовУчНал', $this->formatNumber($line->getPrice_with_vat()));
            // http://www.classbase.ru/okei
            // 796 - штука
            //$elLine->setAttribute('ОКЕИ_Тов', 796);// choice1 required
            $elLine->setAttribute('ДефОКЕИ_Тов', '-');// choice1 required
            $elLine->setAttribute('КолТов', $this->formatNumber($line->getAmount(), 3));// optional
            $elLine->setAttribute('СтТовБезНДС', $this->formatNumber($line->getPrice_without_vat()));// optional
            $elLine->setAttribute('ЦенаТов', $this->formatNumber($line->price));// optional
            $elInvoiceTable->appendChild($elLine);// optional

            $elLineExcise = $dom->createElement('Акциз');
            $elLine->appendChild($elLineExcise);
            $excise = $dom->createElement('БезАкциз', $this->formatText('без акциза'));
            $elLineExcise->appendChild($excise);

            $elLineTaxSum = $dom->createElement('СумНал');
            $this->addTaxSumElement($elLineTaxSum, $line->getVat_rate(), $line->getVat());
            $elLine->appendChild($elLineTaxSum);

            $maxVatRate = max($maxVatRate, $line->getVat_rate());
        }

        $elTotal = $dom->createElement('ВсегоОпл');// required
        $elTotal->setAttribute('СтТовБезНДСВсего', $this->formatNumber($this->invoice->sum_without_tax));// optional
        $elTotal->setAttribute('СтТовУчНалВсего', $this->formatNumber($this->invoice->sum));
        //$elTotal->setAttribute('ДефСтТовУчНалВсего', '-');
        $elInvoiceTable->appendChild($elTotal);

        $elTotalSumTotal = $dom->createElement('СумНалВсего');// required
        $this->addTaxSumElement($elTotalSumTotal, $maxVatRate, $this->invoice->sum_tax);
        $elTotal->appendChild($elTotalSumTotal);

        return $elInvoiceTable;
    }
}
