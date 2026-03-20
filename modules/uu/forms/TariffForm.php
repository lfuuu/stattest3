<?php

namespace app\modules\uu\forms;

use app\exceptions\ModelValidationException;
use app\modules\nnp\models\Package;
use app\modules\nnp\models\PackageApi;
use app\modules\nnp\models\PackageMinute;
use app\modules\nnp\models\PackagePrice;
use app\modules\nnp\models\PackagePricelist;
use app\modules\nnp\models\PackagePricelistNnp;
use app\modules\nnp\models\PackagePricelistNnpInternet;
use app\modules\nnp\models\PackagePricelistNnpSms;
use app\modules\uu\models\Period;
use app\modules\uu\models\ServiceType;
use app\modules\uu\models\Tariff;
use app\modules\uu\models\TariffBundle;
use app\modules\uu\models\TariffCountry;
use app\modules\uu\models\TariffOrganization;
use app\modules\uu\models\TariffPeriod;
use app\modules\uu\models\TariffResource;
use app\modules\uu\models\TariffTags;
use app\modules\uu\models\TariffVoipCity;
use app\modules\uu\models\TariffVoipCountry;
use app\modules\uu\models\TariffVoipNdcType;
use app\modules\uu\models\TariffVoipSource;
use InvalidArgumentException;

abstract class TariffForm extends \app\classes\Form
{

    /** @var Tariff */
    public $tariff;

    /** @var TariffPeriod[] */
    public $tariffPeriods;

    /** @var TariffResource[] */
    public $tariffResources;

    /** @var TariffVoipCity[] */
    public $tariffVoipCities;

    /** @var TariffCountry[] */
    public $tariffCountries;

    /** @var TariffCountry[] */
    public $tariffVoipCountries;

    /** @var TariffVoipNdcType[] */
    public $tariffNdcTypes;

    /** @var TariffVoipSource[] */
    public $tariffSource;

    /** @var TariffOrganization[] */
    public $tariffOrganizations;

    /** @var TariffBundle[] */
    public $tariffBundles;

    /** @var TariffTags[] */
    public $tariffTags;

    /** @var PackageApi[] */
    public $packageApi;

    /**
     * @return TariffResource[]
     */
    abstract public function getTariffResources();

    /**
     * @return TariffPeriod[]
     */
    abstract public function getTariffPeriods();

    /**
     * @return TariffVoipCity[]
     */
    abstract public function getTariffVoipCities();

    /**
     * @return TariffVoipNdcType[]
     */
    abstract public function getTariffVoipNdcTypes();

    /**
     * @return TariffVoipSource[]
     */
    abstract public function getTariffVoipSources();

    /**
     * @return TariffOrganization[]
     */
    abstract public function getTariffOrganizations();

    /**
     * @return TariffBundle[]
     */
    abstract public function getTariffBundles();

    /**
     * @return TariffTags[]
     */
    abstract public function getTariffTags();

    /**
     * @return TariffCountry[]
     */
    abstract public function getTariffCountries();

    /**
     * @return TariffVoipCountry[]
     */
    abstract public function getTariffVoipCountries();

    /**
     * @return Tariff
     */
    abstract public function getTariffModel();

    /**
     * @return PackageApi
     */
    abstract public function getPackageApi();

    /**
     * Конструктор
     */
    public function init()
    {
        $this->tariff = $this->getTariffModel();
        $this->tariffPeriods = $this->getTariffPeriods();
        $this->tariffResources = $this->getTariffResources();
        $this->packageApi = $this->getPackageApi();

        // Обработать submit (создать, редактировать, удалить)
        $this->loadFromInput();
    }

    /**
     * @return TariffPeriod[]
     */
    public function getNewTariffPeriods()
    {
        /** @var Period $period */
        $period = Period::find()->where(['monthscount' => 1])->one();

        $tariffPeriod = new TariffPeriod();
        if ($period) {
            $tariffPeriod->charge_period_id = $period->id;
        }

        $tariffPeriod->price_setup = 0;
        $tariffPeriod->price_min = 0;
        return [$tariffPeriod];
    }

