<?php

namespace app\modules\uu\models\traits;

use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\City;
use app\models\ClientAccount;
use app\models\Datacenter;
use app\models\dictionary\A2pSmsRoute;
use app\models\Region;
use app\models\UsageTrunk;
use app\modules\sim\models\Card;
use app\modules\uu\models\AccountEntry;
use app\modules\uu\models\AccountLogPeriod;
use app\modules\uu\models\AccountLogResource;
use app\modules\uu\models\AccountLogSetup;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\AccountTariffExtVoip;
use app\modules\uu\models\AccountTariffHeap;
use app\modules\uu\models\AccountTariffLog;
use app\modules\uu\models\AccountTariffResourceLog;
use app\modules\uu\models\AccountTrouble;
use app\modules\uu\models\helper\AccountTariffHelper;
use app\modules\uu\models\ResourceModel;
use app\modules\uu\models\ServiceType;
use app\modules\uu\models\TariffPeriod;
use app\modules\uu\models\TariffResource;
use yii\db\ActiveQuery;
use yii\db\Expression;

/**
 * @property-read ClientAccount $clientAccount
 * @property-read ServiceType $serviceType
 * @property-read ResourceModel[] $resources
 * @property-read Region $region
 * @property-read City $city
 * @property-read \app\models\Number $number
 * @property-read AccountTariff $prevAccountTariff  Основная услуга
 * @property-read AccountEntry[] $accountEntries  Универсальные проводки
 * @property-read AccountTariff[] $nextAccountTariffs   Пакеты
 * @property-read AccountTariff[] $nextAccountTariffsEager   Пакеты
 * @property-read AccountTariff $prevUsage  Перенесено из
 * @property-read AccountTariff $nextUsage   Перенесено в
 * @property-read AccountTariffLog[] $accountTariffLogs
 * @property-read AccountTariffResourceLog[] $accountTariffResourceLogs
 * @property-read AccountTariffResourceLog[] $accountTariffResourceLogsAll
 * @property-read TariffPeriod $tariffPeriod
 * @property-read Datacenter $datacenter
 * @property-read UsageTrunk $usageTrunk
 * @property-read AccountTariffHeap $accountTariffHeap
 * @property-read AccountTrouble[] $accountTroubles
 *
 * @property-read AccountLogSetup[] $accountLogSetups
 * @property-read AccountLogPeriod[] $accountLogPeriods
 * @property-read AccountLogPeriod[] $accountLogPeriodsByUniqueKey
 * @property-read AccountLogPeriod $accountLogPeriodLast
 * @property-read AccountLogResource[] $accountLogResources
 * @property-read AccountLogResource[] $accountLogResourceOptions
 * @property-read AccountLogResource[] $accountLogResourceTraffics
 *
 * @property-read AccountTariffHelper $helper
 * @property-read AccountTariffExtVoip $extVoip
 * @property-read Card $iccidModel
 *
 * @property string iccid
 * @property-read string iccid_saved_at_utc
 *
 * @method ActiveQuery hasMany($class, array $link) see [[BaseActiveRecord::hasMany()]] for more info
 * @method ActiveQuery hasOne($class, array $link) see [[BaseActiveRecord::hasOne()]] for more info
 *
 * @method static AccountTariff findOne($condition)
 * @method static AccountTariff[] findAll($condition)
 */
