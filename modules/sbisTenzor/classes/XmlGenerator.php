<?php

namespace app\modules\sbisTenzor\classes;

use app\models\Bill;
use app\models\ClientAccount;
use app\models\Invoice;
use app\models\Organization;
use app\modules\sbisTenzor\helpers\SBISDataProvider;
use app\modules\sbisTenzor\helpers\SBISInfo;
use app\modules\sbisTenzor\models\SBISExchangeForm;
use app\modules\sbisTenzor\classes\XmlGenerator\Act2016Form5_02;
use app\modules\sbisTenzor\classes\XmlGenerator\Invoice2016Form5_02;
use app\modules\sbisTenzor\classes\XmlGenerator\Invoice2019Form5_01;
use app\modules\sbisTenzor\classes\XmlGenerator\Invoice2025Form5_03;
use app\modules\sbisTenzor\classes\XmlGenerator\Upd2023Form5_03;
use app\modules\sbisTenzor\helpers\SBISUtils;
use DateTime;

/**
 * Форма первичного документа (файла) в системе СБИС
 *
 */
abstract class XmlGenerator// extends SBISExchangeForm
{
    protected static $classes = [
        SBISExchangeForm::ACT_2016_5_02 => Act2016Form5_02::class,
        SBISExchangeForm::INVOICE_2016_5_02 => Invoice2016Form5_02::class,
        SBISExchangeForm::INVOICE_2019_5_01 => Invoice2019Form5_01::class,
        SBISExchangeForm::INVOICE_2025_5_03 => Invoice2025Form5_03::class,
        SBISExchangeForm::UPD_2023_5_03 => Upd2023Form5_03::class,
    ];

    protected static $xsdFilePathParts = [
        __DIR__,
        'XmlGenerator',
        'schemas'
    ];

    /** @var SBISExchangeForm */
    protected $form;
    /** @var Invoice */
    protected $invoice;

    /** @var string */
    protected $formVersion;
    /** @var int */
    protected $kndCode;
    /** @var string */
    protected $fileIdPattern;
    /** @var string */
    protected $signerBaseAttribute = '';
    /** @var string */
    protected $softName = '';

    /** @var \DOMDocument */
    protected $domDocument;
    /** @var Bill */
    protected $bill;
    /** @var ClientAccount */
    protected $client;
    /** @var DateTime */
    protected $invoiceInitialDate;
    /** @var DateTime */
    protected $invoiceDate;
    /** @var DateTime */
    protected $invoiceDateAdded;
    /** @var string */
    protected $fileId;
    /** @var DateTime */
    protected $now;
    /** @var Organization */
    protected $organizationFrom;
    /** @var string */
    protected $sbisIdSender;
    /** @var string */
    protected $xsdFile;

    /** @var bool */
    public $isInformationAboutParticipants = true;
    /**
     * Создание генератора
     *
     * @param SBISExchangeForm $form
     * @param Invoice $invoice
     * @return static
     */
    public static function createXmlGenerator(SBISExchangeForm $form, Invoice $invoice)
    {
        $class = self::$classes[$form->id];
        $instance = new $class($form, $invoice);

        return $instance;
    }

    /**
     * XmlGenerator constructor.
     *
     * @param SBISExchangeForm $form
     * @param Invoice $invoice
     * @throws \Exception
     */
    public function __construct(SBISExchangeForm $form, Invoice $invoice)
    {
        $this->form = $form;
        $this->invoice = $invoice;

        $this->init();
        $this->generateXmlDocument();
    }

    /**
     * @throws \Exception
     */
    protected function init()
    {
        // form data
        $this->kndCode = $this->form->knd_code;
        $this->formVersion = $this->form->version;
        $this->fileIdPattern = $this->form->file_pattern;

        // invoice data
        $invoice = $this->invoice;

        $bill = $invoice->bill;
        if (!$bill) {
            throw new \Exception(
                sprintf('Для данного отчетного документа (Invoice #%s) не найден расчётный документ с номером %s!', $invoice->id, $invoice->bill_no)
            );
        }

        $client = $bill->clientAccount;
        $this->organizationFrom = $this->invoice->organization;

        $sbisOrganization = SBISDataProvider::getSBISOrganizationByClient($client, $this->organizationFrom);
        if (!$sbisOrganization) {
            throw new \Exception(
                sprintf('Обслуживающая данного клиента организация %s не настроена для работы со СБИС!', $this->organizationFrom->name)
            );
        }

        $this->sbisIdSender = $sbisOrganization->exchange_id;

        // set vars
        $this->bill = $bill;
        $this->client = $client;

        $this->invoiceInitialDate = new DateTime($invoice->getInitialDate());
        $this->invoiceDate = new DateTime($invoice->date);
        $this->invoiceDateAdded = new DateTime($invoice->add_date);
    }