    /**
     * Обработать submit (создать, редактировать, удалить)
     */
    protected function loadFromInput()
    {
        $post = \Yii::$app->request->post();

        $isInUse = $this->tariff->isHasAccountTariff();
        if ($isInUse) {
            // На этом тарифе есть услуги. Редактировать можно только некоторые свойства.
            if (isset($post['Tariff']['name'])) {
                // checkbox передаются даже disabled, потому что они в паре с hidden. Надо все лишнее убрать
                $post['Tariff'] = [
                        'name' => $post['Tariff']['name'],
                        'tariff_status_id' => $post['Tariff']['tariff_status_id'],
                        'tag_id' => $post['Tariff']['tag_id'],
                        'is_default' => $post['Tariff']['is_default'],
                        'is_charge_after_blocking' => $post['Tariff']['is_charge_after_blocking'],
                        'is_one_active' => $post['Tariff']['is_one_active'],
                        'is_proportionately' => $post['Tariff']['is_proportionately'],
                        'voip_group_id' => $post['Tariff']['voip_group_id'],
                        'overview' => $post['Tariff']['overview'],
                        'comment' => $post['Tariff']['comment'],
                        'payment_template_type_id' => $post['Tariff']['payment_template_type_id'],
                    ] + (\Yii::$app->user->can('tarifs.editTax') ? [
                        'tax_rate' => $post['Tariff']['tax_rate'],
                        'agent_tax_rate' => $post['Tariff']['agent_tax_rate'],
                    ] : []);

            }

            unset($post['TariffPeriod']/*, $post['TariffResource']*/, $post['Package']);
        }

        $this->tariffOrganizations = $this->getTariffOrganizations();
        $this->tariffBundles = $this->getTariffBundles();
        $this->tariffTags = $this->getTariffTags();
        $this->tariffCountries = $this->getTariffCountries();

        switch ($this->tariff->service_type_id) {

            case ServiceType::ID_VPBX:
                // только для ВАТС
                break;

            case ServiceType::ID_VOIP:
            case ServiceType::ID_VOIP_PACKAGE_CALLS:
                // только для телефонии
                $this->tariffVoipCountries = $this->getTariffVoipCountries();
                $this->tariffVoipCities = $this->getTariffVoipCities();
                $this->tariffNdcTypes = $this->getTariffVoipNdcTypes();
                $this->tariffSource = $this->getTariffVoipSources();
                break;
        }

        // загрузить параметры от юзера
        $transaction = \Yii::$app->db->beginTransaction();
        $transactionPg = \Yii::$app->dbPg->beginTransaction();
        try {
            if (isset($post['cloneButton'])) {

                // клонировать тариф
                $tariffCloned = $this->_cloneTariff();
                $this->id = $tariffCloned->id;
                $this->isSaved = true;

            } elseif (isset($post['dropButton'])) {

                // удалить
                try {
                    $this->tariff->delete();
                    $this->id = null;
                    $this->isSaved = true;
                } catch (\Exception $e) {
                    $msg = $e->getMessage();
                    if (strpos($msg, 'SQLSTATE[23000') !== false) {
                        if (strpos($msg, TariffBundle::tableName()) !== false) {
                            $msg = 'Бандл-тариф нельзя удалить, т.к. на него ссылаются бандл-пакеты';
                        }
                    }

                    throw new \Exception($msg, $e->getCode());
                }

            } elseif ($this->tariff->load($post)) {

                if ($this->tariff->is_autoprolongation) {
                    $this->tariff->count_of_validity_period = 0;
                }

                if (!$this->tariff->save()) {
                    $this->validateErrors += $this->tariff->getFirstErrors();
                    throw new ModelValidationException($this->tariff);
                }

                Tariff::deleteCacheById($this->tariff->id);

                $this->id = $this->tariff->id;
                $this->isSaved = true;

                $tariffPeriod = new TariffPeriod();
                $tariffPeriod->tariff_id = $this->id;
                if (isset($post['TariffPeriod'])) {
                    $this->tariffPeriods = $this->crudMultiple($this->tariffPeriods, $post, $tariffPeriod);
                }

                $tariffResource = new TariffResource();
                $tariffResource->tariff_id = $this->id;
                if (isset($post['TariffResource'])) {
                    $this->tariffResources = $this->crudMultiple($this->tariffResources, $post, $tariffResource);
                }

                $tariffOrganization = new TariffOrganization();
                $tariffOrganization->tariff_id = $this->id;
                $this->tariffOrganizations = $this->crudMultipleSelect2($this->tariffOrganizations, $post, $tariffOrganization, 'organization_id');

                $tariffBundle = new TariffBundle();
                $tariffBundle->package_tariff_id = $this->id;
                $this->tariffBundles = $this->crudMultipleSelect2($this->tariffBundles, $this->tariff->is_bundle ? $post : [$tariffBundle->formName() => []], $tariffBundle, 'tariff_id');

                $tariffTag = new TariffTags();
                $tariffTag->tariff_id = $this->id;
                $this->tariffTags = $this->crudMultipleSelect2($this->tariffTags, $post, $tariffTag, 'tag_id');

                $tariffCountries = new TariffCountry();
                $tariffCountries->tariff_id = $this->id;
                $this->tariffCountries = $this->crudMultipleSelect2($this->tariffCountries, $post, $tariffCountries, 'country_id');

                switch ($this->tariff->service_type_id) {

                    case ServiceType::ID_VPBX:
                        // только для ВАТС
                        break;

                    case ServiceType::ID_VOIP_PACKAGE_CALLS:
                        $tariffVoipCountries = new TariffVoipCountry();
                        $tariffVoipCountries->tariff_id = $this->id;
                        $this->tariffVoipCountries = $this->crudMultipleSelect2($this->tariffVoipCountries, $post, $tariffVoipCountries, 'country_id');
                    // no break;

                    case ServiceType::ID_VOIP_PACKAGE_SMS:
                    case ServiceType::ID_A2P_PACKAGE:
                    case ServiceType::ID_TRUNK_PACKAGE_ORIG:
                    case ServiceType::ID_TRUNK_PACKAGE_TERM:
                    case ServiceType::ID_VOIP_PACKAGE_INTERNET_ROAMABILITY:
                    case ServiceType::ID_VOIP_PACKAGE_MAV:

                        if (!$this->id) {
                            break;
                        }

                        $package = $this->tariff->package;
                        if (!$package) {
                            $package = new Package();
                            $package->tariff_id = $this->id;
                        }

                        $package->service_type_id = $this->tariff->service_type_id;
                        $package->is_include_vat = (bool)$this->tariff->is_include_vat;
                        $package->name = $this->tariff->name;

                        $package->load($post);
                        $package->currency_id = $this->tariff->currency_id;
                        $package->price_min = reset($this->tariffPeriods)->price_min;
                        if (!$package->save()) {
                            $this->validateErrors += $package->getFirstErrors();
                            throw new ModelValidationException($package);
                        }

                        $packageMinute = new PackageMinute();
                        $packageMinute->tariff_id = $this->id;
                        $this->crudMultiple($this->tariff->packageMinutes, $post, $packageMinute);

                        $packagePrice = new PackagePrice();
                        $packagePrice->tariff_id = $this->id;
                        $this->crudMultiple($this->tariff->packagePrices, $post, $packagePrice);

                        $packagePricelistNnp = new PackagePricelistNnp();
                        $packagePricelistNnp->tariff_id = $this->id;
                        $this->crudMultiple($this->tariff->packagePricelistsNnp, $post, $packagePricelistNnp);

                        // или обычный прайс-лист или v2
                        if (!isset($post['PackagePricelistNnp'])) {
                            $packagePricelist = new PackagePricelist();
                            $packagePricelist->tariff_id = $this->id;
                            $this->crudMultiple($this->tariff->packagePricelists, $post, $packagePricelist);
                        }

                        if ($this->tariff->service_type_id == ServiceType::ID_VOIP_PACKAGE_CALLS) {
                            $tariffVoipCity = new TariffVoipCity();
                            $tariffVoipCity->tariff_id = $this->id;
                            $this->tariffVoipCities = $this->crudMultipleSelect2($this->tariffVoipCities, (count($this->tariffCountries) == 1) ? $post : [], $tariffVoipCity, 'city_id');

                            $tariffVoipNdcType = new TariffVoipNdcType();
                            $tariffVoipNdcType->tariff_id = $this->id;
                            $this->tariffNdcTypes = $this->crudMultipleSelect2($this->tariffNdcTypes, $post, $tariffVoipNdcType, 'ndc_type_id');

                            $tariffVoipSource = new TariffVoipSource();
                            $tariffVoipSource->tariff_id = $this->id;
                            $this->tariffSource = $this->crudMultipleSelect2($this->tariffSource, $post, $tariffVoipSource, 'source_code', null, false);
                        }

                        if ($this->tariff->service_type_id == ServiceType::ID_VOIP_PACKAGE_INTERNET_ROAMABILITY) {
                            $packageInternet = new PackagePricelistNnpInternet();
                            $packageInternet->tariff_id = $this->id;
                            $this->crudMultiple($this->tariff->packagePricelistsNnpInternet, $post, $packageInternet);
                        }

                        if (
                            $this->tariff->service_type_id == ServiceType::ID_VOIP_PACKAGE_SMS
                            || $this->tariff->service_type_id == ServiceType::ID_A2P_PACKAGE
                        ) {
                            $packageSms = new PackagePricelistNnpSms();
                            $packageSms->tariff_id = $this->id;
                            $this->crudMultiple($this->tariff->packagePricelistsNnpSms, $post, $packageSms);
                        }

                        break;

                    case ServiceType::ID_VOIP:
                        // только для телефонии
                        $tariffVoipCountries = new TariffVoipCountry();
                        $tariffVoipCountries->tariff_id = $this->id;
                        $this->tariffVoipCountries = $this->crudMultipleSelect2($this->tariffVoipCountries, $post, $tariffVoipCountries, 'country_id');

                        $tariffVoipCity = new TariffVoipCity();
                        $tariffVoipCity->tariff_id = $this->id;
                        $this->tariffVoipCities = $this->crudMultipleSelect2($this->tariffVoipCities, (count($this->tariffCountries) == 1) ? $post : [], $tariffVoipCity, 'city_id');

                        $tariffVoipNdcType = new TariffVoipNdcType();
                        $tariffVoipNdcType->tariff_id = $this->id;
                        $this->tariffNdcTypes = $this->crudMultipleSelect2($this->tariffNdcTypes, $post, $tariffVoipNdcType, 'ndc_type_id');

                        $tariffVoipSource = new TariffVoipSource();
                        $tariffVoipSource->tariff_id = $this->id;
                        $this->tariffSource = $this->crudMultipleSelect2($this->tariffSource, $post, $tariffVoipSource, 'source_code', null, false);
                        break;

                    case ServiceType::ID_BILLING_API_MAIN_PACKAGE:
                        if (!$this->id || $isInUse) {
                            break;
                        }

                        $package = $this->tariff->package;
                        if (!$package) {
                            $package = new Package();
                            $package->tariff_id = $this->id;
                        }

                        $package->service_type_id = $this->tariff->service_type_id;
                        $package->is_include_vat = (bool)$this->tariff->is_include_vat;
                        $package->name = $this->tariff->name;

                        $package->load($post);
                        $package->currency_id = $this->tariff->currency_id;
                        if (!$package->save()) {
                            $this->validateErrors += $package->getFirstErrors();
                            throw new ModelValidationException($package);
                        }

                        $packageApi = new PackageApi();
                        $packageApi->tariff_id = $this->id;
                        $this->packageApi = $this->crudMultiple($this->packageApi, $post, $packageApi);

                        break;

                }
            }

            if ($this->validateErrors) {
                throw new InvalidArgumentException();
            }

            $transaction->commit();
            $transactionPg->commit();

        } catch (InvalidArgumentException $e) {
            $transaction->rollBack();
            $transactionPg->rollBack();
            $this->isSaved = false;

        } catch (\Exception $e) {
            $transaction->rollBack();
            $transactionPg->rollBack();
            \Yii::error($e);
            $this->isSaved = false;
            $this->validateErrors[] = YII_DEBUG ? $e->getMessage() : \Yii::t('common', 'Internal error');
        }
    }

