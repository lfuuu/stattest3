<?php

namespace app\modules\uu\models\traits;

use app\classes\HandlerLogger;
use app\classes\Html;
use app\exceptions\ModelValidationException;
use app\helpers\Semaphore;
use app\models\billing\StatsAccount;
use app\models\ClientAccount;
use app\models\DidGroup;
use app\modules\nnp\models\AccountTariffLight;
use app\modules\nnp\models\NdcType;
use app\modules\uu\models\AccountLogPeriod;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\AccountTariffLog;
use app\modules\uu\models\AccountTariffResourceLog;
use app\modules\uu\models\ResourceModel;
use app\modules\uu\models\ServiceType;
use app\modules\uu\models\Tariff;
use app\modules\uu\models\TariffPeriod;
use app\modules\uu\models\TariffStatus;
use Yii;
use yii\base\InvalidParamException;

trait AccountTariffPackageTrait
{
    private static string $TYPE_PACKAGE_DEFAULT = 'default_package';
    private static string $TYPE_PACKAGE_BUNDLE = 'bundle_package';

    /**
     * @param array $params
     * @throws \Exception
     */
    public static function actualizeDefaultPackages($params)
    {
        $accountTariffId = $params['account_tariff_id'] ?? 0;
        $accountTariff = AccountTariff::findOne(['id' => $accountTariffId]);
        if (!$accountTariff) {
            throw new \InvalidArgumentException('Услуга не найдена: ' . $accountTariffId->account_tariff_id);
        }

        $accountTariffLog = self::_getAccountTariffLogByParam($params, $accountTariff);

        self::checkEventSuitable($accountTariff, $accountTariffLog);

        if (!Semaphore::me()->acquire(Semaphore::ID_UU_CALCULATOR, false)) {
            throw new \LogicException('calculator busy, try restart later');
        }

        try {
            $accountTariff->addOrCloseDefaultPackage($accountTariffLog);
        } catch (\Exception $e) {
            Semaphore::me()->release(Semaphore::ID_UU_CALCULATOR);
            throw $e;
        }
        Semaphore::me()->release(Semaphore::ID_UU_CALCULATOR);
    }

    /**
     * Если эта услуга активна - подключить базовый пакет. Если неактивна - закрыть все пакеты.
     *
     * @throws \Exception
     */
    public function addOrCloseDefaultPackage(AccountTariffLog $accountTariffLog)
    {
        if (!in_array($this->service_type_id, ServiceType::$packages)) {
            return;
        }

        if (!$accountTariffLog->tariff_period_id) {
            HandlerLogger::me()->add('Услуга выключается');
            return;
        }

        if (
            !$accountTariffLog->tariffPeriod
            || !$accountTariffLog->tariffPeriod->tariff
        ) {
            throw new \InvalidArgumentException('Тариф не найден');
        }

        if (
            $accountTariffLog->tariffPeriod->tariff->is_bundle
        ) {
            HandlerLogger::me()->add('Tariff is bundle');
            return;
        }

        $transaction = \Yii::$app->db->beginTransaction();
        try {
            // подключить базовые пакеты
            $this->_addDefaultPackage($accountTariffLog);
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::error($e);
            HandlerLogger::me()->add($e->getMessage());
            throw $e;
        }

    }

