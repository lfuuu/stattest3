<?php

namespace app\modules\uu\behaviors;

use app\classes\model\ActiveRecord;
use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\usages\UsageInterface;
use app\models\UsageVirtpbx;
use app\models\UsageVoip;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\ServiceType;
use DateTime;
use DateTimeZone;
use yii\base\Behavior;
use yii\base\Event;
use yii\base\InvalidConfigException;


class AccountTariffTransferClean extends Behavior
{
    /**
     * @return array
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_DELETE => 'cleanTransfer',
        ];
    }

    /**
     * Если отменена услуга, и она была перенесенной - то отменить перенос.
     *
     * @param Event $event
     * @throws ModelValidationException
     * @throws \Exception
     */
    public function cleanTransfer(Event $event)
    {
        /** @var AccountTariff $accountTariff */
        $accountTariff = $event->sender;

        try {
            if (!$accountTariff->prev_usage_id) {
                return;
            }

            if ($accountTariff->prev_usage_id > AccountTariff::DELTA) {
                // Источник — УУ-услуга (AccountTariff)
                $this->_cleanUuTransfer($accountTariff);
                return;
            }

            // Источник — обычная услуга (UsageVoip / UsageVirtpbx)
            if ($accountTariff->service_type_id == ServiceType::ID_VOIP || $accountTariff->service_type_id == ServiceType::ID_VPBX) {

                if ($accountTariff->service_type_id == ServiceType::ID_VOIP) {
                    $usage = UsageVoip::findOne(['id' => $accountTariff->prev_usage_id]);
                } else {
                    $usage = UsageVirtpbx::findOne(['id' => $accountTariff->prev_usage_id]);
                }

                if (!$usage) {
                    throw new InvalidConfigException('prev account tariff is not found');
                }

                $usage->next_usage_id = 0;
                $usage->actual_to = UsageInterface::MAX_POSSIBLE_DATE;

                if (!$usage->save()) {
                    throw new ModelValidationException($usage);
                }
            }
        } catch (InvalidConfigException $e) {
            return;
        } catch (\Exception $e) {
            throw $e;
        }

    }

    /**
     * Откат закрытия УУ-источника при удалении перенесённой УУ-услуги.
     *
     * @param AccountTariff $accountTariff
     * @throws ModelValidationException
     * @throws \Throwable
     */
    private function _cleanUuTransfer(AccountTariff $accountTariff)
    {
        $sourceAccountTariff = AccountTariff::findOne(['id' => $accountTariff->prev_usage_id]);
        if (!$sourceAccountTariff) {
            return;
        }

        // Последний лог источника должен быть закрытием в будущем
        $logs = $sourceAccountTariff->accountTariffLogs; // упорядочены по id DESC
        $lastLog = reset($logs);

        $nowUtc = (new DateTime('now', $sourceAccountTariff->clientAccount->getTimezone()))
            ->setTimezone(new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC))
            ->format(DateTimeZoneHelper::DATETIME_FORMAT);

        if (
            !$lastLog
            || $lastLog->tariff_period_id !== null
            || $lastLog->actual_from_utc <= $nowUtc
        ) {
            return;
        }

        if (!$lastLog->delete()) {
            throw new ModelValidationException($lastLog);
        }
    }

}