    /**
     * Клонировать тариф
     *
     * @return Tariff
     * @throws ModelValidationException
     */
    private function _cloneTariff()
    {
        $tariffCloned = $this->_cloneTariffTariff();
        $this->_cloneTariffCountry($tariffCloned);
        $this->_cloneTariffVoipCountry($tariffCloned);
        $this->_cloneTariffVoipCity($tariffCloned);
        $this->_cloneTariffVoipNdcType($tariffCloned);
        $this->_cloneTariffTags($tariffCloned);
        $this->_cloneTariffOrganization($tariffCloned);
        $this->_cloneTariffBundle($tariffCloned);
        $this->_cloneTariffPeriod($tariffCloned);
        $this->_cloneTariffResource($tariffCloned);
        $this->_cloneTariffPackage($tariffCloned);
        $this->_cloneTariffPackagePrice($tariffCloned);
        $this->_cloneTariffPackagePricelist($tariffCloned);
        $this->_cloneTariffPackagePricelistNnp($tariffCloned);
        $this->_cloneTariffPackageMinute($tariffCloned);
        $this->_cloneTariffPackageApi($tariffCloned);
        return $tariffCloned;
    }

    /**
     * Клонировать тариф. Tariff
     *
     * @return Tariff
     * @throws ModelValidationException
     */
    private function _cloneTariffTariff()
    {
        $tariffCloned = new Tariff();
        $fieldNamesExcept = [
            'id',
            'insert_time',
            'insert_user_id',
            'update_time',
            'update_user_id',
        ];

        $tariffCloned->setAttributes($this->tariff->getAttributes(null, $fieldNamesExcept), false);

        if (!$tariffCloned->save()) {
            $this->validateErrors += $tariffCloned->getFirstErrors();
            throw new ModelValidationException($tariffCloned);
        }

        return $tariffCloned;
    }

