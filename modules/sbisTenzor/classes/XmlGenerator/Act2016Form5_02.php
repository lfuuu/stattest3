<?php

namespace app\modules\sbisTenzor\classes\XmlGenerator;

use app\models\ClientContragent;
use app\models\Currency;
use app\modules\sbisTenzor\classes\XmlGenerator;
use app\modules\uu\models_light\InvoiceBillLight;

class Act2016Form5_02 extends XmlGenerator
{
    /** @var string */
    protected $formVersion = '5.02';
    /** @var int */
    protected $kndCode = 1175012;
    /** @var string */
    protected $fileIdPattern = 'DP_REZRUISP_{A}_{O}_{GGGGMMDD}_{N}';
    /** @var string */
    protected $signerBaseAttribute = 'ОснПолнПодп';
    /** @var string */
    protected $xsdFile = 'act_2016-1175012_5_02.xsd';

    /**
     * Создает свойство Файл.Документ
     *
     * @param \DOMDocument $dom
     * @return \DOMElement
     */
    protected function createElementDocument(\DOMDocument $dom)
    {
        $elDoc = $dom->createElement('Документ');
        $elDoc->setAttribute('ВремИнфИсп', $this->now->format('H.i.s'));
        $elDoc->setAttribute('ДатаИнфИсп', $this->now->format('d.m.Y'));
        $elDoc->setAttribute('КНД', $this->kndCode);
        $elDoc->setAttribute('НаимЭконСубСост', $this->prepareText($this->organizationFrom->full_name));

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
        // Файл.Документ.СвДокПРУ
        $elInfo = $dom->createElement('СвДокПРУ');

        $elInfoName = $dom->createElement('НаимДок');
        $elInfoName->setAttribute('НаимДокОпр', 'Акт о передаче результатов работ (Акт об оказании услуг)');
        $elInfoName->setAttribute('ПоФактХЖ', 'Документ о передаче результатов работ (Документ об оказании услуг)');
        $elInfo->appendChild($elInfoName);

        $elInfoId = $dom->createElement('ИдентДок');
        $elInfoId->setAttribute('ДатаДокПРУ', $this->invoiceInitialDate->format('d.m.Y'));
        $elInfoId->setAttribute('НомДокПРУ', $this->invoice->number);
        $elInfo->appendChild($elInfoId);

        if ($this->invoice->correction_idx >= 1) {
            $elInfoCorrection = $dom->createElement('ИспрДокПРУ');
            $elInfoCorrection->setAttribute('НомИспрДокПРУ', $this->invoice->correction_idx);
            $elInfoCorrection->setAttribute('ДатаИспрДокПРУ', $this->invoiceDate->format('d.m.Y'));
            $elInfo->appendChild($elInfoCorrection);
        }

        $elInfoMoney = $dom->createElement('ДенИзм');
        $elInfoMoney->setAttribute('КодОКВ', $this->bill->currencyModel->code);
        $elInfoMoney->setAttribute('НаимОКВ', $this->bill->currencyModel->name);
        $elInfo->appendChild($elInfoMoney);

        $elInfoContent = $dom->createElement('СодФХЖ1');
        $elInfo->appendChild($elInfoContent);

        // ---------------- seller
        $elInfoContentSeller = $dom->createElement('Исполнитель');
        $elInfoContent->appendChild($elInfoContentSeller);

        $elInfoContentSellerId = $dom->createElement('ИдСв');
        $elInfoContentSeller->appendChild($elInfoContentSellerId);

        $elInfoContentSellerIdType = $dom->createElement('СвОрг');
        $elInfoContentSellerId->appendChild($elInfoContentSellerIdType);

        $elInfoContentSellerIdTypeData = $dom->createElement('СвЮЛ');
        $elInfoContentSellerIdTypeData->setAttribute('ИННЮЛ', $this->organizationFrom->tax_registration_id);
        $elInfoContentSellerIdTypeData->setAttribute('КПП', $this->organizationFrom->tax_registration_reason);
        $elInfoContentSellerIdTypeData->setAttribute('НаимОрг', $this->prepareText($this->organizationFrom->full_name));
        $elInfoContentSellerIdType->appendChild($elInfoContentSellerIdTypeData);

        $elInfoContentSellerAddress = $dom->createElement('Адрес');
        $elInfoContentSeller->appendChild($elInfoContentSellerAddress);

        $elInfoContentSellerAddressData = $dom->createElement('АдрИно');
        $elInfoContentSellerAddressData->setAttribute('АдрТекст', $this->organizationFrom->legal_address);
        $elInfoContentSellerAddressData->setAttribute('КодСтр', $this->organizationFrom->country_id);
        $elInfoContentSellerAddress->appendChild($elInfoContentSellerAddressData);

        $elInfoContentSellerContact = $dom->createElement('Контакт');
        $elInfoContentSellerContact->setAttribute('Тлф', $this->organizationFrom->contact_phone);
        $elInfoContentSeller->appendChild($elInfoContentSellerContact);

        // ----------------- buyer
        $elInfoContentBuyer = $dom->createElement('Заказчик');
        $elInfoContent->appendChild($elInfoContentBuyer);

        $this->addBuyerInfo($elInfoContentBuyer);

        $elInfoContentBuyerAddress = $dom->createElement('Адрес');
        $elInfoContentBuyer->appendChild($elInfoContentBuyerAddress);

        $elInfoContentBuyerAddressData = $dom->createElement('АдрИно');
        $elInfoContentBuyerAddressData->setAttribute('АдрТекст', $this->client->address);
        $elInfoContentBuyerAddressData->setAttribute('КодСтр', $this->client->contragent->country_id);
        $elInfoContentBuyerAddress->appendChild($elInfoContentBuyerAddressData);

        $clientContacts = $this->client->getOfficialContact();
        $phone = array_shift($clientContacts['phone']);
        if ($phone) {
            $elInfoContentBuyerContact = $dom->createElement('Контакт');
            $elInfoContentBuyerContact->setAttribute('Тлф', $phone);
            $elInfoContentBuyer->appendChild($elInfoContentBuyerContact);
        }

        $reasonForTransfer = InvoiceBillLight::reasonForTransferUpd($this->client, $this->bill);

        $elInfoContentBase = $dom->createElement('Основание');
        $elInfoContentBase->setAttribute('ДатаОсн', $reasonForTransfer['date_human']);
        $elInfoContentBase->setAttribute('НаимОсн', $reasonForTransfer['name']);
        $elInfoContentBase->setAttribute('НомОсн', $reasonForTransfer['number']);
        $elInfoContent->appendChild($elInfoContentBase);

        // --------------------------------------------------------------------------------------------------------------
        // Файл.Документ.ТаблСчФакт
        $elWorkList = $dom->createElement('ОписРабот');
        $index= 1;
        $maxVatRate = 0;
        foreach ($this->invoice->lines as $line) {
            $vatRate = $line->getVat_rate();
            $elLine = $dom->createElement('Работа');
            $elLine->setAttribute('Номер', $index++);// optional
            //$elLine->setAttribute('НаимРабот', $this->prepareText($line->getFullName()));// optional1
            $elLine->setAttribute('Количество', $this->formatNumber($line->getAmount()));// optional
            $elLine->setAttribute('НалСт', $line->getVat_rate() ? $line->getVat_rate() . '%' : 'без НДС');// optional
            // http://www.classbase.ru/okei
            // 796 - штука
            //$elLine->setAttribute('ОКЕИ', 796);// optional, but required if no optional1
            $elLine->setAttribute('СтоимУчНДС', $this->formatNumber($line->getPrice_with_vat()));// optional
            $elLine->setAttribute('СтоимБезНДС', $this->formatNumber($line->getPrice_without_vat()));// optional
            $elLine->setAttribute('Цена', $this->formatNumber($line->price));// optional

            if ($vatRate) {
                $elLine->setAttribute('СумНДС', $this->formatNumber($line->getVat()));// optional
            }
            
            $elWorkList->appendChild($elLine);

            $elLineDesc = $dom->createElement('Описание', $this->formatText($line->getFullName()));// optional, but required if no optional1
            $elLine->appendChild($elLineDesc);

            $maxVatRate = max ($maxVatRate, $vatRate);
        }
        $elWorkList->setAttribute('СтУчНДСИт', $this->formatNumber($this->invoice->sum));
        if ($maxVatRate) {
            $elWorkList->setAttribute('СтБезНДСИт', $this->formatNumber($this->invoice->sum_without_tax));// optional
            $elWorkList->setAttribute('СумНДСИт', $this->formatNumber($this->invoice->sum_tax));// optional
        }
        $elInfoContent->appendChild($elWorkList);

        if ($this->bill->currency !== Currency::RUB) {
            // в иностранной валюте сумма прописью в интерфейсе СБИС неверна, поэтому выбираем визуализацию 1С
            $elInfoContentFacts = $dom->createElement('ИнфПолФХЖ1');
            $elInfoContent->appendChild($elInfoContentFacts);

            $elInfoContentFactsText = $dom->createElement('ТекстИнф');
            $elInfoContentFactsText->setAttribute('Значен', '1С');
            $elInfoContentFactsText->setAttribute('Идентиф', 'ИдВизуализации');
            $elInfoContentFacts->appendChild($elInfoContentFactsText);
        }

        // --------------------------------------------------------------------------------------------------------------
        // Файл.Документ.СодФХЖ2
        $elFacts = $dom->createElement('СодФХЖ2');
        $elFacts->setAttribute('СодОпер', 'Результаты работ переданы (услуги оказаны)');

        // add
        $elDoc->appendChild($elInfo);
        $elDoc->appendChild($elFacts);
    }

