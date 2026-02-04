<?php

namespace app\dao;

use app\classes\Singleton;
use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\Business;
use app\models\BusinessProcessStatus;
use app\models\ClientAccount;
use app\models\ClientContract;
use app\models\ClientContragent;
use app\models\ClientDocument;
use app\models\InvoiceSettings;
use app\models\Organization;
use app\models\OrganizationSettlementAccount;
use app\models\TaxVoipSettings;
use Yii;
use yii\db\Query;

/**
 * @method static ClientContractDao me()
 */
class ClientContractDao extends Singleton
{
    private $_isOrganizationValue = false;

    public $settlementAccountTypeId = OrganizationSettlementAccount::SETTLEMENT_ACCOUNT_TYPE_RUSSIA;

    /**
     * Получить транковые контракты с типом контракта в скобках
     *
     * @param array $params
     * @param bool $isWithEmpty
     *
     * @return string[]
     */
    public function getListWithType(array $params = [], $isWithEmpty = false)
    {
        $query = (new Query)
            ->select(
                [
                    'name' => "COALESCE(st.contract_number || ' (' || cct.name || ')', st.contract_number)",
                    'id' => 'st.contract_id',
                ]
            )
            ->from('billing.service_trunk AS st')
            ->leftJoin('stat.client_contract_type AS cct', 'cct.id = st.contract_type_id')
            ->orderBy('name DESC');

        if (isset($params['serverIds']) && $params['serverIds']) {
            $query->andWhere(['st.server_id' => $params['serverIds']]);
        }

        if (isset($params['serviceTrunkIds']) && $params['serviceTrunkIds']) {
            $query->andWhere(['st.id' => $params['serviceTrunkIds']]);
        }

        if (isset($params['trunkIds']) && $params['trunkIds']) {
            $query->andWhere(['st.trunk_id' => $params['trunkIds']]);
        }

        $list = $query->indexBy('id')->column(Yii::$app->dbPgSlave);

        if ($isWithEmpty) {
            $list = ['' => '----'] + $list;
        }

        return $list;
    }

    /**
     * Получить партнеров
     *
     * @param bool $isWithEmpty
     *
     * @return string[]
     */
    public function getPartnerList($isWithEmpty = true)
    {
        $list = [];
        $cts = ClientContract::find()->andWhere(['business_id' => Business::PARTNER])->with('clientContragent')->all();
        /** @var ClientContract $ct */
        foreach ($cts as $ct) {
            $list[$ct->id] = $ct->clientContragent->name . ' (' . $ct->number . ($ct->number != (string)$ct->id ? ', #' . $ct->id : '') . ')';
        }

        if ($isWithEmpty) {
            $list = ['' => '----'] + $list;
        }

        return $list;
    }

    /**
     * @param ClientContract $contract
     * @param \DateTime|null $dateOrig
     * @return ClientDocument|null
     * @throws \Exception
     * @internal param \DateTime|null $date
     */
    public function getContractInfo(ClientContract $contract, \DateTime $dateOrig = null)
    {
        if (!$dateOrig) {
            $date = new \DateTime('now', new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_DEFAULT));
        } else {
            $date = $dateOrig;
        }

        $contractDoc = ClientDocument::find()
            ->active()
            ->contract()
            ->andWhere(['contract_id' => $contract->id])
            ->andWhere(['<=', 'contract_date', $date->format(DateTimeZoneHelper::DATETIME_FORMAT)])
            ->last();

        if (!$contractDoc) {
            $contractDoc = ClientDocument::find()
                ->contract()
                ->andWhere(['contract_id' => $contract->id])
                ->andWhere(['<=', 'contract_date', $date->format(DateTimeZoneHelper::DATETIME_FORMAT)])
                ->last();
        }

        if (!$contractDoc) {
            $contractDoc = ClientDocument::find()
                ->contract()
                ->andWhere(['contract_id' => $contract->id])
                ->last();
        }

        if (!$contractDoc) {
            $contractDoc = new ClientDocument;
            $contractDoc->contract_no = 'б/н';
            $contractDoc->contract_date = (new \DateTime($contract->offer_date ?: $contract->created_at))->format(DateTimeZoneHelper::DATE_FORMAT);
        }