    /**
     * Клонировать тариф. TariffVoipCity
     *
     * @param Tariff $tariffCloned
     * @throws ModelValidationException
     */
    private function _cloneTariffVoipCity(Tariff $tariffCloned)
    {
        $voipCities = $this->tariff->voipCities;
        $fieldNames = [
            'city_id',
        ];
        foreach ($voipCities as $voipCity) {
            $voipCityCloned = new TariffVoipCity();
            $voipCityCloned->tariff_id = $tariffCloned->id;
            foreach ($fieldNames as $fieldName) {
                $voipCityCloned->$fieldName = $voipCity->$fieldName;
            }

            if (!$voipCityCloned->save()) {
                $this->validateErrors += $voipCityCloned->getFirstErrors();
                throw new ModelValidationException($voipCityCloned);
            }
        }
    }

    /**
     * Клонировать тариф. TariffCountry
     *
     * @param Tariff $tariffCloned
     * @throws ModelValidationException
     */
    private function _cloneTariffCountry(Tariff $tariffCloned)
    {
        $tariffCountries = $this->tariff->tariffCountries;
        $fieldNames = [
            'country_id',
        ];
        foreach ($tariffCountries as $tariffCountry) {
            $tariffCountryCloned = new TariffCountry();
            $tariffCountryCloned->tariff_id = $tariffCloned->id;
            foreach ($fieldNames as $fieldName) {
                $tariffCountryCloned->$fieldName = $tariffCountry->$fieldName;
            }

            if (!$tariffCountryCloned->save()) {
                $this->validateErrors += $tariffCountryCloned->getFirstErrors();
                throw new ModelValidationException($tariffCountryCloned);
            }
        }
    }

