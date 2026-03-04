<?php

namespace app\modules\sbisTenzor\classes\XmlGenerator;

use app\helpers\DateTimeZoneHelper;
use app\models\Invoice;
use app\modules\uu\models_light\InvoiceBillLight;

class Invoice2025Form5_03 extends Invoice2016Form5_02
{
    const KND_CODE = 1115131;

    /** @var string */
    protected $softName = 'MCN-Stat';
    /** @var string */
    protected $formVersion = '5.03';
    /** @var int */
    protected $kndCode = self::KND_CODE;
    /** @var string */
    protected $fileIdPattern = 'ON_NSCHFDOPPR_{A}_{O}_{GGGGMMDD}_{N}_0_0_0_0_0_00';
    /** @var string */
    protected $xsdFile = 'invoice_2025-1115131_5_03.xsd';

    public $isInformationAboutParticipants = false;


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
        $elInvoiceInfo->setAttribute('ДатаДок', $this->invoiceInitialDate->format('d.m.Y'));
//        $elInvoiceInfo->setAttribute('КодОКВ', $this->bill->currencyModel->code);
        $elInvoiceInfo->setAttribute('НомерДок', $this->invoice->number);

        // Исправления (либо нет либо есть):
        // <ИспрСчФ ДефНомИспрСчФ="-" ДефДатаИспрСчФ="-"/>
        // либо
        // <ИспрСчФ НомИспрСчФ="1" ДатаИспрСчФ="01.01.2020"/>
        if ($this->invoice->correction_idx >= 1) {
            $elInfoCorrection = $dom->createElement('ИспрДок');
            $elInfoCorrection->setAttribute('НомИспр', $this->invoice->correction_idx);
            $elInfoCorrection->setAttribute('ДатаИспр', $this->invoiceDate->format('d.m.Y'));
            $elInvoiceInfo->appendChild($elInfoCorrection);

            // при отсутствии исправлений не добавляем
            //$elInvoiceInfo->appendChild($elInfoCorrection);
        }

        $elInfoSeller = $dom->createElement('СвПрод');
        $elInvoiceInfo->appendChild($elInfoSeller);

        $elInfoSellerId = $dom->createElement('ИдСв');
        $elInfoSeller->appendChild($elInfoSellerId);

        $elInfoSellerIdData = $dom->createElement('СвЮЛУч');
        $elInfoSellerIdData->setAttribute('ИННЮЛ', $this->organizationFrom->tax_registration_id);
        $elInfoSellerIdData->setAttribute('КПП', $this->organizationFrom->tax_registration_reason);
        $elInfoSellerIdData->setAttribute('НаимОрг', $this->prepareText($this->organizationFrom->full_name));
        $elInfoSellerId->appendChild($elInfoSellerIdData);

        $elInfoSellerAddress = $dom->createElement('Адрес');
        $elInfoSeller->appendChild($elInfoSellerAddress);

        $elInfoSellerAddressData = $dom->createElement('АдрИнф');
        $elInfoSellerAddressData->setAttribute('КодСтр', $this->organizationFrom->country_id);
        $elInfoSellerAddressData->setAttribute('НаимСтран', $this->organizationFrom->country->name);
        $elInfoSellerAddressData->setAttribute('АдрТекст', $this->organizationFrom->legal_address);
        $elInfoSellerAddress->appendChild($elInfoSellerAddressData);

        // payments
        $this->addPaymentDocuments($dom, $elInvoiceInfo);