        return $contractDoc;
    }

    /**
     * Обновить эффективную ставку НДС во всех договорах
     *
     * @param bool $isWithTrace
     * @return array
     */
    public function resetAllEffectiveVATRate($isWithTrace = true)
    {
        $countAll = $countSet = $countFromOrganization = $iteration = 0;

        $contractQuery = ClientContract::find();

        echo PHP_EOL . date("r") . PHP_EOL . PHP_EOL;

        $transaction = Yii::$app->db->beginTransaction();

        /** @var ClientContract $contract */
        foreach ($contractQuery->each() as $contract) {
            $info = $contract->resetEffectiveVATRate($isWithTrace);
            $countAll += $info['countAll'];
            $countSet += $info['countSet'];
            $countFromOrganization += $info['countFromOrganization'];

            if ($iteration++ % 100 == 0) {
                echo "\r" . date("r") . ": iteration: " . $iteration . "; all: " . $countAll . "; set: " . $countSet . "; from organization: " . $countFromOrganization;
            }

            if ($iteration % 100 == 0) {
                $transaction->commit();
                $transaction = Yii::$app->db->beginTransaction();
            }
        }

        $transaction->commit();

        return [
            'countAll' => $countAll,
            'countSet' => $countSet,
            'countFromOrganization' => $countFromOrganization
        ];

    }

    /**
     * Рассчитывает необходимость использования тарифов с НДС или без НДС
     *
     * @param ClientContract $contract
     * @param ClientContragent $contragent
     */
    public function resetTaxVoip(ClientContract $contract, ClientContragent $contragent)
    {
        $isVoipWithTax = $this->getVoipWithTax($contract, $contragent);

        if ($contract->is_voip_with_tax != $isVoipWithTax) {
            $contract->is_voip_with_tax = $isVoipWithTax;
            $contract->isSetVoipWithTax = $isVoipWithTax;
        }

        // no save
    }

    /**
     * @param ClientContract $contract
     * @param ClientContragent $contragent
     * @return int
     */
    public function getVoipWithTax(ClientContract $contract, ClientContragent $contragent)
    {
        static $cache = [];

        if (!$cache) {
            /** @var TaxVoipSettings $item */
            foreach (TaxVoipSettings::find()->all() as $item) {
                $cache[$item->business_id][$item->country_id] = $item->is_with_tax;
            }
        }

        if (isset($cache[$contract->business_id][$contragent->country_id])) {
            return $cache[$contract->business_id][$contragent->country_id];
        }

        return 0;
    }

    /**
     * Устанавливаем эффективную ставку НДС на подчиненные ЛС
     *
     * @param ClientContract $contract
     * @param bool $isWithTrace
     * @return array
     * @throws ModelValidationException
     */
    public function resetEffectiveVATRate(ClientContract $contract, $isWithTrace = true)
    {
        $countAll = $countSet = $countFromOrganization = 0;

        $contract->refresh();

        $vatRate = $this->getEffectiveVATRate($contract);

        if (!$isWithTrace) {
            $contract->detachBehaviors();
            $contract->isHistoryVersioning = false;
        }

        /** @var ClientAccount $account */
        foreach ($contract->accounts as $account) {

            $countAll++;

            if ($account->effective_vat_rate == $vatRate) {
                continue;
            }

            $account->effective_vat_rate = $vatRate;

            if (!$isWithTrace) {
                $account->detachBehaviors();
                $account->isHistoryVersioning = false;
            }

            if (!$account->save()) {
                throw new ModelValidationException($account);
            }

            $countSet++;

            if ($this->_isOrganizationValue) {
                $countFromOrganization++;
            }
        }

        return [
            'countAll' => $countAll,
            'countSet' => $countSet,
            'countFromOrganization' => $countFromOrganization
        ];
    }

    /**
     * Рассчитывает эффективную ставку НДС для данного договора
     *
     * @param ClientContract $contract
     * @param \DateTime|\DateTimeImmutable|string|null $date
     * @return int
     */
    public function getEffectiveVATRate(ClientContract $contract, $date = null)
    {
        if ($date && ($date instanceof \DateTime || $date instanceof \DateTimeImmutable)) {
            $date = $date->format(DateTimeZoneHelper::DATE_FORMAT);
        }

        !$date && $date = date(DateTimeZoneHelper::DATE_FORMAT);

        // специальная папка "Госники - 20% НДС"
        if ($contract->business_process_status_id == BusinessProcessStatus::TELEKOM_MAINTENANCE_GOVERNMENT_AGENCIES) {
            return $this->_correctTaxRateRussia($date);
        }

        $rate = $this->_getEffectiveVATRate($contract);

        if ($rate != 20) { // 20% базовая для России
            return $rate;
        }

        if (\Yii::$app->isRus()) {
            return $this->_correctTaxRateRussia($date);
        }

        return $rate; // 20...
    }

    /**
     * Корректируем ставку по времени
     *
     * @param $date
     * @return int
     */
    private function _correctTaxRateRussia($date): int
    {
        if ($date >= '2026-01-01') {
            return 22;
        }

        if ($date >= '2019-01-01') {
            return 20;
        }

        return 18;
    }

    private function _getEffectiveVATRate(ClientContract $contract)
    {
        static $cash = [];

        if (!$cash) {
            /** @var InvoiceSettings $settings */
            foreach (InvoiceSettings::find()->all() as $settings) {
                $cash[$settings->doer_organization_id][$settings->customer_country_code ?: 'any'][$settings->vat_apply_scheme] = [
                    'vat_rate' => $settings->vat_rate,
                    'account_type_id' => $settings->settlement_account_type_id,
                ];
            }
        }

        $this->_isOrganizationValue = false;
        $this->settlementAccountTypeId = OrganizationSettlementAccount::SETTLEMENT_ACCOUNT_TYPE_RUSSIA;
        $organizationId = $contract->organization_id;
        $countryId = $contract->contragent->country_id;

        $countrySettings = null;
        if (isset($cash[$organizationId][$countryId])) { // настройки компания+страна
            $countrySettings = $cash[$organizationId][$countryId];
        } elseif (isset($cash[$organizationId]['any'])) { // настройки компания+любая страна
            $countrySettings = $cash[$organizationId]['any'];
        } else {
            Yii::warning('[contract_vat_not_found] Не найдена эффективная ставка НДС для договора ' . $contract->id . '. Нет настроек страны для организации id:' . $organizationId);
            $this->_isOrganizationValue = true;
            return $this->getOrganizationVATRateByContract($contract);
        }

        if ($contract->contragent->tax_regime == ClientContragent::TAX_REGTIME_YCH_VAT0 && isset($countrySettings[InvoiceSettings::VAT_SCHEME_NONVAT])) {
            $cacheValue = $countrySettings[InvoiceSettings::VAT_SCHEME_NONVAT];
            $this->settlementAccountTypeId = $cacheValue['account_type_id'];
            return $cacheValue['vat_rate'];
        } elseif ($contract->contragent->tax_regime == ClientContragent::TAX_REGTIME_OCH_VAT18 && isset($countrySettings[InvoiceSettings::VAT_SCHEME_VAT])) {
            $cacheValue = $countrySettings[InvoiceSettings::VAT_SCHEME_VAT];
            $this->settlementAccountTypeId = $cacheValue['account_type_id'];
            return $cacheValue['vat_rate'];
        } elseif (isset($countrySettings[InvoiceSettings::VAT_SCHEME_ANY])) {
            $cacheValue = $countrySettings[InvoiceSettings::VAT_SCHEME_ANY];
            $this->settlementAccountTypeId = $cacheValue['account_type_id'];
            return $cacheValue['vat_rate'];
        }

        Yii::warning('[contract_vat_not_found] Не найдена эффективная ставка НДС для договора ' . $contract->id . '. Нет настроек по режиму.');
        $this->_isOrganizationValue = true;
        return $this->getOrganizationVATRateByContract($contract);
    }

    /**
     * Получение ставки НДС в организации по договору
     *
     * @param ClientContract $contract
     * @return int
     */
    public function getOrganizationVATRateByContract(ClientContract $contract)
    {
        static $cash = [];

        if (!array_key_exists($contract->organization_id, $cash)) {
            $organization = Organization::find()->byId($contract->organization_id)->actual()->one();

            $cash[$contract->organization_id] = $organization ? $organization->vat_rate : null;
        }

        if ($cash[$contract->organization_id] === null) {
            throw new \LogicException(sprintf('[contract_organization_not_found] Не найдена организация (%d) в договоре %d', $contract->organization_id, $contract->id));
        }

        return $cash[$contract->organization_id];
    }
}