    /**
     * Клонировать тариф. TariffVoipCountry
     *
     * @param Tariff $tariffCloned
     * @throws ModelValidationException
     */
    private function _cloneTariffVoipCountry(Tariff $tariffCloned)
    {
        $tariffVoipCountries = $this->tariff->tariffVoipCountries;
        $fieldNames = [
            'country_id',
        ];
        foreach ($tariffVoipCountries as $tariffVoipCountry) {
            $tariffVoipCountryCloned = new TariffVoipCountry();
            $tariffVoipCountryCloned->tariff_id = $tariffCloned->id;
            foreach ($fieldNames as $fieldName) {
                $tariffVoipCountryCloned->$fieldName = $tariffVoipCountry->$fieldName;
            }

            if (!$tariffVoipCountryCloned->save()) {
                $this->validateErrors += $tariffVoipCountryCloned->getFirstErrors();
                throw new ModelValidationException($tariffVoipCountryCloned);
            }
        }
    }

    /**
     * Клонировать тариф. TariffVoipNdcType
     *
     * @param Tariff $tariffCloned
     * @throws ModelValidationException
     */
    private function _cloneTariffVoipNdcType(Tariff $tariffCloned)
    {
        $voipNdcTypes = $this->tariff->voipNdcTypes;
        $fieldNames = [
            'ndc_type_id',
        ];
        foreach ($voipNdcTypes as $voipNdcType) {
            $voipNdcTypeCloned = new TariffVoipNdcType();
            $voipNdcTypeCloned->tariff_id = $tariffCloned->id;
            foreach ($fieldNames as $fieldName) {
                $voipNdcTypeCloned->$fieldName = $voipNdcType->$fieldName;
            }

            if (!$voipNdcTypeCloned->save()) {
                $this->validateErrors += $voipNdcTypeCloned->getFirstErrors();
                throw new ModelValidationException($voipNdcTypeCloned);
            }
        }
    }