    /**
     * @return string
     */
    public function getFileName()
    {
        return $this->fileId . '.xml';
    }

    /**
     * @return string
     */
    public function getContent()
    {
        //return $this->domDocument->saveXML();
        return $this->domDocument->saveXML($this->domDocument->documentElement);
    }

    /**
     * @return string
     */
    protected function getXsdFile()
    {
        if (!$this->xsdFile) {
            return '';
        }

        $parts = self::$xsdFilePathParts;
        $parts[] = $this->xsdFile;

        return implode(DIRECTORY_SEPARATOR, $parts);
    }

    /**
     * Возвращает текст ошибки валидации no схеме xsd
     *
     * @return string
     */
    public function getErrorText()
    {
        $errorText = '';

        $xsdFile = $this->getXsdFile();
        if (!$xsdFile || !file_exists($xsdFile)) {
            return $errorText;
        }

        try {
            if ($this->domDocument->schemaValidate($xsdFile)) {
                return $errorText;
            }
            $errors = libxml_get_errors() ? : [libxml_get_last_error()];
            foreach ($errors as $error) {
                switch ($error->level) {
                    case LIBXML_ERR_WARNING:
                        $errorText .= "Warning $error->code: ";
                        break;
                    case LIBXML_ERR_ERROR:
                        $errorText .= "Error $error->code: ";
                        break;
                    case LIBXML_ERR_FATAL:
                        $errorText .= "Fatal Error $error->code: ";
                        break;
                }

                $errorText .= trim($error->message);
                if ($error->file) {
                    $errorText .= " in $error->file";
                }

                if ($error->line) {
                    $errorText .= " on line $error->line" . PHP_EOL;
                }
            }
        } catch (\Exception $e) {
            $errorText = $e->getMessage();

            // strip 'DOMDocument::schemaValidate(): '
            if (strpos($errorText, 'DOMDocument::schemaValidate(): ') !== false) {
                $errorText = substr($errorText, 31);
            }
        }

        return $errorText;
    }

    /**
     * @param string $fileName
     * @return bool
     * @throws \Exception
     */
    public function save($fileName = '')
    {
        $fileName = $fileName ? : $this->getFileName();
        $this->domDocument->save($fileName);

        return true;
    }

    /**
     * @param float $number
     * @param int $digits
     * @return string
     */
    protected function formatNumber($number, $digits = 2)
    {
        return number_format($number, $digits, '.', '');
    }

    /**
     * @param mixed $text
     * @return string
     */
    protected function prepareText($text)
    {
        return trim(
                strtr(strval($text), [
                '«' => '"',
                '»' => '"',
            ])
        );
    }

    /**
     * @param string $text
     * @return string
     */
    protected function formatText($text)
    {
        return htmlspecialchars($this->prepareText($text));
    }

    /**
     * @return \DOMDocument
     * @throws \Exception
     */
    protected function generateXmlDocument()
    {
        $this->now = new DateTime();

        $dom = new \DOMDocument('1.0', 'windows-1251');
        $dom->encoding = 'windows-1251';

        // мы хотим красивый вывод
        $dom->formatOutput = true;

        $elFile = $this->createElementFile($dom);
        $dom->appendChild($elFile);

        //doc
        $elDoc = $this->createElementDocument($dom);
        $elFile->appendChild($elDoc);

        $this->fillElementDocument($elDoc);

        // doc - signer
        $elSigner = $this->createElementSigner($dom);
        $elDoc->appendChild($elSigner);

        $this->domDocument = $dom;

        return $dom;
    }

