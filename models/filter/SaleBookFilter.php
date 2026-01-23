<?php

namespace app\models\filter;


use app\helpers\DateTimeZoneHelper;
use app\models\Business;
use app\models\BusinessProcessStatus;
use app\models\ClientAccountOptions;
use app\models\ClientContract;
use app\models\ClientContragent;
use app\models\Country;
use app\models\Currency;
use app\models\Invoice;
use app\models\InvoiceSettings;
use app\models\Organization;
use yii\base\NotSupportedException;
use yii\db\ActiveQuery;
use yii\db\Expression;

class SaleBookFilter extends Invoice
{
    const FILTER_ALL = 'all';
    const FILTER_NORMAL = 'normal';
    const FILTER_REVERSAL = 'reversal';
    const FILTER_ADDITION = 'dop_list';
    const REGISTER = 1;

    public static $filters = [
        self::FILTER_ALL => 'Всё',
        self::FILTER_NORMAL => 'Нормальные с/ф',
        self::FILTER_REVERSAL => 'Сторнированные',
        self::FILTER_ADDITION => '?Доп.лист',
    ];

    public static $skipping_bps = [
        BusinessProcessStatus::TELEKOM_MAINTENANCE_TRASH,
        BusinessProcessStatus::TELEKOM_MAINTENANCE_FAILURE,
        BusinessProcessStatus::WELLTIME_MAINTENANCE_FAILURE
    ];


    public
        $date_from = null,
        $date_to = null,
        $organization_id = null,
        /** \DateTimeImmutable */
        $dateFrom = null,
        /** \DateTimeImmutable */
        $dateTo = null,
        $filter = self::FILTER_NORMAL,
        $currency = Currency::RUB,
        $is_euro_format = 0,
        $is_excel_eu_bmd = 0,
        $is_register = 0,
        $is_register_vp = 0,
        $is_invoice_off = 0;

