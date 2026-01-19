<?php

namespace app\modules\uu\models_light;

use app\models\ClientAccount;
use app\models\InvoiceSettings;
use app\modules\uu\models\AccountEntry;
use GoodUnit;
use Yii;
use yii\base\Component;

class InvoiceItemsLight extends Component implements InvoiceLightInterface
{
    const CODE_MONTH = 362; // код месяца в счет-фактуре

    public $items = [];

    private
        $_clientAccount,
        $_language,
        $_clientContragentEuroINN = false,
        $_isDetailed = true;

    /**
     * @param ClientAccount $clientAccount
     * @param InvoiceBillLight $bill
     * @param AccountEntry[] $items
     * @param string $language
     */
    public function __construct(ClientAccount $clientAccount, InvoiceBillLight $bill, $items, $language)
    {
        parent::__construct();

        $this->_clientAccount = $clientAccount;
        $this->_language = $language;
        // Взять EU Vat ID у контрагента
        $this->_clientContragentEuroINN = $clientAccount->contragent->inn_euro;
        // Язык счета
        $billLanguage = $bill->getLanguage();
        // Установить тип закрывающего документа (Полный / Краткий)
        $this->_isDetailed = (bool)$clientAccount->type_of_bill;

        foreach ($items as $item) {

            $vat = is_array($item) ? $item['sum_tax'] : $item->vat;
            $priceWithoutTax = is_array($item) ? $item['sum_without_tax'] : $item->price_without_vat;
            $priceWithTax = is_array($item) ? $item['sum'] : $item->price_with_vat;

            // Подсчет суммы счета
            $bill
                ->setSummaryVat($vat)
                ->setSummaryWithoutVat($priceWithoutTax)
                ->setSummaryWithVat($priceWithTax);

            $itemAmount = is_array($item) ? $item['amount'] : $item->getAmount();

            $this->items[] = [
                'title' => is_array($item) ? $item['item'] : $item->getFullName($billLanguage),
                'amount' => $itemAmount,
                'unit_code' => '-',
                'unit' => is_array($item) ? '' : $item->getTypeUnitName($billLanguage),
                'price_per_unit' => ($itemAmount > 0 ? number_format(round((float)$priceWithoutTax / $itemAmount, 4), 2) : ''),
                'price_without_vat' => $priceWithoutTax,
                'price_with_vat' => $priceWithTax,
                'vat_rate' => is_array($item) ? $item['tax_rate'] : $item->vat_rate,
                'vat' =>  $vat,
            ];
        }

        $bill->setPageCount(\printUPD::getInfo(count($items)));
    }

    /**
     * @return array
     */
    public function getAll()
    {
        if ($this->_isDetailed === ClientAccount::TYPE_OF_BILL_SIMPLE) {
            $billLine = [
                'title' => Yii::t(
                    'biller',
                    'Communications services contract #{contract_number}',
                    ['contract_number' => $this->_clientAccount->contract->number],
                    $this->_language
                ),
                'price_per_unit' => 0,
                'price_without_vat' => 0,
                'price_with_vat' => 0,
                'vat_rate' => 0,
                'vat' => 0,
                'amount' => 1,
                'unit_code' => self::CODE_MONTH,
                'unit' => Yii::t('biller', 'Month', $this->_language),
            ];
            foreach ($this->items as $item) {
                $billLine['price_per_unit'] += $item['price_without_vat'];
                $billLine['price_without_vat'] += $item['price_without_vat'];
                $billLine['price_with_vat'] += $item['price_with_vat'];
                $billLine['vat_rate'] = $item['vat_rate'];
                $billLine['vat'] += $item['vat'];
            }

            return [$billLine];
        }

        return $this->items;
    }

    /**
     * @return string
     */
    public static function getKey()
    {
        return 'item';
    }

    /**
     * @return string
     */
    public static function getBlockKey()
    {
        return 'items';
    }

    /**
     * @return string
     */
    public static function getTitle()
    {
        return 'Данные о проводке (используется в цикле $items)';
    }

    /**
     * @return array
     */
    public static function attributeLabels()
    {
        return [
            'title' => 'Название услуги',
            'amount' => 'Кол-во',
            'unit' => 'Ед. измерения',
            'price_per_unit' => 'Цена за ед. измерения',
            'price_without_vat' => 'Цена без НДС',
            'price_with_vat' => 'Цена с НДС',
            'vat' => 'НДС',
            'vat_rate' => 'Процент НДС',
        ];
    }

}