    /**
     * Создает свойство Файл
     *
     * @param \DOMDocument $dom
     * @return \DOMElement
     * @throws \app\modules\sbisTenzor\exceptions\SBISTensorException
     * @throws \yii\base\Exception
     * @throws \yii\web\BadRequestHttpException
     */
    protected function createElementFile(\DOMDocument $dom)
    {
        $fileUid = SBISUtils::generateUuid();
        $sbisIdRecipient = SBISInfo::getExchangeIntegrationId($this->client);

        $this->fileId = strtr($this->fileIdPattern, [
            '{A}' => $sbisIdRecipient,
            '{O}' => $this->sbisIdSender,
            '{GGGGMMDD}' => $this->invoiceDateAdded->format('Ymd'),
            '{N}' => $fileUid,
        ]);

        $elFile = $dom->createElement('Файл');
        if ($this->softName) {
            $elFile->setAttribute('ВерсПрог', $this->softName);
        }
        $elFile->setAttribute('ВерсФорм', $this->formVersion);
        $elFile->setAttribute('ИдФайл', $this->fileId);

        // добавляем свойство Файл.СвУчДокОбор
        if ($this->isInformationAboutParticipants) {
            $elInfo = $dom->createElement('СвУчДокОбор');
            $elInfo->setAttribute('ИдОтпр', $this->sbisIdSender);
            $elInfo->setAttribute('ИдПол', $sbisIdRecipient);

            $elInfoSender = $dom->createElement('СвОЭДОтпр');
            $elInfoSender->setAttribute('ИННЮЛ', 7605016030);
            $elInfoSender->setAttribute('ИдЭДО', '2BE');
            $elInfoSender->setAttribute('НаимОрг', 'ООО "Компания "Тензор"');
            $elInfo->appendChild($elInfoSender);
            $elFile->appendChild($elInfo);
        }

        return $elFile;
    }

    /**
     * Создает свойство Файл.Документ
     *
     * @param \DOMDocument $dom
     * @return \DOMElement
     */
    protected function createElementDocument(\DOMDocument $dom)
    {
        $elDoc = $dom->createElement('Документ');
        $elDoc->setAttribute('ВремИнфПр', $this->now->format('H.i.s'));
        $elDoc->setAttribute('ДатаИнфПр', $this->now->format('d.m.Y'));
        $elDoc->setAttribute('КНД', $this->kndCode);
        $elDoc->setAttribute('НаимЭконСубСост', $this->prepareText($this->organizationFrom->full_name));

        return $elDoc;
    }

    /**
     * Создает свойство Файл.Документ.Подписант
     *
     * @param \DOMDocument $dom
     * @return \DOMElement
     */
    protected function createElementSigner(\DOMDocument $dom)
    {
        $elSigner = $dom->createElement('Подписант');
        $elSigner->setAttribute('ОблПолн', 2);
        $elSigner->setAttribute($this->signerBaseAttribute, 'Должностные обязанности');
        $elSigner->setAttribute('Статус', 1);

        $elSignerType = $dom->createElement('ЮЛ');
        $elSignerType->setAttribute('Должн', $this->organizationFrom->director->post_nominative);
        $elSignerType->setAttribute('ИННЮЛ', $this->organizationFrom->tax_registration_id);
        $elSignerType->setAttribute('НаимОрг', $this->prepareText($this->organizationFrom->full_name));
        $elSigner->appendChild($elSignerType);

        $initials = $this->getInitials($this->organizationFrom->director->name_nominative);
        $elInitials = $this->createFioElement($dom, $initials[0], $initials[1], $initials[2]);
        $elSignerType->appendChild($elInitials);

        return $elSigner;
    }

    /**
     * Создает узел ФИО с опциональным отчеством.
     *
     * @param \DOMDocument $dom
     * @param string $lastName
     * @param string $firstName
     * @param string $middleName
     * @return \DOMElement
     */
    protected function createFioElement(\DOMDocument $dom, $lastName, $firstName, $middleName = '')
    {
        $elInitials = $dom->createElement('ФИО');
        $elInitials->setAttribute('Имя', $firstName);
        $elInitials->setAttribute('Фамилия', $lastName);
        if ($middleName !== '') {
            $elInitials->setAttribute('Отчество', $middleName);
        }

        return $elInitials;
    }

    /**
     * Инициалы подписанта
     *
     * @param string $fullName
     * @return array
     */
    protected function getInitials($fullName)
    {
        $fullName = strtr($fullName, [
            'ИП ' => '',
            '«' => '',
            '»' => '',
            '"' => '',
        ]);
        $fullName = trim($fullName, " .\t");
        $fullName = preg_replace('/\s+/', ' ', $fullName);

        $parts = explode(' ', $fullName);
        if (
            count($parts) == 2 &&
            (strpos($parts[1], '.') !== false)
        ) {
            $names2And3 = explode('.', trim($parts[1], " .\t"));

            if (count($names2And3) == 2) {
                $parts[1] = $names2And3[0] . '.';
                $parts[2] = $names2And3[1] . '.';
            }
        }

        $surName = !empty($parts[0]) ? $parts[0] : '';
        $name = !empty($parts[1]) ? $parts[1] : '';
        $fathersName = !empty($parts[2]) ? $parts[2] : '';

        return [$surName, $name, $fathersName];
    }

    /**
     * Заполняет свойство Файл.Документ
     *
     * @param \DOMElement $elDoc
     */
    abstract protected function fillElementDocument(\DOMElement $elDoc);
}