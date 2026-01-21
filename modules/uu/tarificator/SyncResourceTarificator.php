<?php

namespace app\modules\uu\tarificator;

use app\helpers\DateTimeZoneHelper;
use app\models\EventQueue;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\AccountTariffResourceLog;
use app\modules\uu\models\ResourceModel;
use app\modules\uu\models\ServiceType;
use app\modules\uu\models\Tariff;
use Yii;
use yii\db\Expression;

/**
 * Отправить измененные ресурсы на платформу и другим поставщикам услуг
 */
class SyncResourceTarificator extends Tarificator
{
    /**
     * @param int|null $accountTariffId Если указан, то только для этой услуги. Если не указан - для всех
     * @param bool $isWithTransaction
     * @throws \Exception
     */
    public function tarificate($accountTariffId = null, $isWithTransaction = true)
    {
        $db = Yii::$app->db;

        // найти все услуги, у которых есть хоть один несинхронизированный ресурс
        $query = AccountTariffResourceLog::find()
            ->select(new Expression('DISTINCT account_tariff_id AS id'))
            ->where(['sync_time' => null])
            ->andWhere(['<=', 'actual_from_utc', DateTimeZoneHelper::getUtcDateTime()->format(DateTimeZoneHelper::DATETIME_FORMAT)])
            ->andWhere($accountTariffId ? ['account_tariff_id' => $accountTariffId] : [])
            ->asArray();

        foreach ($query->each() as $row) {

            $accountTariff = AccountTariff::findOne(['id' => $row['id']]);

            $isWithTransaction && $transaction = $db->beginTransaction();
            try {

                $accountTariffResourceLogs = $accountTariff->setResourceSyncTime();

                // только по незакрытым услугам
                if ($accountTariff->isActive()) {

                    // в зависимости от типа услуги
                    switch ($accountTariff->service_type_id) {

                        case ServiceType::ID_VOIP:
                            // Телефония
                            self::doSyncResources($accountTariff);
                            break;

                        case ServiceType::ID_VPBX:
                            // ВАТС
                            EventQueue::go(\app\modules\uu\Module::EVENT_RESOURCE_VPBX, [
                                'client_account_id' => $accountTariff->client_account_id,
                                'account_tariff_id' => $accountTariff->id,
                            ]);
                            break;

                        case ServiceType::ID_VPS:
                            // VPS
                            EventQueue::go(\app\modules\uu\Module::EVENT_RESOURCE_VPS, [
                                'client_account_id' => $accountTariff->client_account_id,
                                'account_tariff_id' => $accountTariff->id,
                                'account_tariff_resource_ids' => array_keys($accountTariffResourceLogs),
                            ]);
                            break;

                        case ServiceType::ID_VOICE_ROBOT:
                            EventQueue::go(\app\modules\uu\Module::EVENT_ROBOCALL_INTERNAL_UPDATE, [
                                'client_account_id' => $accountTariff->client_account_id,
                                'account_tariff_id' => $accountTariff->id,
                            ]);
                            break;
                    }
                }

                $isWithTransaction && $transaction->commit();

            } catch (\Exception $e) {
                $isWithTransaction && $transaction->rollBack();
                $this->out(PHP_EOL . 'Error. ' . $e->getMessage() . PHP_EOL);
                Yii::error($e);
                if ($accountTariffId) {
                    throw $e;
                }
            }
        }
    }

    public static function doSyncResources(AccountTariff $accountTariff)
    {
        $number = $accountTariff->number;

        EventQueue::go(\app\modules\uu\Module::EVENT_RESOURCE_VOIP, [
            'client_account_id' => $accountTariff->client_account_id,
            'account_tariff_id' => $accountTariff->id,
            'number' => $accountTariff->voip_number,
            'lines' => $accountTariff->getResourceValue(ResourceModel::ID_VOIP_LINE),
            'is_fmc_active' => $accountTariff->getResourceValue(ResourceModel::ID_VOIP_FMC), //($number ? ($number->isFmcAlwaysActive() || (!$number->isFmcAlwaysInactive() && $accountTariff->getResourceValue(ResourceModel::ID_VOIP_FMC))) : null),
            'is_fmc_editable' => ($number ? $number->isFmcEditable() : null),
            'is_mobile_outbound_active' => ($number ? ($number->isMobileOutboundAlwaysActive() || (!$number->isMobileOutboundAlwaysInactive() && $accountTariff->getResourceValue(ResourceModel::ID_VOIP_MOBILE_OUTBOUND))) : null),
            'is_mobile_outbound_editable' => ($number ? $number->isMobileOutboundEditable() : null),
            'is_robocall_enabled' => ($accountTariff->tariff_period_id && $accountTariff->tariffPeriod->tariff->isAutodial()) || $accountTariff->getResourceValue(ResourceModel::ID_VOIP_ROBOCALL),
            'is_smart' => $accountTariff->getResourceValue(ResourceModel::ID_VOIP_IS_SMART),
            'is_geo_substitute' => $accountTariff->getResourceValue(ResourceModel::ID_VOIP_GEO_REPLACE),
            'is_for_siptrunk_or_vpbx_only' => $accountTariff->getResourceValue(ResourceModel::ID_VOIP_ONLY_FOR_TRUNK_VATS),
            'is_call_record' => $accountTariff->getResourceValue(ResourceModel::ID_VOIP_CALL_RECORDING),
        ]);
    }
}