    /**
     * Подключить базовые пакеты
     *
     * @throws \app\exceptions\ModelValidationException
     */
    private function _addDefaultPackage(AccountTariffLog $accountTariffLog)
    {
        $packageType = ServiceType::$serviceToPackage[$this->service_type_id] ?? null;

        if (
            !$packageType
            || $this->_hasDefaultPackage()
        ) {
            // хотя бы один базовый пакет уже подключен
            // или услуга без пекетов
            return;
        }

        $this->closeAlienPackages($accountTariffLog, self::$TYPE_PACKAGE_DEFAULT);

        /** @var TariffPeriod $tariffPeriod */
        $tariffPeriod = $accountTariffLog->tariffPeriod;
        $tariffStatuses = $this->getPackageTariffStatuses();
        /** @var \app\models\Number $number */
        $number = $this->number;

        $countryId = $this->clientAccount->getUuCountryId();

        /*
        if ($number) {
            $countryId = $number->country_code;
        } elseif ($this->city_id) {
            $countryId = $this->city->country_id;
        } elseif ($this->region_id) {
            $countryId = $this->region->country_id;
        } else {
            $countryId = null;
        }
        */

        if ($number && $number->ndc_type_id == NdcType::ID_MOBILE) {
            $packageType = [$packageType, ServiceType::ID_VOIP_PACKAGE_SMS, ServiceType::ID_VOIP_PACKAGE_INTERNET_ROAMABILITY];
        }

        $defaultPackages = $tariffPeriod->tariff->findDefaultPackages(
            $countryId,
            $this->city_id,
            $number ? $number->country_code : null,
            $number ? $number->ndc_type_id : null,
            $tariffPeriod->tariff->is_include_vat,
            $tariffStatuses,
            $packageType,
            $this->clientAccount->contract->organization_id
        );

        if (!$defaultPackages) {
            $msg = 'Не найден базовый пакет для услуги ' . $this->id;
            HandlerLogger::me()->add($msg);
            Yii::error($msg, 'uu');
            throw new \LogicException($msg);
            return;
        }

        /** @var Tariff $defaultPackage */
        foreach ($defaultPackages as $defaultPackage) {
            $this->_addPackage($defaultPackage, $accountTariffLog);
        }
    }

    static public function _getAccountTariffLogByParam(array $params, AccountTariff $accountTariff)
    {
        if (isset($params['account_tariff_log_id'])) {
            $accountTariffLog = AccountTariffLog::findOne(['id' => $params['account_tariff_log_id']]);
            if (!$accountTariffLog) {
                throw new \InvalidArgumentException('AccountTariffLog id: ' . $params['account_tariff_log_id'] . ' не найден');
            }

            if ($accountTariff->id != $accountTariffLog->account_tariff_id) {
                throw new \InvalidArgumentException('AccountTariffLog не является логом тарифа услуги (' . $accountTariff->id . ' != ' . $accountTariffLog->account_tariff_id . ')');
            }

        } elseif (isset($params['account_tariff_log_actual_from_utc']) && isset($params['new_tariff_period_id'])) {
            $accountTariffLog = new AccountTariffLog;
            $accountTariffLog->tariff_period_id = $params['new_tariff_period_id'];
            $accountTariffLog->account_tariff_id = $accountTariff->id;
            $accountTariffLog->actual_from_utc = $params['account_tariff_log_actual_from_utc'];
            HandlerLogger::me()->add('create AccountTariffLog with actual_from_utc: ' . $accountTariffLog->actual_from_utc);
        } else {
            throw new \InvalidArgumentException('AccountTariffLog: не установлен');
        }

        return $accountTariffLog;
    }

    private function closeAlienPackages(AccountTariffLog $accountTariffLog, $type)
    {
        foreach ($this->nextAccountTariffs as $nextAccountTariff) {
            if (!$nextAccountTariff->isActive()) {
                HandlerLogger::me()->add($nextAccountTariff->id . ' is off. Skip.');
                continue;
            }

            $nextTariffPeriod = $nextAccountTariff->getNotNullTariffPeriod();

            $toClose = false;
            if ($type == self::$TYPE_PACKAGE_BUNDLE && $nextTariffPeriod->tariff->is_default) {
                $toClose = true;
            } elseif ($type == self::$TYPE_PACKAGE_DEFAULT && $nextTariffPeriod->tariff->is_bundle) {
                $toClose = true;
            }

            if (!$toClose) {
                continue;
            }

            HandlerLogger::me()->add('off: (' . $nextAccountTariff->id . ') ' . ($nextAccountTariff->tariff_period_id ? $nextAccountTariff->tariffPeriod->getName() : '???') . ' - ' . $accountTariffLog->actual_from_utc);
            $nextAccountTariff->closeAccountTariff($accountTariffLog->actual_from_utc);
        }
    }