        // format 01.10.2024 (5a)
        // <ДокПодтвОтгр НаимДокОтгр="АКТ № || ТОРГ12 №" НомДокОтгр="240621-ARG168" ДатаДокОтгр="24.07.2021"/>
        if ($this->invoiceDate >= (new \DateTime('2024-10-01 00:00:00'))) {
            $elDocShip = $dom->createElement('ДокПодтвОтгрНом');
            $elDocShip->setAttribute('РеквНаимДок', $this->getDocumentTitle(true));
            $elDocShip->setAttribute('РеквНомерДок', $this->invoice->number);
            $elDocShip->setAttribute('РеквДатаДок', $this->invoiceDate->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED));
            $elInvoiceInfo->appendChild($elDocShip);
        }
        // format 01.07.2021 (5a)
        // <ДокПодтвОтгр НаимДокОтгр="п/п 1-2 УПД" НомДокОтгр="240621-ARG168" ДатаДокОтгр="24.07.2021"/>
        else if ($this->invoiceDate >= (new \DateTime('2021-07-01 00:00:00'))) {
            $elDocShip = $dom->createElement('ДокПодтвОтгр');
            $elDocShip->setAttribute('РеквНаимДок', 'п/п 1-' . (count($this->invoice->lines)));
            $elDocShip->setAttribute('РеквНомерДок', $this->invoice->number);
            $elDocShip->setAttribute('РеквДатаДок', $this->invoiceDate->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED));
            $elInvoiceInfo->appendChild($elDocShip);
        }

        $elInfoBuyer = $dom->createElement('СвПокуп');
        $elInvoiceInfo->appendChild($elInfoBuyer);

        $elInfoBuyerId = $dom->createElement('ИдСв');
        $elInfoBuyer->appendChild($elInfoBuyerId);

        $this->addBuyerInfo($elInfoBuyerId);

        $elInfoBuyerAddress = $dom->createElement('Адрес');
        $elInfoBuyer->appendChild($elInfoBuyerAddress);

        $elInfoBuyerAddressData = $dom->createElement('АдрИнф');
        $elInfoBuyerAddressData->setAttribute('КодСтр', $this->client->contragent->country_id);
        $elInfoBuyerAddressData->setAttribute('НаимСтран', $this->client->contragent->country->name);
        $elInfoBuyerAddressData->setAttribute('АдрТекст', $this->client->address);
        $elInfoBuyerAddress->appendChild($elInfoBuyerAddressData);

        $clientContacts = $this->client->getOfficialContact();
        $phone = array_shift($clientContacts['phone']);
        $email = array_shift($clientContacts['email']);
        if ($phone || $email) {
            $elInfoBuyerContact = $dom->createElement('Контакт');
            $elInfoBuyer->appendChild($elInfoBuyerContact);

            if ($phone) {
                $elInfoBuyerContactPhone = $dom->createElement('Тлф', $this->formatText($phone));
                $elInfoBuyerContact->appendChild($elInfoBuyerContactPhone);
            }

            if ($email) {
                $elInfoBuyerContactEmail = $dom->createElement('ЭлПочта', $this->formatText($email));
                $elInfoBuyerContact->appendChild($elInfoBuyerContactEmail);
            }
        }



        $elMoney = $dom->createElement('ДенИзм');
        $elMoney->setAttribute('КодОКВ', $this->bill->currencyModel->code);
        $elMoney->setAttribute('НаимОКВ', $this->bill->currencyModel->name);
        $elInvoiceInfo->appendChild($elMoney);
        $elDoc->appendChild($elInvoiceInfo);

        // --------------------------------------------------------------------------------------------------------------
        // Файл.Документ.ТаблСчФакт
        $elInvoiceTable = $this->addElementItemsTable($dom);
        $elDoc->appendChild($elInvoiceTable);

        // --------------------------------------------------------------------------------------------------------------
        $elPass = $this->getFileDocumentContentsOfTheEconomicFact($dom);

        if ($elPass) {
            $elDoc->appendChild($elPass);
        }
    }

    protected function addPaymentDocuments(\DOMDocument $dom, \DOMElement $elInvoiceInfo)
    {
        if ($this->invoice->is_hide_payment_number) {
            return;
        }

        if (
            $this->invoice->type_id == Invoice::TYPE_1
            || $this->invoice->type_id == Invoice::TYPE_2
            || $this->invoice->type_id == Invoice::TYPE_GOOD
        ) {
            foreach ($this->bill->getInvoicePayments() as $payment) {
                $elPayment = $dom->createElement('СвПРД');
                $elPayment->setAttribute('ДатаПРД', (new \DateTime())->setTimestamp($payment['payment_date_ts'])->format('d.m.Y'));
                $elPayment->setAttribute('ДатаПРД', (new \DateTime($payment->payment_date))->format('d.m.Y'));
                $elPayment->setAttribute('НомерПРД', $payment['payment_no']);
                $elInvoiceInfo->appendChild($elPayment);
            }
        }
    }

    protected function getDocumentTitle($isShort = false)
    {
        return $this->invoice->type_id == Invoice::TYPE_GOOD ? 'ТОРГ12' : 'АКТ';
    }

    /**
     * Информация о Покупателе - ФЛ (формат 5.03)
     *
     * @param \DOMElement $elInfoBuyerId
     */
    protected function addBuyerInfoPerson(\DOMElement $elInfoBuyerId)
    {
        $dom = $elInfoBuyerId->ownerDocument;

        $elInfoBuyerIdType = $dom->createElement('СвФЛУч');
        if ($this->client->contragent->person->inn) {
            $elInfoBuyerIdType->setAttribute('ИННФЛ', $this->client->contragent->person->inn);
        }

        $elInfoBuyerIdTypeData = $dom->createElement('ФИО');
        $initials = $this->getInitials($this->client->contragent->name_full);
        $elInfoBuyerIdTypeData->setAttribute('Имя', ($this->client->contragent->person->first_name ?: $initials[1]));
        $elInfoBuyerIdTypeData->setAttribute('Отчество', ($this->client->contragent->person->middle_name ?: $initials[2]));
        $elInfoBuyerIdTypeData->setAttribute('Фамилия', ($this->client->contragent->person->last_name ?: $initials[0]));
        $elInfoBuyerIdType->appendChild($elInfoBuyerIdTypeData);

        $elInfoBuyerId->appendChild($elInfoBuyerIdType);
    }

    protected function getFileDocumentContentsOfTheEconomicFact($dom)
    {
        $reasonForTransfer = InvoiceBillLight::reasonForTransferUpd($this->client, $this->bill);

        // Файл.Документ.СвПродПер
        $elPass = $dom->createElement('СвПродПер');
        $elPassInfo = $dom->createElement('СвПер');
        $elPassInfo->setAttribute('СодОпер', 'Реализация');
        $elPass->appendChild($elPassInfo);

        $elPassInfoMain = $dom->createElement('ОснПер');
        $elPassInfoMain->setAttribute('РеквДатаДок', $reasonForTransfer['date_human']);
        $elPassInfoMain->setAttribute('РеквНаимДок', $reasonForTransfer['name']);
        $elPassInfoMain->setAttribute('РеквНомерДок', $reasonForTransfer['number']);
        $elPassInfo->appendChild($elPassInfoMain);

        return $elPass;
    }

    /**
     * Создает свойство Файл.Документ.Подписант
     *
     * @param \DOMDocument $dom
     * @return \DOMElement
     */
    protected function createElementSigner(\DOMDocument $dom)
    {
        // https://www.consultant.ru/document/cons_doc_LAW_440556/93d50e3e8b4aeef0627f46c14b35978364442fcc/

        $elSigner = $dom->createElement('Подписант');
        $elSigner->setAttribute('СпосПодтПолном', 1); // 1 - в соответствии с данными, содержащимися в электронной подписи
        $elSigner->setAttribute('Должн', $this->organizationFrom->director->post_nominative);

        $elInitials = $dom->createElement('ФИО');
        $initials = $this->getInitials($this->organizationFrom->director->name_nominative);
        $elInitials->setAttribute('Имя', $initials[1]);
        $elInitials->setAttribute('Отчество', $initials[2]);
        $elInitials->setAttribute('Фамилия', $initials[0]);
        $elSigner->appendChild($elInitials);

        return $elSigner;
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
//            $elLine->setAttribute('ОКЕИ_Тов', 796);// choice1 required
//            $elLine->setAttribute('НаимЕдИзм', 'шт');// choice1 required
            $elLine->setAttribute('КолТов', $this->formatNumber($line->getAmount(), 3));// optional
            $elLine->setAttribute('СтТовБезНДС', $this->formatNumber($line->getPrice_without_vat()));// optional
            $elLine->setAttribute('ЦенаТов', $this->formatNumber($line->getPrice_per_unit()));// optional
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
        $elInvoiceTable->appendChild($elTotal);

        $elTotalSumTotal = $dom->createElement('СумНалВсего');// required
        $this->addTaxSumElement($elTotalSumTotal, $maxVatRate, $this->invoice->sum_tax);
        $elTotal->appendChild($elTotalSumTotal);

        return $elInvoiceTable;
    }
}