    /**
     * Информация о Заказчике
     *
     * @param \DOMElement $elInfoContentBuyer
     */
    protected function addBuyerInfo(\DOMElement $elInfoContentBuyer)
    {
        switch ($this->client->contragent->legal_type) {
            case ClientContragent::LEGAL_TYPE:
                $this->addBuyerInfoLegal($elInfoContentBuyer);
                break;

            case ClientContragent::IP_TYPE:
                $this->addBuyerInfoIp($elInfoContentBuyer);
                break;

            case ClientContragent::PERSON_TYPE:
                $this->addBuyerInfoPerson($elInfoContentBuyer);
                break;
        }
    }

    /**
     * Информация о Заказчике - Организации
     *
     * @param \DOMElement $elInfoContentBuyer
     */
    protected function addBuyerInfoLegal(\DOMElement $elInfoContentBuyer)
    {
        $dom = $elInfoContentBuyer->ownerDocument;

        $elInfoContentBuyerId = $dom->createElement('ИдСв');
        $elInfoContentBuyer->appendChild($elInfoContentBuyerId);

        $elInfoContentBuyerIdType = $dom->createElement('СвОрг');
        $elInfoContentBuyerId->appendChild($elInfoContentBuyerIdType);

        $elInfoContentBuyerIdTypeData = $dom->createElement('СвЮЛ');
        $elInfoContentBuyerIdTypeData->setAttribute('ИННЮЛ', $this->buyer->tax_registration_id);
        $elInfoContentBuyerIdTypeData->setAttribute('КПП', $this->buyer->tax_registration_reason);
        $elInfoContentBuyerIdTypeData->setAttribute('НаимОрг', $this->prepareText($this->buyer->name));
        $elInfoContentBuyerIdType->appendChild($elInfoContentBuyerIdTypeData);
    }