    /**
     * @return int[]
     */
    public function getPackageTariffStatuses()
    {
        if ($this->service_type_id != ServiceType::ID_VOIP) {
            return [TariffStatus::ID_PUBLIC];
        }

        $tariffStatuses = [];

        /** @var \app\models\Number $number */
        $number = $this->number;
        if (!$number) {
            // возможно, линия
            return $tariffStatuses;
        }

        /** @var ClientAccount $clientAccount */
        $clientAccount = $this->clientAccount;
        $priceLevel = $clientAccount->price_level;
        $didGroup = $number->didGroup;
        $tariffStatuses[] = $didGroup->getTariffStatusPackage($priceLevel); // пакет с учетом уровня цен
        $clientAccount->uu_tariff_status_id && $tariffStatuses[] = $clientAccount->uu_tariff_status_id; // пакет персонально клиенту
        if ($priceLevel >= DidGroup::MIN_PRICE_LEVEL_FOR_BEAUTY) {
            // только для ОТТ (см. ClientAccount::getPriceLevels)
            $tariffStatuses[] = $didGroup->tariff_status_beauty; // пакет за красивость
        }

        return $tariffStatuses;
    }

    /**
     * Есть ли существующий базовый пакет.
     *
     * @return bool|null
     */
    private function _hasDefaultPackage()
    {
        /** @var AccountTariff[] $nextAccountTariffs */
        $nextAccountTariffs = $this->nextAccountTariffs;
        foreach ($nextAccountTariffs as $nextAccountTariff) {

            if (!$nextAccountTariff->isActive()) {
                // закрытый
                continue;
            }

            /** @var TariffPeriod $tariffPeriod */
            $tariffPeriod = $nextAccountTariff->getNotNullTariffPeriod();
            if ($tariffPeriod->tariff->is_default) {
                return true;
            }
        }

        return null;
    }

    /**
     * Закрыть все пакеты.
     *
     * @throws \yii\db\StaleObjectException
     * @throws \app\exceptions\ModelValidationException
     * @throws \Exception
     */
    public static function closeAllPackages($params)
    {
        $accountTariffLog = AccountTariffLog::findOne(['id' => $params['account_tariff_log_id']]);
        if (!$accountTariffLog) {
            throw new \InvalidArgumentException('AccountTariffLog id: ' . $params['account_tariff_log_id'] . ' не найден');
        }

        $accountTariff = $accountTariffLog->accountTariff;

        if (!$accountTariff) {
            throw new \InvalidArgumentException('Услуга не найдена: ' . $accountTariffLog->account_tariff_id);
        }

        if ($accountTariffLog->tariff_period_id) {
            $text = 'Услуга ' . $accountTariffLog->account_tariff_id . ' закрыта, хотя не должна';
            HandlerLogger::me()->add($text);
            Yii::error($text, 'uu');
            return;
        }

        // закрыть все пакеты

        $accountTariff::getDb()->transaction(function($db) use ($accountTariff, $accountTariffLog) {
            /** @var AccountTariff[] $nextAccountTariffs */
            $nextAccountTariffs = $accountTariff->nextAccountTariffs;
            Yii::info('closeAllPackages at_id=' . $accountTariff->id
                . ' atl_id=' . $accountTariffLog->id
                . ' packages=' . implode(',', array_keys($nextAccountTariffs)), 'uu');
            foreach ($nextAccountTariffs as $nextAccountTariff) {
                Yii::info('closeAccountTariff package_id=' . $nextAccountTariff->id
                    . ' tp=' . $nextAccountTariff->tariff_period_id
                    . ' actual_from=' . $accountTariffLog->actual_from_utc, 'uu');
                $nextAccountTariff->closeAccountTariff($accountTariffLog->actual_from_utc);
            }
        });
    }


    /**
     * @param Tariff $tariff
     * @return AccountTariff
     * @throws ModelValidationException
     */
    public function _addPackage(Tariff $tariff, AccountTariffLog $accountTariffLog)
    {
        $tariffPeriods = $tariff->tariffPeriods;
        $tariffPeriod = reset($tariffPeriods);

        // подключить базовый пакет
        $accountTariffPackage = new AccountTariff();
        $accountTariffPackage->client_account_id = $this->client_account_id;
        $accountTariffPackage->service_type_id = $tariff->service_type_id;
        $accountTariffPackage->region_id = $this->region_id;
        $accountTariffPackage->city_id = $this->city_id;
        $accountTariffPackage->prev_account_tariff_id = $this->id;
        if (!$accountTariffPackage->save()) {
            throw new ModelValidationException($accountTariffPackage);
        }

        $accountTariffPackageLog = new AccountTariffLog();
        $accountTariffPackageLog->account_tariff_id = $accountTariffPackage->id;
        $accountTariffPackageLog->tariff_period_id = $tariffPeriod->id;
        $accountTariffPackageLog->actual_from_utc = $accountTariffLog->actual_from_utc;
        $accountTariffPackageLog->insert_time = $accountTariffLog->actual_from_utc; // чтобы не было лишнего списания
        if (!$accountTariffPackageLog->save()) {
            throw new ModelValidationException($accountTariffPackageLog);
        }

        return $accountTariffPackage;
    }