trait AccountTariffRelationsTrait
{
    /**
     * @return ActiveQuery
     */
    public function getTariffPeriod()
    {
        return $this->hasOne(TariffPeriod::class, ['id' => 'tariff_period_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getClientAccount()
    {
        return $this->hasOne(ClientAccount::class, ['id' => 'client_account_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getPrevAccountTariff()
    {
        return $this->hasOne(self::class, ['id' => 'prev_account_tariff_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountEntries()
    {
        return $this->hasMany(AccountEntry::class, ['account_tariff_id' => 'id'])
            ->inverseOf('accountTariff');
    }

    /**
     * @return ActiveQuery
     */
    public function getNextAccountTariffs()
    {
        return $this->hasMany(self::class, ['prev_account_tariff_id' => 'id'])
            ->inverseOf('prevAccountTariff')
            ->indexBy('id');
    }

    /**
     * @return ActiveQuery
     */
    public function getNextAccountTariffsEager()
    {
        return $this->hasMany(self::class, ['prev_account_tariff_id' => 'id'])
            ->with('number')
            ->with('serviceType.resources')
            ->with('region')
            ->with('city')

            ->with('tariffPeriod.tariff.tariffResourcesIndexedByResourceId')
            ->with('clientAccount')

            ->with('accountTariffLogs.accountTariff.clientAccount')
            ->with('accountLogPeriods')

            ->with('accountTariffLogs.tariffPeriod.chargePeriod')
            ->with('accountTariffLogs.tariffPeriod.tariff.tariffVoipCountries.country')
            ->with('accountTariffLogs.tariffPeriod.tariff.package')
            ->with('accountTariffLogs.tariffPeriod.tariff.serviceType')
            ->with('accountTariffLogs.tariffPeriod.tariff.status')
            ->with('accountTariffLogs.tariffPeriod.tariff.person')
            ->with('accountTariffLogs.tariffPeriod.tariff.tag')
            ->with('accountTariffLogs.tariffPeriod.tariff.tariffResources.resource.serviceType')
            ->with('accountTariffLogs.tariffPeriod.tariff.voipGroup')
            ->with('accountTariffLogs.tariffPeriod.tariff.voipCities.city')
            ->with('accountTariffLogs.tariffPeriod.tariff.voipNdcTypes.ndcType')
            ->with('accountTariffLogs.tariffPeriod.tariff.organizations.organization')
            ->with('accountTariffLogs.tariffPeriod.tariff.packageMinutes.destination')
            ->with('accountTariffLogs.tariffPeriod.tariff.packagePrices.destination')
            ->with('accountTariffLogs.tariffPeriod.tariff.packagePricelists.pricelist')

            ->with('accountTariffResourceLogsAll.accountTariff.clientAccount')
            ->with('accountTariffResourceLogs.resource')

            ->with('accountLogPeriodLast')
            ->with('accountLogPeriodLast.minutesSummary')

            ->with('nextAccountTariffsEager')

            ->inverseOf('prevAccountTariff')
            ->indexBy('id');
    }

    /**
     * @return ActiveQuery
     */
    public function getPrevUsage()
    {
        return $this->hasOne(self::class, ['id' => 'prev_usage_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getNextUsage()
    {
        return $this->hasOne(self::class, ['prev_usage_id' => 'id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getRegion()
    {
        return $this->hasOne(Region::class, ['id' => 'region_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getCity()
    {
        return $this->hasOne(City::class, ['id' => 'city_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getNumber()
    {
        return $this->hasOne(\app\models\Number::class, ['number' => 'voip_number']);
    }

    /**
     * @return ActiveQuery
     */
    public function getServiceType()
    {
        return $this->hasOne(ServiceType::class, ['id' => 'service_type_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getDatacenter()
    {
        return $this->hasOne(Datacenter::class, ['id' => 'datacenter_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getUsageTrunk()
    {
        return $this->hasOne(UsageTrunk::class, ['id' => 'id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getResources()
    {
        return $this->hasMany(ResourceModel::class, ['service_type_id' => 'service_type_id'])
            ->indexBy('id')
            ->orderBy(['id' => SORT_ASC]);
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountLogSetups()
    {
        return $this->hasMany(AccountLogSetup::class, ['account_tariff_id' => 'id'])
            ->indexBy('id')
            ->orderBy(['id' => SORT_ASC]);
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountLogPeriods()
    {
        return $this->hasMany(AccountLogPeriod::class, ['account_tariff_id' => 'id'])
            ->indexBy('id')
            ->orderBy(['id' => SORT_ASC]);
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountLogPeriodsByUniqueKey()
    {
        return $this->hasMany(AccountLogPeriod::class, ['account_tariff_id' => 'id'])
            ->indexBy(function (AccountLogPeriod $accountLogPeriod) {
                return $accountLogPeriod->getUniqueId();
            })
            ->inverseOf('accountTariff');
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountLogPeriodLast()
    {
        return $this->hasOne(AccountLogPeriod::class, ['account_tariff_id' => 'id'])
            ->from(['logs' => AccountLogPeriod::tableName()])
            ->andWhere(
                new Expression(
                    'logs.id = (' .
                    AccountLogPeriod::find()
                        ->select(['MAX(x.id)'])
                        ->from(['x' => AccountLogPeriod::tableName()])
                        ->where(['x.account_tariff_id' => new Expression('logs.account_tariff_id')])
                        ->createCommand()->rawSql .
                    ')'
                )
            );
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountLogResources()
    {
        return $this->hasMany(AccountLogResource::class, ['account_tariff_id' => 'id'])
            ->indexBy('id')
            ->orderBy(['id' => SORT_ASC]);
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountLogResourceOptions()
    {
        return $this->hasMany(AccountLogResource::class, ['account_tariff_id' => 'id'])
            ->joinWith('tariffResource')
            ->where([
                'NOT', [TariffResource::tableName() . '.resource_id' => ResourceModel::getReaderIds()], // только опции
            ]);
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountLogResourceTraffics()
    {
        return $this->hasMany(AccountLogResource::class, ['account_tariff_id' => 'id'])
            ->joinWith('tariffResource')
            ->where([
                TariffResource::tableName() . '.resource_id' => ResourceModel::getReaderIds(), // только трафик
            ]);
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountTariffLogs()
    {
        return $this->hasMany(AccountTariffLog::class, ['account_tariff_id' => 'id'])
            ->orderBy(['id' => SORT_DESC])
            ->indexBy('id')
            ->inverseOf('accountTariff');
    }

    /**
     * @param int $resourceId
     * @return ActiveQuery
     */
    public function getAccountTariffResourceLogs($resourceId = null)
    {
        return $this->hasMany(AccountTariffResourceLog::class, ['account_tariff_id' => 'id'])
            ->andWhere($resourceId ? ['resource_id' => $resourceId] : [])
            ->orderBy(
                [
                    'resource_id' => SORT_ASC,
                    'id' => SORT_DESC,
                ])
            ->indexBy('id')
            ->inverseOf('accountTariff');
    }

    /**
     * @param int $resourceId
     * @return AccountTariffResourceLog[]
     */
    public function getAccountTariffResourceLogsByResourceId($resourceId = null)
    {
        if (is_null($resourceId)) {
            return $this->accountTariffResourceLogsAll;
        }

        $logs = [];
        foreach($this->accountTariffResourceLogsAll as $id => $accountTariffResourceLog) {
            if ($accountTariffResourceLog->resource_id == $resourceId) {
                $logs[$id] = $accountTariffResourceLog;
            }
        }

        return $logs;
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountTariffResourceLogsAll()
    {
        return $this->hasMany(AccountTariffResourceLog::class, ['account_tariff_id' => 'id'])
            ->orderBy(
                [
                    'resource_id' => SORT_ASC,
                    'id' => SORT_DESC,
                ])
            ->indexBy('id')
            ->inverseOf('accountTariff');

    }

    /**
     * @return AccountTariffHelper
     */
    public function getHelper()
    {
        static $helperStorage = [];

        if (!array_key_exists($this->id, $helperStorage)) {
             $helperStorage[$this->id] = new AccountTariffHelper($this);
        }

        return $helperStorage[$this->id];
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountTariffHeap()
    {
        return $this->hasOne(AccountTariffHeap::class, ['account_tariff_id' => 'id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getExtVoip()
    {
        return $this->hasOne(AccountTariffExtVoip::class, ['account_tariff_id' => 'id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountTroubles()
    {
        return $this->hasMany(AccountTrouble::class, ['account_tariff_id' => 'id']);
    }

    /**
     * @return string
     */
    public function getAccountTroublesText()
    {
        return implode(', ', $this->accountTroubles);
    }

    /**
     * Лог включения
     * @return AccountTariffLog
     */
    public function getOnAccountTariffLog()
    {
        $logs = array_reverse($this->accountTariffLogs);
        $log = reset($logs);

        return $log;
    }


    /**
     * Лог отключения
     * @return AccountTariffLog
     */
    public function getOffAccountTariffLog()
    {
        $logs = $this->accountTariffLogs;
        $log = reset($logs);

        if ($log->tariff_period_id) {
            return false;
        }

        return $log;
    }

    /**
     * Лог последнего включенного тарифа
     * @return AccountTariffLog
     */
    public function getLastOnAccountTariffLog()
    {
        $logs = array_filter($this->accountTariffLogs, function (AccountTariffLog $log){
            return (bool)$log->tariff_period_id;
        });

        $log = reset($logs);

        return $log;
    }

    /**
     * Это пакет телефонии, без продления, если нет денег
     */
    public function isPricePackage()
    {
        return $this->service_type_id == ServiceType::ID_VOIP_PACKAGE_CALLS
        && $this->tariff_period_id
        && ($this->tariffPeriod->price_per_period > 0
            || $this->tariffPeriod->price_min > 0)
        && !$this->tariffPeriod->tariff->is_charge_after_blocking;
    }

    public function getRoute_name_default()
    {
        return 'API_' . $this->client_account_id;
    }

    public function getRoute_name()
    {
        $defaultRouteName = $this->route_name_default;

        if (!$this->calltracking_params) {
            return $defaultRouteName;
        }

        return $this->getParam('route_name', $defaultRouteName);
    }

    public function setRoute_name($routeName)
    {
        $this->addParam('route_name', $routeName);

        if ($routeName) {
            $route = A2pSmsRoute::findOne(['name' => $routeName]);
            if (!$route) {
                $route = new A2pSmsRoute();
                $route->name = $routeName;
                $route->route_name = $routeName;
                if (!$route->save()) {
                    throw new ModelValidationException($route);
                }
            }
            $this->addParam('route_id', $route->id);
        }
    }

    public function getIccid()
    {
        return $this->getParam('iccid', '');
    }

    public function getIccid_saved_at_utc()
    {
        return $this->getParam('iccid_saved_at_utc', null);
    }

    public function setIccid($routeName)
    {
        $this->addParam('iccid', $routeName);
        $this->addParam('iccid_saved_at_utc', DateTimeZoneHelper::getUtcDateTime()->format(DateTimeZoneHelper::DATETIME_FORMAT));
    }

    public function getIccidModel()
    {
        $iccid = $this->iccid;
        if (!$iccid) {
            return null;
        }

        return Card::findOne(['iccid' => $iccid]);
    }

    public function getCalligrapher_node_id()
    {
        return $this->getParam('calligrapher_node_id', 0);
    }

    public function setCalligrapher_node_id($id)
    {
        if (!$id) {
            $id = '';
        }
        $this->addParam('calligrapher_node_id', $id);
    }

    public function getCalligrapher_type_connection_id()
    {
        return $this->getParam('calligrapher_type_connection_id', 0);
    }

    public function setcalligrapher_type_connection_id($id)
    {
        if (!$id) {
            $id = '';
        }
        $this->addParam('calligrapher_type_connection_id', $id);
    }

    public function getDomain_name()
    {
        return $this->getParam('domain_name', '');
    }

    public function setdomain_name($name)
    {
        $name = trim($name);
        
        if (!$name) {
            $name = '';
        }
        
        $this->addParam('domain_name', $name);
    }


}