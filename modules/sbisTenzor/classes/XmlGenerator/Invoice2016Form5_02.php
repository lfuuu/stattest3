<?php

namespace app\modules\sbisTenzor\classes\XmlGenerator;

use ActiveRecord\DateTime;
use app\helpers\DateTimeZoneHelper;
use app\models\ClientContragent;
use app\models\Invoice;
use app\modules\sbisTenzor\classes\XmlGenerator;

class Invoice2016Form5_02 extends XmlGenerator
{
    const KND_CODE = 1115125;

    /** @var string */
    protected $formVersion = '5.02';
    /** @var int */
    protected $kndCode = self::KND_CODE;
    /** @var string */
    protected $fileIdPattern = 'ON_SCHFDOPPR_{A}_{O}_{GGGGMMDD}_{N}';
    /** @var string */
    protected $signerBaseAttribute = 'ОснПолн';
    /** @var string */
    protected $xsdFile = 'invoice_2016-1115125_5_02.xsd';

    /**
     * Создает свойство Документ
     *
     * @param \DOMDocument $dom
     * @return \DOMElement
     */
    protected function createElementDocument(\DOMDocument $dom)
    {
        $elDoc = parent::createElementDocument($dom);
        $elDoc->setAttribute('Функция', 'СЧФ');

        return $elDoc;
    }

    /**
     * Заполняет свойство Файл.Документ
     *
     * @param \DOMElement $elDoc
     * @throws \Exception
     */
    protected function fillElementDocument(\DOMElement $elDoc)
    {
        $dom = $elDoc->ownerDocument;
        // --------------------------------------------------------------------------------------------------------------
        // Файл.Документ.СвСчФакт
        $elInvoiceInfo = $dom->createElement('СвСчФакт');
        $elInvoiceInfo->setAttribute('ДатаСчФ', $this->invoiceInitialDate->format('d.m.Y'));
        $elInvoiceInfo->setAttribute('КодОКВ', $this->bill->currencyModel->code);
        $elInvoiceInfo->setAttribute('НомерСчФ', $this->invoice->number);

        // Исправления (либо нет либо есть):
        // <ИспрСчФ ДефНомИспрСчФ="-" ДефДатаИспрСчФ="-"/>
        // либо
        // <ИспрСчФ НомИспрСчФ="1" ДатаИспрСчФ="01.01.2020"/>
        if ($this->invoice->correction_idx >= 1) {
            $elInfoCorrection = $dom->createElement('ИспрСчФ');
            $elInfoCorrection->setAttribute('НомИспрСчФ', $this->invoice->correction_idx);
            $elInfoCorrection->setAttribute('ДатаИспрСчФ', $this->invoiceDate->format('d.m.Y'));
            $elInvoiceInfo->appendChild($elInfoCorrection);
        } else {
            $elInfoCorrection = $dom->createElement('ИспрСчФ');
            $elInfoCorrection->setAttribute('ДефНомИспрСчФ', '-');
            $elInfoCorrection->setAttribute('ДефДатаИспрСчФ', '-');

            // при отсутствии исправлений не добавляем
            //$elInvoiceInfo->appendChild($elInfoCorrection);
        }

        $elInfoSeller = $dom->createElement('СвПрод');
        $elInvoiceInfo->appendChild($elInfoSeller);

        $elInfoSellerId = $dom->createElement('ИдСв');
        $elInfoSeller->appendChild($elInfoSellerId);

        $elInfoSellerIdData = $dom->createElement('СвЮЛУч');
        $elInfoSellerIdData->setAttribute('ИННЮЛ', $this->organizationFrom->tax_registration_id);
        if ($this->kndCode == Invoice2019Form5_01::KND_CODE) {
            //$elInfoSellerIdData->setAttribute('ДефИННЮЛ', '-');
        }
        $elInfoSellerIdData->setAttribute('КПП', $this->organizationFrom->tax_registration_reason);
        $elInfoSellerIdData->setAttribute('НаимОрг', $this->prepareText($this->organizationFrom->full_name));
        $elInfoSellerId->appendChild($elInfoSellerIdData);

        $elInfoSellerAddress = $dom->createElement('Адрес');
        $elInfoSeller->appendChild($elInfoSellerAddress);

        $elInfoSellerAddressData = $dom->createElement('АдрИнф');
        $elInfoSellerAddressData->setAttribute('АдрТекст', $this->organizationFrom->legal_address);
        $elInfoSellerAddressData->setAttribute('КодСтр', $this->organizationFrom->country_id);
        $elInfoSellerAddress->appendChild($elInfoSellerAddressData);

        // payments
        if (
            $this->invoice->type_id == Invoice::TYPE_1
            || $this->invoice->type_id == Invoice::TYPE_GOOD
            || ($this->invoice->type_id == Invoice::TYPE_2 && $this->bill->inv2to1)
        ) {
            foreach ($this->bill->getInvoicePayments() as $payment) {
                $elPayment = $dom->createElement('СвПРД');
                $elPayment->setAttribute('ДатаПРД', (new \DateTime())->setTimestamp($payment['payment_date_ts'])->format('d.m.Y'));
                $elPayment->setAttribute('НомерПРД', $payment['payment_no']);
                $elInvoiceInfo->appendChild($elPayment);
            }
        }

        $elInfoBuyer = $dom->createElement('СвПокуп');
        $elInvoiceInfo->appendChild($elInfoBuyer);

        $elInfoBuyerId = $dom->createElement('ИдСв');
        $elInfoBuyer->appendChild($elInfoBuyerId);

        $this->addBuyerInfo($elInfoBuyerId);

        $elInfoBuyerAddress = $dom->createElement('Адрес');
        $elInfoBuyer->appendChild($elInfoBuyerAddress);

        $elInfoBuyerAddressData = $dom->createElement('АдрИнф');
        $elInfoBuyerAddressData->setAttribute('АдрТекст', $this->client->address);
        $elInfoBuyerAddressData->setAttribute('КодСтр', $this->client->contragent->country_id);
        $elInfoBuyerAddress->appendChild($elInfoBuyerAddressData);

        $clientContacts = $this->client->getOfficialContact();
        $phone = array_shift($clientContacts['phone']);
        if ($phone) {
            $elInfoBuyerContact = $dom->createElement('Контакт');
            $elInfoBuyerContact->setAttribute('Тлф', $phone);
            $elInfoBuyer->appendChild($elInfoBuyerContact);
        }

        // --------------------------------------------------------------------------------------------------------------
        // Файл.Документ.ТаблСчФакт
        $elInvoiceTable = $this->addElementItemsTable($dom);

        // --------------------------------------------------------------------------------------------------------------
        $elPass = null;

        $billDateTime = new \DateTime($this->bill->date);
        if ($contract = $this->client->contract->getContractInfo($billDateTime)) {
            $contractDateTime = new \DateTime($contract->contract_date, new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_DEFAULT));
            $contractDate = $contractDateTime->format('d.m.Y');

            // Файл.Документ.СвПродПер
            $elPass = $dom->createElement('СвПродПер');
            $elPassInfo = $dom->createElement('СвПер');
            $elPassInfo->setAttribute('СодОпер', 'Реализация');
            $elPass->appendChild($elPassInfo);

            $elPassInfoMain = $dom->createElement('ОснПер');
            $elPassInfoMain->setAttribute('ДатаОсн', $contractDate);
            $elPassInfoMain->setAttribute('НаимОсн', sprintf('%s от %s', $contract->contract_no, $contractDate));
            $elPassInfoMain->setAttribute('НомОсн', $contract->contract_no);
            $elPassInfo->appendChild($elPassInfoMain);
        }