    // @TODO объединить с setClosed ("остаться должен только один")

    /**
     * @param AccountTariff $nextAccountTariff
     * @param string $actual_from_utc
     * @throws ModelValidationException
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    public function closeAccountTariff($actual_from_utc)
    {
        if (!$this->tariff_period_id) {
            // уже закрыт
            Yii::info('closeAccountTariff SKIP (already closed) id=' . $this->id, 'uu');
            return;
        }

        $nextAccountTariffLogs = $this->accountTariffLogs;
        $nextAccountTariffLog = reset($nextAccountTariffLogs);  // последняя смена тарифа (в начале desc-списка)
        Yii::info('closeAccountTariff id=' . $this->id
            . ' tp=' . $this->tariff_period_id
            . ' lastLog=' . ($nextAccountTariffLog ? $nextAccountTariffLog->id : 'null')
            . ' lastLogActual=' . ($nextAccountTariffLog ? $nextAccountTariffLog->actual_from_utc : 'null')
            . ' closeDate=' . $actual_from_utc, 'uu');
        if ($nextAccountTariffLog->actual_from_utc > $actual_from_utc) {
            // что-то есть в будущем - отменить и закрыть
            if (!$nextAccountTariffLog->delete()) {
                throw new ModelValidationException($nextAccountTariffLog);
            }
        } elseif ($nextAccountTariffLog->actual_from_utc == $actual_from_utc) {
            if (!$nextAccountTariffLog->tariff_period_id) {
                // и так должно быть закрытие. Ничего не делаем
                return;
            }

            // что?! смена на другой тариф?! отменить и закрыть
            if (!$nextAccountTariffLog->delete()) {
                throw new ModelValidationException($nextAccountTariffLog);
            }
        }

        // закрыть
        $nextAccountTariffLog = new AccountTariffLog();
        $nextAccountTariffLog->account_tariff_id = $this->id;
        $nextAccountTariffLog->tariff_period_id = null;
        $nextAccountTariffLog->actual_from_utc = $actual_from_utc;
        $nextAccountTariffLog->insert_time = $actual_from_utc; // чтобы не было лишнего списания
        if (!$nextAccountTariffLog->save($runValidation = false)) { // пакет не может работать без основной услуги. Поэтому закрыть и точка, что бы там проверки не говорили "уже оплачено" и прочее!
            throw new ModelValidationException($nextAccountTariffLog);
        }
    }

    /**
     * Отмена ресурса в будещем
     *
     * @param ResourceModel $resource
     * @return int|null
     */
    public function cancelResource(ResourceModel $resource)
    {
        if (!$this->isResourceCancelable($resource)) {
            throw new InvalidParamException('Ресурс невозможно отменить');
        }

        /** @var AccountTariffResourceLog[] $accountTariffResourceLogs */
        $accountTariffResourceLogs = $this->getAccountTariffResourceLogs($resource->id)->all();
        $accountTariffResourceLog = reset($accountTariffResourceLogs);

        if (!$accountTariffResourceLog->isResourceLogCancelable()) {
            throw new \LogicException('Ресурс невозможно отменить');
        }

        return $accountTariffResourceLog->deleteAppointmentsInTheFuture();
    }


    /**
     * @return string
     */
    public function getNextAccountTariffsAsString()
    {
        if ($this->nextAccountTariffs) {
            $strings = array_map(
                function (AccountTariff $nextAccountTariff) {
                    $string = Html::a(
                        Html::encode($nextAccountTariff->getName(false)),
                        $nextAccountTariff->getUrl()
                    );

                    if (!$nextAccountTariff->tariff_period_id) {
                        $string = Html::tag('strike', $string);
                    }

                    return $string;
                },
                $this->nextAccountTariffs
            );
            return implode('<br />', $strings);
        }

        return Yii::t('common', '(not set)');
    }