    public function __construct()
    {
        $from = (new \DateTimeImmutable())->modify('first day of this month');

        $this->date_from = $from->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE);
        $this->date_to = $from->modify('last day of this month')->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE);
    }


    public function rules()
    {
        return [
            [['date_from', 'date_to', 'organization_id', /*'filter', */ 'currency'], 'required'],
            [['is_euro_format', 'is_euro_format_bmd', 'is_register', 'is_register_vp', 'is_invoice_off'], 'integer'],
            [['date_from', 'date_to'], 'date'],
            [['organization_id'], 'in', 'range' => array_keys(Organization::dao()->getList())],
//            ['filter', 'in', 'range' => array_keys(self::$filters)],
        ];
    }

    public function attributeLabels()
    {
        return parent::attributeLabels() + [
                'is_euro_format' => 'ЕвроФормат',
                'is_euro_format_bmd' => 'ЕвроФормат (BMD)',
                'is_register' => 'Реестр МСН Телеком (ВАТС)',
                'is_register_vp' => 'Реестр АбСервис (ВАТС+ТелСистема)',
                'is_invoice_off' => 'Галочка "Выгружать с/ф ЛС в книгу продаж" не стоит',
            ];
    }

    public function beforeValidate()
    {
        if ($this->date_from && preg_match('/(\d{2})-(\d{2})-(\d{4})/', $this->date_from, $o)) {
            $this->dateFrom = (new \DateTimeImmutable())->setDate($o[3], $o[2], $o[1]);
        }

        if ($this->date_to && preg_match('/(\d{2})-(\d{2})-(\d{4})/', $this->date_to, $o)) {
            $this->dateTo = (new \DateTimeImmutable())->setDate($o[3], $o[2], $o[1]);
        }
    }


    /**
     * @return ActiveQuery
     * @throws NotSupportedException
     */
    public function search()
    {
        if (!$this->dateFrom || !$this->dateTo) {
            return false;
        }

        $query = self::find()
            ->alias('inv')
            ->where([
                'inv.organization_id' => $this->organization_id,
            ])
//            ->andWhere(['OR', ['between',
//                'inv.date',
//                $this->dateFrom->format(DateTimeZoneHelper::DATE_FORMAT),
//                $this->dateTo->format(DateTimeZoneHelper::DATE_FORMAT)
//            ], ['between',
//                'inv.invoice_date',
//                $this->dateFrom->format(DateTimeZoneHelper::DATE_FORMAT),
//                $this->dateTo->format(DateTimeZoneHelper::DATE_FORMAT)
//            ]])
            ->andWhere(['between',
                (new Expression('COALESCE(inv.invoice_date, inv.date)')),
                $this->dateFrom->format(DateTimeZoneHelper::DATE_FORMAT),
                $this->dateTo->format(DateTimeZoneHelper::DATE_FORMAT)
            ])
            ->andWhere(['NOT', ['number' => null]])
            ->orderBy([
                'inv.idx' => SORT_ASC,
                'inv.id' => SORT_ASC,
            ]);

        $query->joinWith('bill bill', true, 'INNER JOIN');
        $query->with('bill');
        $query->with('bill.clientAccountModel');
        $query->with('bill.payments');
        $query->with('lines');
        $query->with('lines.line');
        $query->with('lines.line.accountTariff');

        $this->currency && $query->andWhere(['bill.currency' => $this->currency]);

        if (\Yii::$app->isEu()) {
            $query->joinWith('bill.clientAccountModel c');
            $query->andWhere(['OR',
                ['not', ['c.currency' => Currency::RUB]],
                ['c.id' => $this->getRubAccountIds()]
            ]);
        }

        if ($this->is_invoice_off) {
            // Добавляем фильтр по upload_to_sales_book
            $query->joinWith('bill.clientAccountModel.options options');
            $query->andWhere(['options.option' => ClientAccountOptions::OPTION_UPLOAD_TO_SALES_BOOK, 'options.value' => '0']);
        }

//        throw new \Exception($query->createCommand()->rawSql);

        /*
        switch ($this->filter) {
            case self::FILTER_ALL:
                // nothing
                break;

            case self::FILTER_NORMAL:
                $query->andWhere(['is_reversal' => 0]);
                break;

            case self::FILTER_REVERSAL:
                $query->andWhere(['is_reversal' => 1]);
                break;
            default:
                throw new NotSupportedException('Не готово');
        }
        */

        return $query;
    }

    public function getRubAccountIds()
    {
        $query = <<<SQL
    select distinct c.id
    from clients c,
         uu_account_tariff at,
         uu_account_tariff_log l,
         uu_tariff_period tp,
         uu_tariff t
    where t.name like '%Global%'
      and t.service_type_id = 2
      and at.service_type_id = 2
      and c.currency = 'RUB'
      and c.id = at.client_account_id
      and at.id = l.account_tariff_id
      and l.tariff_period_id = tp.id
      and tp.tariff_id = t.id
SQL;

        return self::getDb()->createCommand($query)->queryColumn();
    }

    public function getPaymentsStr()
    {
        $str = '';

        foreach ($this->bill->payments as $payment) {
            $str && $str .= ', ';

            $str .= $payment->payment_no . '; ' .
                (new \DateTimeImmutable($payment->payment_date))->format(DateTimeZoneHelper::DATE_FORMAT_EUROPE_DOTTED);
        }

        return $str;
    }

    /**
     * Нужная счет-фактура или нет
     *
     * @param Invoice $invoice
     * @return bool
     */
    public function check(Invoice $invoice)
    {
        $contract = $invoice->bill->clientAccount->contract;

        // internal office
        if ($contract->business_id == Business::INTERNAL_OFFICE) {
            return false;
        }

        // если есть с/ф-3 - значит была реализация
        if ($invoice->type_id == Invoice::TYPE_GOOD) {
            return true;
        }


        # AND IF(B.`sum` < 0, cr.`contract_type_id` =2, true) ### only telekom clients with negative sum

        # AND cr.`contract_type_id` != 6 ## internal office
        # AND cr.`business_process_status_id` NOT IN (22, 28, 99) ## trash, cancel

        return !(in_array($contract->business_process_status_id, self::$skipping_bps));
    }

    public function getAtCode($obj = null, $obj2 = null, $obj3 = null)
    {
        $contract = $contragent = $invoice = null;

        $objs = array_filter([$obj, $obj2, $obj3]);

        foreach ($objs as $obj) {
            if ($obj instanceof ClientContract) {
                $contract = $obj;
            }

            if ($obj instanceof ClientContragent) {
                $contragent = $obj;
            }

            if ($obj instanceof Invoice) {
                $invoice = $obj;
            }
        }

        if (!$contragent) {
            if ($contract) {
                $contragent = $contract->contragent;
            } elseif ($invoice) {
                $contract = $invoice->bill->clientAccount->contract;
                $contragent = $contract->contragent;
            }
        }

        $organizationId = $this->organization_id;
        $countryId = $contragent->country_id;

        return InvoiceSettings::dao()->getAtAccountCode($organizationId, $countryId, $contract->contragent->tax_regime) ?: InvoiceSettings::dao()->getLastErrorString();
    }

    public function getFilial(ClientContragent $contragent)
    {
        $countryCode = $contragent->country_id;
        static $c = [];

        if (!$c) {
            $c = $this->_loadFilials();
        }

        $countryCodeAt = $c[$countryCode] ?? null;

        if (!$countryCodeAt) {
            return '??? ' . $contragent->country->name;
        }

        return $countryCodeAt;
    }

    private function _loadFilials()
    {
        $map = [
            ['country_code_at' => 1, 'country_code' => 40, 'name' => 'Austria'],
            ['country_code_at' => 2, 'country_code' => 276, 'name' => 'Germany'],
            ['country_code_at' => 3, 'country_code' => 380, 'name' => 'Italy'],
            ['country_code_at' => 4, 'country_code' => 56, 'name' => 'Belgium'],
            ['country_code_at' => 5, 'country_code' => 528, 'name' => 'Netherlands'],
            ['country_code_at' => 6, 'country_code' => 250, 'name' => 'France'],
            ['country_code_at' => 7, 'country_code' => 372, 'name' => 'Ireland'],
            ['country_code_at' => 8, 'country_code' => 442, 'name' => 'Luxembourg'],
            ['country_code_at' => 9, 'country_code' => 620, 'name' => 'Portugal'],
            ['country_code_at' => 10, 'country_code' => 724, 'name' => 'Spain'],
            ['country_code_at' => 11, 'country_code' => 246, 'name' => 'Finland'],
            ['country_code_at' => 12, 'country_code' => 826, 'name' => 'United Kingdom'],
            ['country_code_at' => 13, 'country_code' => 752, 'name' => 'Sweden'],
            ['country_code_at' => 14, 'country_code' => 208, 'name' => 'Denmark'],
            ['country_code_at' => 15, 'country_code' => 300, 'name' => 'Greece'],
            ['country_code_at' => 16, 'country_code' => 348, 'name' => 'Hungary'],
            ['country_code_at' => 17, 'country_code' => 756, 'name' => 'Switzerland'],
            ['country_code_at' => 18, 'country_code' => 703, 'name' => 'Slovakia'],
            ['country_code_at' => 19, 'country_code' => 203, 'name' => 'Czech Republic'],
            ['country_code_at' => 20, 'country_code' => 616, 'name' => 'Poland'],
            ['country_code_at' => 21, 'country_code' => 440, 'name' => 'Lithuania'],
            ['country_code_at' => 22, 'country_code' => 792, 'name' => 'Turkey'],
            ['country_code_at' => 23, 'country_code' => 470, 'name' => 'Malta'],
            ['country_code_at' => 24, 'country_code' => 100, 'name' => 'Bulgaria'],
            ['country_code_at' => 25, 'country_code' => 642, 'name' => 'Romania'],
            ['country_code_at' => 26, 'country_code' => 233, 'name' => 'Estonia'],
            ['country_code_at' => 27, 'country_code' => 428, 'name' => 'Latvia'],
            ['country_code_at' => 28, 'country_code' => 191, 'name' => 'Croatia'],
            ['country_code_at' => 29, 'country_code' => 705, 'name' => 'Slovenia'],
            ['country_code_at' => 41, 'country_code' => 51, 'name' => 'Armenia'],
            ['country_code_at' => 66, 'country_code' => 156, 'name' => 'China'],
            ['country_code_at' => 101, 'country_code' => 356, 'name' => 'India'],
            ['country_code_at' => 106, 'country_code' => 376, 'name' => 'Israel'],
            ['country_code_at' => 113, 'country_code' => 92, 'name' => 'Virgin Islands, british'],
            ['country_code_at' => 121, 'country_code' => 398, 'name' => 'Kazakstan'],
            ['country_code_at' => 138, 'country_code' => 434, 'name' => 'Libyan Arab Jamahiriya'],
            ['country_code_at' => 170, 'country_code' => 530, 'name' => 'Netherlands Antilles'],
            ['country_code_at' => 191, 'country_code' => 643, 'name' => 'Russian Federation'],
            ['country_code_at' => 204, 'country_code' => 702, 'name' => 'Singapore'],
            ['country_code_at' => 233, 'country_code' => 804, 'name' => 'Ukraine'],
            ['country_code_at' => 235, 'country_code' => 840, 'name' => 'United States'],
            ['country_code_at' => 246, 'country_code' => 196, 'name' => 'Cyprus'],
        ];

        $result = [];
        foreach ($map as $row) {
            $result[$row['country_code']] = $row['country_code_at'];
        }

        return $result;
    }

    public function getSteuerCode($steuer)
    {
        $map = [
            ['sale_index' => 'AT telco sales', 'gkonto' => 4000, 'steuercode' => 1, 'text' => 'telekommunikationsdinstleitungen'],
            ['sale_index' => 'AT Webshop', 'gkonto' => 4001, 'steuercode' => 1, 'text' => 'Equipment from webshop to AT'],
            ['sale_index' => 'EU Reverse charge', 'gkonto' => 4113, 'steuercode' => 77, 'text' => 'telekommunikationsdinstleitungen'],
            ['sale_index' => 'EXPORT 0% VAT', 'gkonto' => 4210, 'steuercode' => '', 'text' => 'telekommunikationsdinstleitungen'],
            ['sale_index' => 'ES MOSS', 'gkonto' => 4010, 'steuercode' => 1, 'text' => 'telekommunikationsdinstleitungen'],
            ['sale_index' => 'GB MOSS', 'gkonto' => 4012, 'steuercode' => 1, 'text' => 'telekommunikationsdinstleitungen'],
            ['sale_index' => 'HU MOSS', 'gkonto' => 4016, 'steuercode' => 1, 'text' => 'telekommunikationsdinstleitungen'],
            ['sale_index' => 'SK MOSS', 'gkonto' => 4018, 'steuercode' => 1, 'text' => 'telekommunikationsdinstleitungen'],
            ['sale_index' => 'CZ MOSS', 'gkonto' => 4019, 'steuercode' => 1, 'text' => 'telekommunikationsdinstleitungen'],
            ['sale_index' => 'PL MOSS', 'gkonto' => 4020, 'steuercode' => 1, 'text' => 'telekommunikationsdinstleitungen'],
            ['sale_index' => 'RO MOSS', 'gkonto' => 4025, 'steuercode' => 1, 'text' => 'telekommunikationsdinstleitungen'],
            ['sale_index' => 'EE MOSS', 'gkonto' => 4026, 'steuercode' => 1, 'text' => 'telekommunikationsdinstleitungen'],
            ['sale_index' => 'LV MOSS', 'gkonto' => 4027, 'steuercode' => 1, 'text' => 'telekommunikationsdinstleitungen'],
            ['sale_index' => 'SI MOSS', 'gkonto' => 4029, 'steuercode' => 1, 'text' => 'telekommunikationsdinstleitungen'],
            ['sale_index' => 'DE MOSS', 'gkonto' => 4002, 'steuercode' => 1, 'text' => 'telekommunikationsdinstleitungen'],
            ['sale_index' => 'PT MOSS', 'gkonto' => 4009, 'steuercode' => 1, 'text' => 'telekommunikationsdinstleitungen'],
            ['sale_index' => 'NL MOSS', 'gkonto' => 4005, 'steuercode' => 1, 'text' => 'telekommunikationsdinstleitungen'],
            ['sale_index' => 'LT MOSS', 'gkonto' => 4021, 'steuercode' => 1, 'text' => 'telekommunikationsdinstleitungen'],
            ['sale_index' => 'EU Webshop 0% VAT', 'gkonto' => 4113, 'steuercode' => 77, 'text' => 'Equipment from webshop to EU'],
        ];

        static $c = [];

        if (!$c) {
            foreach ($map as $row) {
                $c[$row['sale_index']] = $row;

            }
        }

        if (isset($c[$steuer])) {
            return $c[$steuer]['steuercode'];
        }

        return '???';
    }

    public function getSteuer(ClientContragent $contragent, ClientContract $contract, Invoice $invoice, $taxRate, $isFront = false)
    {
        $euInnPrefixList = ['DE', 'HU', 'SK', 'ES', 'CZ', 'PL', 'RO', 'EE', 'LV', 'SI', 'PT', 'CY', 'FR', 'IE', 'NL'];
//C - country
//G - SBP
//M - VAT total
//O - VAT %
//T - EU VAT №

//        =ЕСЛИ(И(
//        C1484='Österreich';
//        ИЛИ(G1484<>'Телеком-клиент / Webshop GmbH'));
//        'AT telco sales';

        if ($contragent->country_id == Country::AUSTRIA && $contract->business_process_status_id != BusinessProcessStatus::TELEKOM_MAINTENANCE_WEB_SHOP) {
            return 'AT telco sales';
        }

        if ($contragent->country_id == Country::AUSTRIA && $contract->business_process_status_id == BusinessProcessStatus::TELEKOM_MAINTENANCE_WEB_SHOP) {
            return 'AT Webshop';
        }

        /*
         * ЕСЛИ(И(
    ИЛИ(
        G1484 = 'Телеком-клиент / Activated';
        G1484 = 'Телеком-клиент / Deactivated';
        G1484 = 'Телеком-клиент / Заказ услуг';
        ЛЕВ(G1484;13)='Межоператорка';
        ЛЕВ(G1484;3)='ОТТ');
    M1484 = 0;
    ИЛИ(
        ЛЕВ(T1484;2)='DE';
        ЛЕВ(T1484;2)='HU';
        ЛЕВ(T1484;2)='SK';
        ЛЕВ(T1484;2)='ES';
        ЛЕВ(T1484;2)='CZ';
        ЛЕВ(T1484;2)='PL';
        ЛЕВ(T1484;2)='RO';
        ЛЕВ(T1484;2)='EE';
        ЛЕВ(T1484;2)='LV';
        ЛЕВ(T1484;2)='SI';
        ЛЕВ(T1484;2)='PT';
        ЛЕВ(T1484;2)='CY';
        ЛЕВ(T1484;2)='FR';
        ЛЕВ(T1484;2)='IE';
        ЛЕВ(T1484;2)='NL'))
    ;'EU Reverse charge';
         */
        if (
            (
                in_array($contract->business_process_status_id, [BusinessProcessStatus::TELEKOM_MAINTENANCE_WORK, BusinessProcessStatus::TELEKOM_MAINTENANCE_DISCONNECTED, BusinessProcessStatus::TELEKOM_MAINTENANCE_ORDER_OF_SERVICES])
                || in_array($contract->business_id, [Business::OTT, Business::OPERATOR])
            )
            && $invoice->sum_tax == 0
            && $contragent->inn_euro != ''
            && in_array(substr($contragent->inn_euro, 0, 2), $euInnPrefixList)
        ) {
            return 'EU Reverse charge';
        }

        /*
         *     ЕСЛИ(И(
        C1484 <> 'Österreich';
            ЕПУСТО(T1484);
            M1484 = 0;
        O1484 = '0%');
    'EXPORT 0% VAT';

         */
        if (
            $contragent->country_id != Country::AUSTRIA
            && !$contragent->inn_euro
            && $taxRate == 0
        ) {
            return 'EXPORT 0% VAT';
        }

        /*
         *     ЕСЛИ(И(
        C1484 <> 'Österreich';
        G1484 = 'Телеком-клиент / Webshop GmbH');
    'EU Webshop 0% VAT';

         */
        if (
            $contragent->country_id != Country::AUSTRIA
            && $contract->business_process_status_id == BusinessProcessStatus::TELEKOM_MAINTENANCE_WEB_SHOP
        ) {
            return 'EXPORT 0% VAT';
        }


//    ЕСЛИ(И(
//        C1484 = 'Magyarország';
//        ИЛИ(
//            G1484 <> 'Телеком-клиент / Webshop GmbH');
//        M1484 <> 0);
//    'HU MOSS';

        if ($contragent->country_id == Country::HUNGARY
            && (
                $contract->business_process_status_id != BusinessProcessStatus::TELEKOM_MAINTENANCE_WEB_SHOP
                || abs($invoice->sum_tax) > 0.001
            )
        ) {
            return 'HU MOSS';
        }

        /**
         *     ЕСЛИ(И(
         * C1484 = 'Deutschland';
         * G1484 = 'Телеком-клиент / Activated';
         * M1484 <> 0);
         * 'DE MOSS';
         */
        if (
            $contract->business_process_status_id == BusinessProcessStatus::TELEKOM_MAINTENANCE_WORK
            && abs($invoice->sum_tax) > 0.001
        ) {
//            ЛЕВ(T1484;2)='DE';
//        ЛЕВ(T1484;2)='HU';
//        ЛЕВ(T1484;2)='SK';
//        ЛЕВ(T1484;2)='ES';
//        ЛЕВ(T1484;2)='CZ';
//        ЛЕВ(T1484;2)='PL';
//        ЛЕВ(T1484;2)='RO';
//        ЛЕВ(T1484;2)='EE';
//        ЛЕВ(T1484;2)='LV';
//        ЛЕВ(T1484;2)='SI';
//        ЛЕВ(T1484;2)='PT';
//        ЛЕВ(T1484;2)='CY';
//        ЛЕВ(T1484;2)='FR';
//        ЛЕВ(T1484;2)='IE';
//        ЛЕВ(T1484;2)='NL'))

            switch ($contragent->country_id) {
                case Country::GERMANY:
                    return 'DE MOSS';
                case Country::SLOVAKIA:
                    return 'SK MOSS';
                case Country::SPAIN:
                    return 'ES MOSS';
                case Country::CZECH:
                    return 'CZ MOSS';
                case Country::POLAND:
                    return 'PL MOSS';
                case Country::ROMÂNIA:
                    return 'RO MOSS';
                case 233: // Estonia
                    return 'EE MOSS';
                case 620: // Poland
                    return 'PT MOSS';
                case 428: // Latvia
                    return 'LV MOSS';
                case 705: // Slovenia
                    return 'SI MOSS';
                case 196: // Cyprus
                    return 'CY MOSS';
                case 250: // France
                    return 'FR MOSS';
                case 372: // Ireland
                    return 'IE MOSS';
                case Country::NETHERLANDS:
                    return 'NL MOSS';
                case Country::UNITED_KINGDOM:
                    return 'GB MOSS';

                default:
                    return '??? MOSS';
            }
        }

        return '???';
    }

}