    /**
     * Информация о Заказчике - ИП
     *
     * @param \DOMElement $elInfoContentBuyer
     */
    protected function addBuyerInfoIp(\DOMElement $elInfoContentBuyer)
    {
        $dom = $elInfoContentBuyer->ownerDocument;

        $elInfoContentBuyerId = $dom->createElement('ИдСв');
        $elInfoContentBuyer->appendChild($elInfoContentBuyerId);

        $elInfoContentBuyerIdType = $dom->createElement('СвИП');
        $elInfoContentBuyerIdType->setAttribute('ИННФЛ', $this->client->contragent->inn);
        $elInfoContentBuyerId->appendChild($elInfoContentBuyerIdType);

        $initials = $this->getInitials($this->client->contragent->name_full);
        $elInfoContentBuyerIdTypeData = $this->createFioElement($dom, $initials[0], $initials[1], $initials[2]);
        $elInfoContentBuyerIdType->appendChild($elInfoContentBuyerIdTypeData);
    }

    /**
     * Информация о Заказчике - ФЛ
     *
     * @param \DOMElement $elInfoContentBuyer
     */
    protected function addBuyerInfoPerson(\DOMElement $elInfoContentBuyer)
    {
        $dom = $elInfoContentBuyer->ownerDocument;

        $elInfoContentBuyerId = $dom->createElement('ИдСв');
        $elInfoContentBuyer->appendChild($elInfoContentBuyerId);

        $elInfoContentBuyerIdType = $dom->createElement('СвФЛ');
        if ($this->client->contragent->inn) {
            $elInfoContentBuyerIdType->setAttribute('ИННФЛ', $this->client->contragent->inn);
        }
        $elInfoContentBuyerId->appendChild($elInfoContentBuyerIdType);

        $initials = $this->getInitials($this->client->contragent->name_full);
        $elInfoContentBuyerIdTypeData = $this->createFioElement(
            $dom,
            ($this->client->contragent->person->last_name ? : $initials[0]),
            ($this->client->contragent->person->first_name ? : $initials[1]),
            ($this->client->contragent->person->middle_name ? : $initials[2])
        );
        $elInfoContentBuyerIdType->appendChild($elInfoContentBuyerIdTypeData);
    }
}