    /**
     * Клонировать тариф. TariffTags
     *
     * @param Tariff $tariffCloned
     * @throws ModelValidationException
     */
    private function _cloneTariffTags(Tariff $tariffCloned)
    {
        $tags = $this->tariff->tags;
        $fieldNames = [
            'tag_id',
        ];
        foreach ($tags as $tag) {
            $tagsCloned = new TariffTags();
            $tagsCloned->tariff_id = $tariffCloned->id;
            foreach ($fieldNames as $fieldName) {
                $tagsCloned->$fieldName = $tag->$fieldName;
            }

            if (!$tagsCloned->save()) {
                $this->validateErrors += $tagsCloned->getFirstErrors();
                throw new ModelValidationException($tagsCloned);
            }
        }
    }

    /**
     * Клонировать тариф. TariffOrganization
     *
     * @param Tariff $tariffCloned
     * @throws ModelValidationException
     */
    private function _cloneTariffOrganization(Tariff $tariffCloned)
    {
        $organizations = $this->tariff->organizations;
        $fieldNames = [
            'organization_id',
        ];
        foreach ($organizations as $organization) {
            $organizationCloned = new TariffOrganization();
            $organizationCloned->tariff_id = $tariffCloned->id;
            foreach ($fieldNames as $fieldName) {
                $organizationCloned->$fieldName = $organization->$fieldName;
            }

            if (!$organizationCloned->save()) {
                $this->validateErrors += $organizationCloned->getFirstErrors();
                throw new ModelValidationException($organizationCloned);
            }
        }
    }