    /**
     * Вернуть кол-во потраченных минут по пакету минут
     *
     * @return array [[i_nnp_package_minute_id, i_used_seconds]]
     * @throws \yii\db\Exception
     */
    public function getMinuteStatistic()
    {
        /** @var AccountLogPeriod $accountLogPeriod */
        $accountLogPeriod = $this->accountLogPeriodLast;

        return $accountLogPeriod ? $accountLogPeriod->getMinutesSummaryAsArray() : [];
    }

    /**
     * Вернуть кол-во потраченных минут по ННП пакету минут
     *
     * @throws \yii\db\Exception
     */
    public function getPriceMinuteStatistic()
    {
        static $cache = [];

        if (!isset($cache[$this->prev_account_tariff_id])) {
            $_nnpPackageStat = \app\models\billing\StatsAccount::getStatsNnpPackageMinute($this->client_account_id, $this->prev_account_tariff_id);

            /**
             * "id": 43000921,
             * "account_tariff_id": 2421192,
             * "account_package_id": 2421199,
             * "name": "Комплект S 200 минут",
             * "used_seconds": 120,
             * "total_seconds": "1200"
             */

            $nnpPackageStat = [];

            array_walk($_nnpPackageStat, function ($v) use (&$nnpPackageStat) {
                $nnpPackageStat[$v['account_package_id']] = [
                    'used_seconds' => (int)$v['used_seconds'],
                    'total_seconds' => (int)$v['total_seconds'],
                ];
            });

            $cache[$this->prev_account_tariff_id] = $nnpPackageStat;
        }

        return $cache[$this->prev_account_tariff_id][$this->id] ?? [];
    }

    public function getInternetStatistic()
    {
        $internetStatistic = [];

        static $internetDataCache = [];
        $did = $this->prevAccountTariff->voip_number;

        if (!$did) {
            return $internetStatistic;
        }

        if (!isset($internetDataCache[$did])) {
            $statInternets = \app\models\billing\StatsAccount::getStatInternet($did);

            $alts = AccountTariffLight::find()
                ->where(['id' => array_map(function ($v) {
                    return $v['account_tariff_light_id'];
                }, $statInternets)])
                ->select('account_package_id')->indexBy('id')->column();

            foreach ($statInternets as $statInternet) {
                if (
                    !isset($statInternet['bytes_amount'])
                    || !isset($statInternet['bytes_consumed'])
                ) {
                    continue;
                }

                if (!isset($alts[$statInternet['account_tariff_light_id']])) {
                    continue;
                }

                $internetDataCache[$did][$alts[$statInternet['account_tariff_light_id']]] = [
                    'bytes_amount' => $statInternet['bytes_amount'],
                    'bytes_consumed' => $statInternet['bytes_consumed'],
                ];
            }
        }

        return $internetDataCache[$did][$this->id] ?? [];
    }

    public function getSmsStatistic()
    {
        static $cache = [];

        if (!isset($cache[$this->prev_account_tariff_id])) {
            $accountTariffSmsStat = StatsAccount::getStatSms($this->client_account_id, $this->prev_account_tariff_id);

            $smsStat = [];

            array_walk($accountTariffSmsStat, function ($v) use (&$smsStat) {
                $smsStat[$v['account_package_id']] = [
                    'amount' => $v['amount'],
                    'amount_package' => $v['amount_package'],
                    'used_sms' => $v['used_sms'],
                ];
            });

            $cache[$this->prev_account_tariff_id] = $smsStat;
        }

        return $cache[$this->prev_account_tariff_id][$this->id] ?? [];
    }


