<?php

namespace app\modules\uu\dao;

use app\classes\helpers\DependecyHelper;
use app\classes\Singleton;
use app\controllers\api\internal\IdNameRecordTrait;
use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\document\PaymentTemplate;
use app\models\Number;
use app\models\UsageTrunk;
use app\modules\nnp\models\NumberRange;
use app\modules\nnp\models\PackageApi;
use app\modules\nnp\models\PackageMinute;
use app\modules\nnp\models\PackagePricelist;
use app\modules\nnp\models\PackagePricelistNnp;
use app\modules\sim\models\Imsi;
use app\modules\uu\filter\AccountTariffFilter;
use app\modules\uu\models\AccountLogPeriod;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\AccountTariffLog;
use app\modules\uu\models\AccountTariffResourceLog;
use app\modules\uu\models\ResourceModel;
use app\modules\uu\models\ServiceType;
use app\modules\uu\models\Tariff;
use app\modules\uu\models\TariffPeriod;
use app\modules\uu\models\TariffResource;
use yii\caching\TagDependency;
use yii\web\HttpException;

class AccountTariffStructureGenerator extends Singleton
{
    const DEFAULT_LIMIT = 50;
    const MAX_LIMIT = 100;

    use IdNameRecordTrait;

    public function getAccountTariffsWithPackages(
        $id = null,
        $client_account_id = null,
        $service_type_id = null,
        $voip_number = null,
        $voip_number_mask = null,
        $limit = self::DEFAULT_LIMIT,
        $offset = 0
    )
    {
        if (!$id && !$client_account_id && !$voip_number) {
            throw new HttpException(ModelValidationException::STATUS_CODE, 'Необходимо указать фильтр id или client_account_id или voip_number', AccountTariff::ERROR_CODE_ACCOUNT_EMPTY);
        }

        $limit = min($limit ?: self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $accountTariffQuery = AccountTariffFilter::getListWithPackagesQuery($id, $client_account_id, $service_type_id, $voip_number, $voip_number_mask, $limit, $offset);

        $result = [];
        foreach ($accountTariffQuery->all() as $accountTariff) {
            $result[] = $this->_getAccountTariffWithPackagesRecord($accountTariff);
        }

        return $result;
    }


    /**
     * Услуги
     *
     * @param AccountTariff $accountTariff
     * @return array
     * @throws \yii\base\Exception
     * @throws \yii\db\Exception
     */
    private function _getAccountTariffWithPackagesRecord($accountTariff)
    {
        $minutesStatistic = [];
        $priceMinutesStatistic = [];

        if ($accountTariff->service_type_id === ServiceType::ID_VOIP_PACKAGE_CALLS) {
            $minutesStatistic = $accountTariff->getMinuteStatistic();
            $priceMinutesStatistic = $accountTariff->getPriceMinuteStatistic();
        }

        $internetStatistic = [];
        if ($accountTariff->service_type_id == ServiceType::ID_VOIP_PACKAGE_INTERNET_ROAMABILITY) {
            $internetStatistic = $accountTariff->getInternetStatistic();
        }

        $smsStatistic = [];
        if ($accountTariff->service_type_id == ServiceType::ID_VOIP_PACKAGE_SMS) {
            $smsStatistic = $accountTariff->getSmsStatistic();
        }

        $number = $accountTariff->number;

        if ($number) {
            $isFmcEditable = $number->isMobileOutboundEditable();
            $isFmcActive = $number->isFmcAlwaysActive() || (!$number->isFmcAlwaysInactive() && $accountTariff->getResourceValue(ResourceModel::ID_VOIP_FMC));

            $isMobileOutboundEditable = $number->isMobileOutboundEditable();
            $isMobileOutboundActive = $number->isMobileOutboundAlwaysActive() || (!$number->isMobileOutboundAlwaysInactive() && $accountTariff->getResourceValue(ResourceModel::ID_VOIP_MOBILE_OUTBOUND));
        } else {
            $isFmcEditable = $isFmcActive = null;
            $isMobileOutboundEditable = $isMobileOutboundActive = null;
        }

        /** @var AccountTariffLog $firstTariff */
        $accountTariffLogs = $accountTariff->accountTariffLogs;
        $lastLog = end($accountTariffLogs);
        $isDefaultTariff = true;

        if ($lastLog && $lastLog->tariff_period_id) {
            $isDefaultTariff = $lastLog->tariffPeriod->tariff->is_default;
        }

        $record = [
            'id' => $accountTariff->id,
            'service_type' => $this->_getIdNameRecord($accountTariff->serviceType),
            'client_account_id' => $accountTariff->client_account_id,
            'region' => $this->_getIdNameRecord($accountTariff->region),
            'voip_number' => $accountTariff->voip_number,
            'voip_city' => $this->_getIdNameRecord($accountTariff->city),
            'beauty_level' => $number ? $number->beauty_level : null,
            'ndc' => $number ? $number->ndc : null,
            'ndc_type_id' => $number ? $number->ndc_type_id : null,
            'is_active' => $accountTariff->isActive(), // Действует ли?
            'is_package_addable' => $accountTariff->isPackageAddable(), // Можно ли подключить пакет?
            'is_cancelable' => $accountTariff->isLogCancelable(), // Можно ли отменить смену тарифа?
            'is_editable' => $accountTariff->isLogEditable(), // Можно ли сменить тариф или отключить услугу?
            'is_fmc_editable' => $isFmcEditable,
            'is_fmc_active' => $isFmcActive,
            'is_mobile_outbound_editable' => $isMobileOutboundEditable,
            'is_mobile_outbound_active' => $isMobileOutboundActive,
            'log' => $this->_getAccountTariffLogLightRecord($accountTariff->accountTariffLogs, $minutesStatistic, $internetStatistic, $priceMinutesStatistic, $smsStatistic),
            'resources' => $this->_getAccountTariffResourceLightRecord($accountTariff),
            'resources_hidden' => $this->_getAccountTariffResourceLightRecord($accountTariff, true),
            'default_actual_from' => $accountTariff->getDefaultActualFrom(),
            'packages' => [],
            'account_tariff_light_ids' => !$isDefaultTariff ? $this->_getAccountTariffLights($accountTariff->id) : [],
        ];

        if ($accountTariff->service_type_id == ServiceType::ID_ONE_TIME && $accountTariff->comment) {
            $record['comment'] = $accountTariff->comment;
        }

        if ($accountTariff->service_type_id == ServiceType::ID_VOIP) {
            $lines = $accountTariff->getResourceValue(ResourceModel::ID_VOIP_LINE);
            $hasTrunkService = UsageTrunk::dao()->hasService($accountTariff->client_account_id);
            $record['voip_number_info'] =  array_intersect_key(array_merge(Number::getNnpInfo($accountTariff->voip_number), NumberRange::getNumberInfo($accountTariff->number)), array_flip([
                'country_code', 'country_name', 'country_prefix', 'is_mob', 'ndc', 'ndc_type_id', 'nnp_city_id', 'city_name', 'nnp_operator_id', 'operator_name', 'nnp_region_id', 'region_name', 'number_length'
            ]));
            $record['is_sip_accounts_enabled'] = (($lines == 0 || $hasTrunkService || AccountTariff::hasTrunk($accountTariff->client_account_id)) ? 0 : 1);
        }

        if ($accountTariff->service_type_id == ServiceType::ID_ESIM) {
            $record['data'] =['sim' => $this->_getRecordSimSection($accountTariff)];
        }

        $packages = $accountTariff->nextAccountTariffsEager;
        if ($packages) {
            $record['packages'] = [];
            foreach ($packages as $package) {
                $record['packages'][] = $this->_getAccountTariffWithPackagesRecord($package);
            }
        }

        return $record;
    }


    /**
     * @param AccountTariffLog[] $models
     * @param array $minutesStatistic
     * @return array
     */
    private function _getAccountTariffLogLightRecord($models, $minutesStatistic = [], $internetStatistic = [], $priceMinutesStatistic = [], $smsStatistic = [])
    {
        $result = [];

        $modelLast = array_shift($models);
        if (!$modelLast) {
            return $result;
        }

        $modelPrev = array_shift($models);
        $modelFirst = array_pop($models);
        !$modelFirst && $modelFirst = $modelPrev;
        !$modelFirst && $modelFirst = $modelLast;

        $isCancelable = $modelLast->actual_from_utc > date(DateTimeZoneHelper::DATETIME_FORMAT);

        if ($modelLast->tariff_period_id) {

            // действующий
            if ($isCancelable) {

                // смена тарифа в будущем
                if ($modelPrev) {
                    // текущий тариф
                    $result[] = [
                        'tariff' => $this->_getTariffRecord($modelPrev->tariffPeriod->tariff, $modelPrev->tariffPeriod, $minutesStatistic, $internetStatistic, $priceMinutesStatistic, $smsStatistic),
                        'activate_initial_date' => $modelFirst->actual_from,
                        'activate_past_date' => $modelPrev->actual_from,
                        'activate_future_date' => null,
                        'deactivate_past_date' => null,
                        'deactivate_future_date' => null,
                        'is_cancelable' => false, // Можно ли отменить смену тарифа?
                        'is_editable' => false, // Можно ли сменить тариф или отключить услугу?
                    ];
                }

                // будущий
                $result[] = [
                    'tariff' => $this->_getTariffRecord($modelLast->tariffPeriod->tariff, $modelLast->tariffPeriod),
                    'activate_initial_date' => $modelFirst->actual_from,
                    'activate_past_date' => null,
                    'activate_future_date' => $modelLast->actual_from,
                    'deactivate_past_date' => null,
                    'deactivate_future_date' => null,
                    'is_cancelable' => true, // Можно ли отменить смену тарифа?
                    'is_editable' => false, // Можно ли сменить тариф или отключить услугу?
                ];

            } else {

                // смена тарифа в прошлом
                $result[] = [
                    'tariff' => $this->_getTariffRecord($modelLast->tariffPeriod->tariff, $modelLast->tariffPeriod, $minutesStatistic, $internetStatistic, $priceMinutesStatistic, $smsStatistic),
                    'activate_initial_date' => $modelFirst->actual_from,
                    'activate_past_date' => $modelLast->actual_from,
                    'activate_future_date' => null,
                    'deactivate_past_date' => null,
                    'deactivate_future_date' => null,
                    'is_cancelable' => false, // Можно ли отменить смену тарифа?
                    'is_editable' => true, // Можно ли сменить тариф или отключить услугу?
                ];

            }
        } else {

            // закрытый
            if ($isCancelable) {

                // закрытие тарифа в будущем
                $result[] = [
                    'tariff' => $this->_getTariffRecord($modelPrev->tariffPeriod->tariff, $modelPrev->tariffPeriod),
                    'activate_initial_date' => $modelFirst->actual_from,
                    'activate_past_date' => $modelPrev->actual_from,
                    'activate_future_date' => null,
                    'deactivate_past_date' => null,
                    'deactivate_future_date' => $modelLast->actual_from,
                    'is_cancelable' => true, // Можно ли отменить смену тарифа?
                    'is_editable' => false, // Можно ли сменить тариф или отключить услугу?
                ];

            } else {

                // закрытие тарифа в прошлом
                $result[] = [
                    'tariff' => $this->_getTariffRecord($modelPrev->tariffPeriod->tariff, $modelPrev->tariffPeriod),
                    'activate_initial_date' => $modelFirst->actual_from,
                    'activate_past_date' => null,
                    'activate_future_date' => null,
                    'deactivate_past_date' => $modelLast->actual_from,
                    'deactivate_future_date' => null,
                    'is_cancelable' => false, // Можно ли отменить смену тарифа?
                    'is_editable' => false, // Можно ли сменить тариф или отключить услугу?
                ];

            }
        }

        return $result;
    }


    /**
     * @param Tariff $tariff
     * @param TariffPeriod|TariffPeriod[] $tariffPeriod
     * @param array $minutesStatistic
     * @param array $internetStatistic
     * @param array $smsStatistic
     * @return array
     */
    public function _getTariffRecord($tariff, $tariffPeriod, $minutesStatistic = [], $internetStatistic = [], $priceMinutesStatistic = [], $smsStatistic = [])
    {
        if (!$tariff || !$tariffPeriod) {
            return null;
        }

        if (!($data = \Yii::$app->cache->get($tariff->cacheKey))) {
//        if (true) {

            $package = $tariff->package;
            $tariffVoipCountries = $tariff->tariffVoipCountries;
            $tariffVoipCountry = reset($tariffVoipCountries);
            $tariffCountries = $tariff->tariffCountries;

            $data = [
                'id' => $tariff->id,
                'name' => $tariff->name,
                'count_of_validity_period' => $tariff->count_of_validity_period,
                'is_autoprolongation' => $tariff->is_autoprolongation,
                'is_charge_after_blocking' => $tariff->is_charge_after_blocking,
                'is_include_vat' => $tariff->is_include_vat,
                'is_default' => $tariff->is_default,
                'is_one_active' => $tariff->is_one_active,
                'currency' => $tariff->currency_id,
                'service_type' => $this->_getIdNameRecord($tariff->serviceType),
                'country' => $this->_getIdNameRecord($tariffVoipCountry ? $tariffVoipCountry->country : null, 'code'), // @todo multi и переименовать в voip_countries
                'countries' => $this->_getIdNameRecord($tariffCountries, 'country_id'),
                'voip_countries' => $this->_getIdNameRecord($tariffVoipCountries, 'country_id'),
                'tariff_status' => $this->_getIdNameRecord($tariff->status),
                'tariff_person' => $this->_getIdNameRecord($tariff->person),
                'tariff_tag' => $this->_getIdNameRecord($tariff->tag),
                'tariff_tags' => $this->_getIdNameRecord($tariff->tariffTags, 'tag_id'),
                'tariff_resources' => $this->_getTariffResourceRecord($tariff->tariffResources),
                'tariff_periods' => null, //$this->_getTariffPeriodRecord($tariffPeriod),
                'is_termination' => $package ? $package->is_termination : null,
                'tarification_free_seconds' => $package ? $package->tarification_free_seconds : null,
                'tarification_interval_seconds' => $package ? $package->tarification_interval_seconds : null,
                'tarification_type' => $package ? $package->tarification_type : null,
                'tarification_min_paid_seconds' => $package ? $package->tarification_min_paid_seconds : null,
                'voip_group' => $this->_getIdNameRecord($tariff->voipGroup),
                'voip_cities' => $this->_getIdNameRecord($tariff->voipCities, 'city_id'),
                'voip_ndc_types' => $this->_getIdNameRecord($tariff->voipNdcTypes, 'ndc_type_id'),
                'voip_sources' => $this->_getIdNameRecord($tariff->voipSources, 'source_code'),
                'organizations' => $this->_getIdNameRecord($tariff->organizations, 'organization_id'),
                'api_package_price' => $tariff->service_type_id == ServiceType::ID_BILLING_API_MAIN_PACKAGE ? $this->_getApiPackagePrice($tariff->packageApi) : null,
                'voip_package_pricelist' => $tariff->service_type_id == ServiceType::ID_VOIP_PACKAGE_CALLS ? $this->_getVoipPackagePricelistRecord($tariff->packagePricelists, ['pricelist' => 'pricelist']) : null, //package_pricelist
                'voip_package_price_internet' => $tariff->service_type_id == ServiceType::ID_VOIP_PACKAGE_INTERNET_ROAMABILITY ? $this->_getVoipPackagePriceV2Record($tariff->packagePricelistsNnpInternet, ['bytes_amount' => 'bytes_amount']) : null, // package_data
                'voip_package_price_sms' => in_array($tariff->service_type_id, [ServiceType::ID_VOIP_PACKAGE_SMS, ServiceType::ID_A2P_PACKAGE]) ? $this->_getVoipPackagePriceV2Record($tariff->packagePricelistsNnpSms, ['include_amount' => 'amount']) : null, // package_sms
                'voip_package_price_minute' => $tariff->service_type_id == ServiceType::ID_VOIP_PACKAGE_CALLS ? $this->_getVoipPackagePriceV2Record($tariff->packagePricelistsNnp, ['minute' => 'minute']) : null,
                'voip_package_minute' => null,
//                'package_pricelist' => null,
            ];

            if ($tariff->payment_template_type_id && $tariffCountries) {
                $tariffCountry = reset($tariffCountries);

                $template = PaymentTemplate::getDefaultByTypeIdAndCountryCode($tariff->payment_template_type_id, $tariffCountry->country_id);
                $data['template'] = $template ? $template->getAttributes(['id', 'content']) : null;
            }

            $data['overview'] = $this->_getOverview($tariff->overview);

            \Yii::$app->cache->set($tariff->cacheKey, $data, DependecyHelper::DEFAULT_TIMELIFE, (new TagDependency(['tags' => [DependecyHelper::TAG_PRICELIST]])));
        }

        $data['tariff_periods'] = $this->_getTariffPeriodRecord($tariffPeriod);
        $data['voip_package_minute'] = $tariff->service_type_id == ServiceType::ID_VOIP_PACKAGE_CALLS ? $this->_getVoipPackageMinuteRecord($tariff->packageMinutes, $minutesStatistic) : null;

        if ($tariff->service_type_id == ServiceType::ID_VOIP_PACKAGE_INTERNET_ROAMABILITY && $internetStatistic) {
            if (!isset($data['voip_package_price_internet'][0])) {
                $data['voip_package_price_internet'][0] = [];
            }
            $data['voip_package_price_internet'][0] += [
                'bytes_amount_package' => $internetStatistic['bytes_amount'],
                'bytes_consumed' => $internetStatistic['bytes_consumed']
            ];
        }

        if ($tariff->service_type_id == ServiceType::ID_VOIP_PACKAGE_CALLS && $priceMinutesStatistic) {
            if (!isset($data['voip_package_price_minute'][0])) {
                $data['voip_package_price_minute'][0] = [];
            }
            $data['voip_package_price_minute'][0] += $priceMinutesStatistic;
        }

        if ($tariff->service_type_id == ServiceType::ID_VOIP_PACKAGE_SMS && $smsStatistic) {
            if (!isset($data['voip_package_price_sms'][0])) {
                $data['voip_package_price_sms'][0] = [];
            }
            $data['voip_package_price_sms'][0] += $smsStatistic;
        }

//        foreach(['voip_package_pricelist', 'voip_package_price_internet', 'voip_package_price_sms', 'voip_package_minute', 'voip_package_price_minute', 'api_package_price'] as $price) {
//            if (isset($data[$price]) && $data[$price]) {
//                $data['package_pricelist'] = $data[$price];
//                break;
//            }
//        }

        return $data;
    }



    /**
     * @param TariffResource|TariffResource[] $model
     * @return array|null
     */
    private function _getTariffResourceRecord($model)
    {
        if (is_array($model)) {
            $result = [];
            foreach ($model as $subModel) {
                $result[] = $this->_getTariffResourceRecord($subModel);
            }

            return $result;
        }

        if ($model) {
            $isCheckable = !$model->resource->isNumber();
            $value = [
                'id' => $model->id,
                'is_show_resource' => (bool)$model->is_show_resource,
                'is_checkable' => $isCheckable,
                'is_editable' => $model->resource->isOption() && $model->is_can_manage,
                'is_checked' => $isCheckable ? (bool)$model->amount : null,
                'amount' => $isCheckable ? null : $model->amount,
                'price_per_unit' => $model->price_per_unit,
                'price_min' => $model->price_min,
                'resource' => $this->_getResourceRecord($model->resource),
            ];

            return $value;
        }

        return null;
    }


    private function _getOverview($overview)
    {
        $toRet = [];
        $lines = explode("\n", $overview);

        foreach ($lines as $line) {
            $line = trim(str_replace("\r", '', $line));

            if (!$line) {
                continue;
            }

            $pos = false;
            $beforePos = $line;
            $afterPos = '';
            $column = false;

            if ($pos = strpos($line, '=')) {
                $type = 'price';
                $column = 'price';
            } elseif ($pos = strpos($line, '#')) {
                $type = 'pricelist_link';
                $column = 'pricelist_id';
            } elseif ($pos = strpos($line, '|')) {
                $type = 'link';
                $column = 'link';
            } else {
                $type = 'text';
            }

            if ($pos) {
                $beforePos = trim(substr($line, 0, $pos));
                $afterPos = trim(substr($line, $pos + 1, strlen($line)));
            }

            $json = [
                    'type' => $type,
                    'text' => $beforePos,
                ] + ($column ? [$column => $afterPos] : []);

            $toRet[] = $json;
        }
        return $toRet;
    }


    /**
     * @param AccountTariff $accountTariff
     * @return array
     */
    private function _getAccountTariffResourceLightRecord($accountTariff, $onlyHidden = false)
    {
        $accountTariffResourceRecords = [];

        $tariffPeriod = $accountTariff->tariffPeriod;
        $tariff = $tariffPeriod ? $tariffPeriod->tariff : null;
        $tariffResourcesIndexedByResourceId = $tariff ? $tariff->tariffResourcesIndexedByResourceId : [];

        foreach ($accountTariff->serviceType->resources as $resource) {
            $tariffResource = $tariffResourcesIndexedByResourceId[$resource->id] ?? null;

            if (!$tariffResource) {
                continue;
            }

            if ($onlyHidden && $tariffResource->is_show_resource) {
                continue;
            }

            if (!$onlyHidden && !$tariffResource->is_show_resource) {
                continue;
            }

            $accountTariffResourceLogs = [];
            foreach ($accountTariff->accountTariffResourceLogsAll as $accountTariffResourceLog) {
                if ($accountTariffResourceLog->resource_id == $resource->id) {
                    $accountTariffResourceLogs[] = $accountTariffResourceLog;
                }
            }

            $accountTariffResourceRecords[] = [
                'resource' => $this->_getResourceRecord($resource),
                'free_amount' => $tariffResource ? $tariffResource->amount : null,
                'price_per_unit' => $tariffResource ? $tariffResource->price_per_unit : null,
                'price_min' => $tariffResource ? $tariffResource->price_min : null,
                'log' => $this->_getAccountTariffResourceLogLightRecord($accountTariffResourceLogs, $tariffResourcesIndexedByResourceId[$resource->id]->is_can_manage),
            ];
        }

        return $accountTariffResourceRecords;
    }


    public function _getAccountTariffLights($accountTariffId)
    {
        return AccountLogPeriod::find()
            ->where(['account_tariff_id' => $accountTariffId])
            ->select(['id', 'date_from', 'date_to'])
            ->orderBy(['date_from' => SORT_ASC])
            ->asArray()
            ->all();
    }


    /**
     * @param PackageApi[]|PackageApi $packageApis
     * @return array
     */
    private function _getApiPackagePrice($packageApis)
    {
        if (!$packageApis) {
            return null;
        }

        if (is_array($packageApis)) {
            $result = [];

            foreach ($packageApis as $packageApi) {
                $result[] = $this->_getApiPackagePrice($packageApi);
            }

            return $result;
        }

        /** @var $packageApis PackageApi */

        return [
            'id' => $packageApis->api_pricelist_id,
            'name' => (string)$packageApis->pricelistApi,
        ];

    }


    /**
     * @param PackagePricelist|PackagePricelist[] $packagePricelists
     * @return array
     */
    private function _getVoipPackagePricelistRecord($packagePricelists, $addField = [])
    {
        if (!$packagePricelists) {
            return null;
        }

        if (is_array($packagePricelists)) {
            $result = [];
            foreach ($packagePricelists as $packagePricelist) {
                $result[] = $this->_getVoipPackagePricelistRecord($packagePricelist, $addField);
            }

            return $result;
        }

        $result = [];
        foreach ($addField as $k => $v) {
            $result[$v] = (string)$packagePricelists->$k;
        }
        $result['minute'] = $packagePricelists->minute;

        return $result;
    }


    /**
     * @param PackagePricelistNnp[] $packagePriceLists
     * @param bool $isNames - только имена и id прайс-листов
     * @return array
     */
    private function _getVoipPackagePriceV2Record($packagePriceLists, $addField = [])
    {
        if (!$packagePriceLists) {
            return null;
        }

        $result = [];
        foreach ($packagePriceLists as $packagePriceList) {
            $row = [];
            foreach (['id' => 'id', 'name' => 'name'] as $k => $v) {
                if (!$packagePriceList->pricelistNnp) {
                    continue;
                }
                $row[$v] = $packagePriceList->pricelistNnp->$k;
            }
            foreach ($addField as $k => $v) {
                $row[$v] = $packagePriceList->$k;
            }
            $result[] = $row;
        }

        return $result;
    }


    /**
     * @param TariffPeriod|TariffPeriod[] $model
     * @return array|null
     */
    private function _getTariffPeriodRecord($model)
    {
        if (is_array($model)) {
            $result = [];
            foreach ($model as $subModel) {
                $result[] = $this->_getTariffPeriodRecord($subModel);
            }

            return $result;
        }

        if ($model) {
            return [
                'id' => $model->id,
                'price_setup' => $model->price_setup,
                'price_per_period' => $model->price_per_period,
                'price_per_charge_period' => round($model->price_per_period * ($model->chargePeriod->monthscount ?: 1 / 30), 2),
                'price_min' => $model->price_min,
                'charge_period' => $this->_getIdNameRecord($model->chargePeriod),
            ];
        }

        return null;
    }


    /**
     * @param PackageMinute|PackageMinute[] $packageMinutes
     * @param array $minutesStatistic
     * @return array
     */
    private function _getVoipPackageMinuteRecord($packageMinutes, $minutesStatistic = [])
    {
        if (!$packageMinutes) {
            return null;
        }

        if (is_array($packageMinutes)) {
            $result = [];
            foreach ($packageMinutes as $packageMinute) {
                $result[] = $this->_getVoipPackageMinuteRecord($packageMinute, $minutesStatistic);
            }

            return $result;
        }

        $minuteStatistic = null;
        foreach ($minutesStatistic as $minuteStatisticTmp) {
            if ($minuteStatisticTmp['i_nnp_package_minute_id'] == $packageMinutes->id) {
                $minuteStatistic = $minuteStatisticTmp['i_used_seconds'];
                break;
            }
        }

        return [
            'destination' => (string)$packageMinutes->destination,
            'minute' => $packageMinutes->minute,
            'spent_seconds' => $minuteStatistic,
        ];
    }


    /**
     * @param ResourceModel $model
     * @return array
     */
    private function _getResourceRecord($model)
    {
        if (!$model) {
            return [];
        }

        return [
            'id' => $model->id,
            'name' => $model->name,
            'unit' => $model->unit,
            'is_number' => $model->isNumber(),
            'min_value' => $model->min_value,
            'max_value' => $model->max_value,
            'is_option' => $model->isOption(),
            'service_type' => $this->_getIdNameRecord($model->serviceType),
        ];
    }


    /**
     * @param AccountTariffResourceLog[] $models
     * @param bool $isCanManage
     * @return array
     */
    private function _getAccountTariffResourceLogLightRecord($models, $isCanManage = false)
    {
        $result = [];

        $modelLast = array_shift($models);
        if (!$modelLast) {
            return $result;
        }

        $modelPrev = array_shift($models);

        $isCancelable = $modelLast->actual_from > date(DateTimeZoneHelper::DATE_FORMAT);
        if ($isCancelable) {

            // смена количества ресурса в будущем
            if ($modelPrev) {
                // текущее значение количества ресурса
                $result[] = [
                    'amount' => $modelPrev->amount,
                    'activate_past_date' => $modelPrev->actual_from,
                    'activate_future_date' => null,
                    'is_cancelable' => false,
                    'is_editable' => false,
                ];
            }

            // будущее значение количества ресурса
            $result[] = [
                'amount' => $modelLast->amount,
                'activate_past_date' => null,
                'activate_future_date' => $modelLast->actual_from,
                'is_cancelable' => (bool)$modelPrev, // если есть на что отменять
                'is_editable' => false,
            ];

        } else {

            // смена количества ресурса в прошлом
            $result[] = [
                'amount' => $modelLast->amount,
                'activate_past_date' => $modelLast->actual_from,
                'activate_future_date' => null,
                'is_cancelable' => false,
                'is_editable' => (bool)$isCanManage,
            ];

        }

        return $result;
    }

    private function _getRecordSimSection(AccountTariff $accountTariff)
    {
        if (!$accountTariff->iccid) {
            return null;
        }

        $result = [
            'card' => [
                'iccid' => (string)$accountTariff->iccid,
            ]
        ];

        if (!$accountTariff->iccidModel) {
            return $result;
        }

        $result['card']['type'] = $this->_getIdNameRecord($accountTariff->iccidModel->type);
        $result['card']['imsies'] = array_map(function (Imsi $imsi) {
            $data = $imsi->getAttributes(['imsi', 'is_default']);
            if ($imsi->token) {
                $data['token'] = $imsi->token->token;
            }
            if ($imsi->profile) {
                $data['profile'] = $this->_getIdNameRecord($imsi->profile);
            }

            if ($imsi->status) {
                $data['status'] = $this->_getIdNameRecord($imsi->status);
            }

            return $data;
        }, $accountTariff->iccidModel->imsies);

        return $result;
    }

}