    /**
     * Клонировать тариф. TariffOrganization
     *
     * @param Tariff $tariffCloned
     * @throws ModelValidationException
     */
    private function _cloneTariffBundle(Tariff $tariffCloned)
    {
        $isPackage = isset(ServiceType::$packages[$tariffCloned->service_type_id]);

        $tariffField = 'tariff_id';
        $tariffPackageField = 'package_tariff_id';
        $bundleTariffs = $this->tariff->bundlePackages;

        if ($isPackage) {
            $tariffField = 'package_tariff_id';
            $tariffPackageField = 'tariff_id';
            $bundleTariffs = $this->tariff->bundleTariffs;
        }


        $fieldNames = [
            $tariffPackageField,
        ];
        foreach ($bundleTariffs as $bundleTariff) {
            $tariffBundleCloned = new TariffBundle();
            $tariffBundleCloned->{$tariffField} = $tariffCloned->id;
            foreach ($fieldNames as $fieldName) {
                $tariffBundleCloned->$fieldName = $bundleTariff->$fieldName;
            }

            if (!$tariffBundleCloned->save()) {
                $this->validateErrors += $tariffBundleCloned->getFirstErrors();
                throw new ModelValidationException($tariffBundleCloned);
            }
        }
    }

    /**
     * Клонировать тариф. TariffPeriod
     *
     * @param Tariff $tariffCloned
     * @throws ModelValidationException
     */
    private function _cloneTariffPeriod(Tariff $tariffCloned)
    {
        $tariffPeriods = $this->tariff->tariffPeriods;
        $fieldNames = [
            'price_per_period',
            'price_setup',
            'price_min',
            'charge_period_id',
        ];
        foreach ($tariffPeriods as $tariffPeriod) {
            $tariffPeriodCloned = new TariffPeriod();
            $tariffPeriodCloned->tariff_id = $tariffCloned->id;
            foreach ($fieldNames as $fieldName) {
                $tariffPeriodCloned->$fieldName = $tariffPeriod->$fieldName;
            }

            if (!$tariffPeriodCloned->save()) {
                $this->validateErrors += $tariffPeriodCloned->getFirstErrors();
                throw new ModelValidationException($tariffPeriodCloned);
            }
        }
    }

    /**
     * Клонировать тариф. TariffResource
     *
     * @param Tariff $tariffCloned
     * @throws ModelValidationException
     */
    private function _cloneTariffResource(Tariff $tariffCloned)
    {
        $tariffResources = $this->tariff->tariffResources;
        $fieldNames = [
            'amount',
            'price_per_unit',
            'price_min',
            'resource_id',
            'is_can_manage',
            'is_show_resource',
        ];
        foreach ($tariffResources as $tariffResource) {
            $tariffResourceCloned = new TariffResource();
            $tariffResourceCloned->tariff_id = $tariffCloned->id;
            foreach ($fieldNames as $fieldName) {
                $tariffResourceCloned->$fieldName = $tariffResource->$fieldName;
            }

            if (!$tariffResourceCloned->save()) {
                $this->validateErrors += $tariffResourceCloned->getFirstErrors();
                throw new ModelValidationException($tariffResourceCloned);
            }
        }
    }

    /**
     * Клонировать тариф. Package
     *
     * @param Tariff $tariffCloned
     * @throws ModelValidationException
     */
    private function _cloneTariffPackage(Tariff $tariffCloned)
    {
        $package = $this->tariff->package;
        if (!$package) {
            return;
        }

        $fieldNames = [
            'service_type_id',
            'is_termination',
            'tarification_free_seconds',
            'tarification_interval_seconds',
            'tarification_type',
            'tarification_min_paid_seconds',
            'currency_id',
            'is_include_vat',
            'name',
            'is_inversion_mgp',
        ];
        $packageCloned = new Package();
        $packageCloned->tariff_id = $tariffCloned->id;
        foreach ($fieldNames as $fieldName) {
            $packageCloned->$fieldName = $package->$fieldName;
        }

        if (!$packageCloned->save()) {
            $this->validateErrors += $packageCloned->getFirstErrors();
            throw new ModelValidationException($packageCloned);
        }
    }