    /**
     * Прверяет конфигурацию пакетов в соответствии с текущим бандл-тарифом
     * @param array|integer $params AccountTariffId
     * @throws \yii\db\Exception
     */
    public static function actualizeBundlePackages($params)
    {
        if (($params['old_tariff_period_id'] ?? 0) == ($params['new_tariff_period_id'] ?? 0)) {
            HandlerLogger::me()->add('old=new');
            return;
        }

        $accountTariffId = $params['account_tariff_id'] ?? 0;
        $accountTariff = AccountTariff::findOne(['id' => $accountTariffId]);
        if (!$accountTariff) {
            throw new \InvalidArgumentException('Услуга не найдена: ' . $accountTariffId->account_tariff_id);
        }

        $accountTariffLog = self::_getAccountTariffLogByParam($params, $accountTariff);

        self::checkEventSuitable($accountTariff, $accountTariffLog);

        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $accountTariff->_checkBoundlePackages($accountTariffLog);
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    // Проверяем, последний ли лог тарифа мы обрабатываем.
    private static function checkEventSuitable(AccountTariff $accountTariff, AccountTariffLog $accountTariffLog)
    {
        $accountTariffLogs = $accountTariff->accountTariffLogs;
        /** @var AccountTariffLog $lastAccountTariffLog */
        $lastAccountTariffLog = reset($accountTariffLogs);
        if (!$lastAccountTariffLog || !$lastAccountTariffLog->tariff_period_id) {
            throw new \InvalidArgumentException('Ошибка получения логов включения по услуге: ' . $accountTariffLog->account_tariff_id);
        }

        if ($lastAccountTariffLog->tariff_period_id != $accountTariffLog->tariff_period_id && $accountTariffLog->id) {
            throw new \LogicException('Последнее включение лога тариф-периода не совпадает с тариф-периодом в задаче');
        }
    }

    private function _checkBoundlePackages(AccountTariffLog $accountTariffLog)
    {
        $packageType = ServiceType::$serviceToPackage[$this->service_type_id] ?? null;

        if (!$packageType) {
            return;
        }

        if (!$accountTariffLog->tariff_period_id) {
            HandlerLogger::me()->add('is off');
            return;
        }

        if (
            !$accountTariffLog->tariffPeriod
            || !$accountTariffLog->tariffPeriod->tariff
            || !$accountTariffLog->tariffPeriod->tariff->is_bundle) {
            HandlerLogger::me()->add('Tariff is not bundle');
            return;
        }

        $this->closeAlienPackages($accountTariffLog, self::$TYPE_PACKAGE_BUNDLE);


        // compare bundle package
        $tariff = $accountTariffLog->tariffPeriod->tariff;
        $bundleTariffs = [];
        foreach ($tariff->bundlePackages as $bundlePackage) {
            $bundleTariffPeriod = reset($bundlePackage->packageTariff->tariffPeriods); // нет механизма какой ТП брать
            $bundleTariffs[$bundleTariffPeriod->id] = $bundlePackage->packageTariff;
        }

        $needClose = [];
        foreach ($this->nextAccountTariffs as $nextAccountTariff) {
            if (!$nextAccountTariff->isActive()) {
                HandlerLogger::me()->add($nextAccountTariff->id . ' is off. Skip');
                // зачем нам уже отключенные. Включенные в будущем будут здесь
                continue;
            }

            $nextTariffPeriod = $nextAccountTariff->getNotNullTariffPeriod();

            if (!$nextTariffPeriod->tariff->is_bundle) {
                HandlerLogger::me()->add($nextAccountTariff->id . ' is not bundle. Skip');
                continue;
            }

            // package in this bundle
            if (isset($bundleTariffs[$nextTariffPeriod->id])) {
                HandlerLogger::me()->add($nextAccountTariff->id . ' package in this bundle. Skip');
                unset($bundleTariffs[$nextTariffPeriod->id]);
                continue;
            }

            $needClose[] = $nextAccountTariff;
        }

        if ($bundleTariffs) {
            foreach ($bundleTariffs as $tariffToAdd) {
//                HandlerLogger::me()->add($accountTariffLog->account_tariff_id . ': on: ' . $tariffToAdd->name . ' (' . $tariffToAdd->id . ') - ' . $tariff->name . '(' . $tariff->id . ')');
                $addedAccountTariff = $this->_addPackage($tariffToAdd, $accountTariffLog);
                HandlerLogger::me()->add($accountTariffLog->account_tariff_id . ': on: ' . $tariffToAdd->name . ' (' . $tariffToAdd->id . ') - ' . $tariff->name . '(' . $tariff->id . ') - at: ' . $addedAccountTariff->id);
            }
        }

        if ($needClose) {
            foreach ($needClose as $nextAccountTariff) {
                HandlerLogger::me()->add($accountTariffLog->account_tariff_id . ': off: (' . $nextAccountTariff->id . ') ' . ($nextAccountTariff->tariff_period_id ? $nextAccountTariff->tariffPeriod->getName() : '???') . ' - ' . $accountTariffLog->actual_from_utc);
                $nextAccountTariff->closeAccountTariff($accountTariffLog->actual_from_utc);
            }
        }
    }

}