        // format 01.10.2024 (5a)
        // <ДокПодтвОтгр НаимДокОтгр="АКТ № || ТОРГ12 №" НомДокОтгр="240621-ARG168" ДатаДокОтгр="24.07.2021"/>
        if ($this->invoiceDate >= (new \DateTime('2024-10-01 00:00:00'))) {
            $elDocShip = $dom->createElement('ДокПодтвОтгр');
            $elDocShip->setAttribute('НаимДокОтгр', ($this->invoice->type_id == Invoice::TYPE_GOOD ? 'ТОРГ12' : 'АКТ'));
            $elDocShip->setAttribute('НомДокОтгр', $this->invoice->number);
            $elDocShip->setAttribute('ДатаДокОтгр', $this->invoiceDate->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED));
            $elInvoiceInfo->appendChild($elDocShip);
        }
        // format 01.07.2021 (5a)
        // <ДокПодтвОтгр НаимДокОтгр="п/п 1-2 УПД" НомДокОтгр="240621-ARG168" ДатаДокОтгр="24.07.2021"/>
        else if ($this->invoiceDate >= (new \DateTime('2021-07-01 00:00:00'))) {
            $elDocShip = $dom->createElement('ДокПодтвОтгр');
            $elDocShip->setAttribute('НаимДокОтгр', 'п/п 1-' . (count($this->invoice->lines)));
            $elDocShip->setAttribute('НомДокОтгр', $this->invoice->number);
            $elDocShip->setAttribute('ДатаДокОтгр', $this->invoiceDate->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED));
            $elInvoiceInfo->appendChild($elDocShip);
        }

        // add
        $elDoc->appendChild($elInvoiceInfo);
        $elDoc->appendChild($elInvoiceTable);
        if ($elPass) {
            $elDoc->appendChild($elPass);
        }
    }

    /**
     * Информация о Покупателе
     *
     * @param \DOMElement $elInfoBuyerId
     * @throws \Exception
     */
    protected function addBuyerInfo(\DOMElement $elInfoBuyerId)
    {
        switch ($this->client->contragent->legal_type) {
            case ClientContragent::LEGAL_TYPE:
                $this->addBuyerInfoLegal($elInfoBuyerId);
                break;

            case ClientContragent::IP_TYPE:
                $this->addBuyerInfoIp($elInfoBuyerId);
                break;

            case ClientContragent::PERSON_TYPE:
                // TODO: может ли вообще использоваться?
                $this->addBuyerInfoPerson($elInfoBuyerId);
                break;
        }
    }

    /**
     * Информация о Покупателе - Организации
     *
     * @param \DOMElement $elInfoBuyerId
     */
    protected function addBuyerInfoLegal(\DOMElement $elInfoBuyerId)
    {
        $dom = $elInfoBuyerId->ownerDocument;

        $elInfoBuyerIdData = $dom->createElement('СвЮЛУч');
        $elInfoBuyerIdData->setAttribute('ИННЮЛ', $this->buyer->tax_registration_id);
        if ($this->kndCode == Invoice2019Form5_01::KND_CODE) {
            //$elInfoBuyerIdData->setAttribute('ДефИННЮЛ', '-');
        }
        $elInfoBuyerIdData->setAttribute('КПП', $this->buyer->tax_registration_reason);
        $elInfoBuyerIdData->setAttribute('НаимОрг', $this->prepareText($this->buyer->name));
        $elInfoBuyerId->appendChild($elInfoBuyerIdData);
    }

    /**
     * Информация о Покупателе - ИП
     *
     * @param \DOMElement $elInfoBuyerId
     */
    protected function addBuyerInfoIp(\DOMElement $elInfoBuyerId)
    {
        $dom = $elInfoBuyerId->ownerDocument;

        $elInfoBuyerIdType = $dom->createElement('СвИП');
        $elInfoBuyerIdType->setAttribute('ИННФЛ', $this->client->contragent->inn);
        if ($this->kndCode == Invoice2019Form5_01::KND_CODE) {
            //$elInfoBuyerIdType->setAttribute('ДефИННФЛ', '-');
        }
        $elInfoBuyerId->appendChild($elInfoBuyerIdType);

        $initials = $this->getInitials($this->client->contragent->name_full);
        $elInfoBuyerIdTypeData = $this->createFioElement($dom, $initials[0], $initials[1], $initials[2]);
        $elInfoBuyerIdType->appendChild($elInfoBuyerIdTypeData);
    }

    /**
     * Информация о Покупателе - ФЛ
     *
     * @param \DOMElement $elInfoBuyerId
     * @throws \Exception
     */
    protected function addBuyerInfoPerson(\DOMElement $elInfoBuyerId)
    {
        throw new \Exception(
            sprintf('Данный документ не предназначен для работы с физическими лицами!')
        );
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
            $elLine->setAttribute('НалСт', $line->getVat_rate() ? $line->getVat_rate() . '%' : 'без НДС');
            $elLine->setAttribute('НаимТов', $this->prepareText($line->getFullName()));// optional
            $elLine->setAttribute('СтТовУчНал', $this->formatNumber($line->getPrice_with_vat()));// optional
            // http://www.classbase.ru/okei
            // 796 - штука
            //$elLine->setAttribute('ОКЕИ_Тов', 796); // choice1
            $elLine->setAttribute('КолТов', $this->formatNumber($line->getAmount(), 3));// optional
            $elLine->setAttribute('СтТовБезНДС', $this->formatNumber($line->getPrice_without_vat()));// optional
            $elLine->setAttribute('ЦенаТов', $this->formatNumber($line->price));// optional
            $elInvoiceTable->appendChild($elLine);

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
        $elInvoiceTable->appendChild($elTotal);

        $elTotalSumTotal = $dom->createElement('СумНалВсего');// required
        $this->addTaxSumElement($elTotalSumTotal, $maxVatRate, $this->invoice->sum_tax);
        $elTotal->appendChild($elTotalSumTotal);

        return $elInvoiceTable;
    }

    /**
     * Добавить свойство СумНДСТип
     *
     * @param \DOMElement $elTaxSum
     * @param int $vatRate
     * @param float $vatSum
     * @return \DOMElement
     */
    protected function addTaxSumElement(\DOMElement $elTaxSum, $vatRate, $vatSum)
    {
        $dom = $elTaxSum->ownerDocument;

        if ($vatRate) {
            $taxSum = $dom->createElement('СумНал', $this->formatNumber($vatSum));
        } else {
            $taxSum = $dom->createElement('БезНДС', $this->formatText('без НДС'));
        }
        $elTaxSum->appendChild($taxSum);

        return $elTaxSum;
    }
}