    /**
     * Клонировать тариф. PackagePrice
     *
     * @param Tariff $tariffCloned
     * @throws ModelValidationException
     */
    private function _cloneTariffPackagePrice(Tariff $tariffCloned)
    {
        $packagePrices = $this->tariff->packagePrices;
        $fieldNames = [
            'destination_id',
            'price',
            'interconnect_price',
            'connect_price',
            'weight',
        ];
        foreach ($packagePrices as $packagePrice) {
            $packagePriceCloned = new PackagePrice();
            $packagePriceCloned->tariff_id = $tariffCloned->id;
            foreach ($fieldNames as $fieldName) {
                $packagePriceCloned->$fieldName = $packagePrice->$fieldName;
            }

            if (!$packagePriceCloned->save()) {
                $this->validateErrors += $packagePriceCloned->getFirstErrors();
                throw new ModelValidationException($packagePriceCloned);
            }
        }
    }

    /**
     * Клонировать тариф. PackagePricelist
     *
     * @param Tariff $tariffCloned
     * @throws ModelValidationException
     */
    private function _cloneTariffPackagePricelist(Tariff $tariffCloned)
    {
        $packagePricelists = $this->tariff->packagePricelists;
        $fieldNames = [
            'pricelist_id',
        ];
        foreach ($packagePricelists as $packagePricelist) {
            $packagePricelistCloned = new PackagePricelist();
            $packagePricelistCloned->tariff_id = $tariffCloned->id;
            foreach ($fieldNames as $fieldName) {
                $packagePricelistCloned->$fieldName = $packagePricelist->$fieldName;
            }

            if (!$packagePricelistCloned->save()) {
                $this->validateErrors += $packagePricelistCloned->getFirstErrors();
                throw new ModelValidationException($packagePricelistCloned);
            }
        }
    }

    /**
     * Клонировать тариф. PackagePricelistNnp
     *
     * @param Tariff $tariffCloned
     * @throws ModelValidationException
     */
    private function _cloneTariffPackagePricelistNnp(Tariff $tariffCloned)
    {
        $packagePricelists = $this->tariff->packagePricelistsNnp;
        $fieldNames = [
            'nnp_pricelist_id',
        ];
        foreach ($packagePricelists as $packagePricelist) {
            $packagePricelistCloned = new PackagePricelistNnp();
            $packagePricelistCloned->tariff_id = $tariffCloned->id;
            foreach ($fieldNames as $fieldName) {
                $packagePricelistCloned->$fieldName = $packagePricelist->$fieldName;
            }

            if (!$packagePricelistCloned->save()) {
                $this->validateErrors += $packagePricelistCloned->getFirstErrors();
                throw new ModelValidationException($packagePricelistCloned);
            }
        }
    }

    /**
     * Клонировать тариф. PackageMinute
     *
     * @param Tariff $tariffCloned
     * @throws ModelValidationException
     */
    private function _cloneTariffPackageMinute(Tariff $tariffCloned)
    {
        $packageMinutes = $this->tariff->packageMinutes;
        $fieldNames = [
            'destination_id',
            'minute',
        ];
        foreach ($packageMinutes as $packageMinute) {
            $packageMinuteCloned = new PackageMinute();
            $packageMinuteCloned->tariff_id = $tariffCloned->id;
            foreach ($fieldNames as $fieldName) {
                $packageMinuteCloned->$fieldName = $packageMinute->$fieldName;
            }

            if (!$packageMinuteCloned->save()) {
                $this->validateErrors += $packageMinuteCloned->getFirstErrors();
                throw new ModelValidationException($packageMinuteCloned);
            }
        }
    }

    /**
     * Клонировать тариф. PackageApi
     *
     * @param Tariff $tariffCloned
     * @throws ModelValidationException
     */
    private function _cloneTariffPackageApi(Tariff $tariffCloned)
    {
        $packageApis = $this->tariff->packageApi;

        $fieldNames = [
            'api_pricelist_id',
        ];
        foreach ($packageApis as $packageApi) {
            $packageApiCloned = new PackageApi();
            $packageApiCloned->tariff_id = $tariffCloned->id;
            foreach ($fieldNames as $fieldName) {
                $packageApiCloned->$fieldName = $packageApi->$fieldName;
            }

            if (!$packageApiCloned->save()) {
                $this->validateErrors += $packageApiCloned->getFirstErrors();
                throw new ModelValidationException($packageApiCloned);
            }
        }
    }
}
