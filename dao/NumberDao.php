<?php

namespace app\dao;

use app\classes\Assert;
use app\classes\Singleton;
use app\exceptions\ModelValidationException;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\AccountTariffLog;
use app\helpers\DateTimeZoneHelper;
use app\models\billing\CallsRaw;
use app\models\BusinessProcessStatus;
use app\models\ClientAccount;
use app\models\Number;
use app\models\NumberLog;
use app\models\UsageVoip;
use app\modules\uu\models\ServiceType;
use DateTime;
use DateTimeZone;
use Yii;
use yii\db\ActiveQuery;
use yii\db\Expression;

/**
 * @method static NumberDao me($args = null)
 */
class NumberDao extends Singleton
{

    /**
     * @param \app\models\Number $number
     * @param ClientAccount|null $clientAccount
     * @param DateTime|null $stopDate
     */
    public function startReserve(
        \app\models\Number $number,
        ClientAccount      $clientAccount = null,
        DateTime           $stopDate = null
    )
    {
        Assert::isInArray($number->status, [Number::STATUS_INSTOCK, Number::STATUS_NOTSALE]);

        $utc = new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC);

        $number->client_id = $clientAccount ? $clientAccount->id : null;
        $number->reserve_from = (new DateTime('now', $utc))->format(DateTimeZoneHelper::DATETIME_FORMAT);
        $number->reserve_till = $stopDate ? $stopDate->setTimezone($utc)->format(DateTimeZoneHelper::DATETIME_FORMAT) : null;
        $number->status = Number::STATUS_NOTACTIVE_RESERVED;
        $number->save();

        Number::dao()->log($number, NumberLog::ACTION_INVERTRESERVED, 'Y');
    }

    /**
     * @param \app\models\Number $number
     */
    public function stopReserve(\app\models\Number $number)
    {
        Assert::isEqual($number->status, Number::STATUS_NOTACTIVE_RESERVED);

        $number->reserve_from = null;
        $number->reserve_till = null;
        $number->save();

        Number::dao()->toInstock($number);
    }

    /**
     * @param \app\models\Number $number
     * @param UsageVoip|null $usage
     * @param AccountTariff|null $uuUsage
     */
    public function startActive(
        \app\models\Number $number,
        UsageVoip          $usage = null,
        AccountTariff      $uuUsage = null,
                           $isInFuture = false
    )
    {

        if ($usage) {
            $fieldsEqual =
                $number->usage_id == $usage->id &&
                $number->client_id == $usage->clientAccount->id;

            $number->usage_id = $usage->id;
            $number->client_id = $usage->clientAccount->id;
            if (!$isInFuture) {
                $isTest = $usage->tariff->isTest();
            }
        } else {
            // uuUsage
            $fieldsEqual =
                $number->uu_account_tariff_id == $uuUsage->id &&
                $number->client_id == $uuUsage->client_account_id;

            $number->uu_account_tariff_id = $uuUsage->id;
            $number->client_id = $uuUsage->client_account_id;
            if (!$isInFuture) {
                $isTest = $uuUsage->tariffPeriod->tariff->isTest;
            }
        }

        $newStatus = $isInFuture ?
            Number::STATUS_ACTIVE_CONNECTED :
            ($isTest ?
                Number::STATUS_ACTIVE_TESTED :
                Number::STATUS_ACTIVE_COMMERCIAL
            );

        if ($number->forced_status === Number::STATUS_BLOCKED_BY_SUBSCRIBER) {
            $newStatus = Number::STATUS_BLOCKED_BY_SUBSCRIBER;
        } elseif ($number->forced_status === Number::STATUS_BLOCKED_BY_OPERATOR) {
            $newStatus = Number::STATUS_BLOCKED_BY_OPERATOR;
        } elseif ($number->is_verified === 0) {
            $newStatus = Number::STATUS_NOT_VERFIED;
        } elseif ($number->is_in_msteams) {
            $newStatus = Number::STATUS_ACTIVE_MSTEAMS;
        }

        if ($fieldsEqual && $newStatus == $number->status) {
            return;
        }

        $number->reserve_from = null;
        $number->reserve_till = null;
        $number->hold_from = null;
        $number->hold_to = null;
        $number->status = $newStatus;

        $number->save();

        $logStatus = Number::STATUS_ACTIVE_TESTED;
        switch ($newStatus) {
            case Number::STATUS_ACTIVE_TESTED:
                $logStatus = NumberLog::ACTION_ADDITION_TESTED;
                break;
            case Number::STATUS_ACTIVE_COMMERCIAL:
                $logStatus = NumberLog::ACTION_ADDITION_COMMERCIAL;
                break;
            case Number::STATUS_NOT_VERFIED:
                $logStatus = NumberLog::ACTION_NOT_VERFIED;
                break;
            case Number::STATUS_ACTIVE_MSTEAMS:
                $logStatus = NumberLog::ACTION_MSTEAMS;
                break;
            case Number::STATUS_BLOCKED_BY_SUBSCRIBER:
                $logStatus = NumberLog::ACTION_BLOCKED_BY_SUBSCRIBER;
                break;
            case Number::STATUS_BLOCKED_BY_OPERATOR:
                $logStatus = NumberLog::ACTION_BLOCKED_BY_OPERATOR;
                break;
        }

        Number::dao()->log(
            $number,
            NumberLog::ACTION_ACTIVE,
            $logStatus
        );
    }

    /**
     * @param \app\models\Number $number
     */
    public function stopActive(\app\models\Number $number)
    {
        if (!in_array($number->status, Number::$statusGroup[Number::STATUS_GROUP_ACTIVE])) {
            return;
        }

        $number->forced_status = null;
        $number->is_verified = 1;

        if ($number->is_ported) {
            Number::dao()->toRelease($number);
            return;
        }

        $now = new DateTime('now', new DateTimeZone('UTC'));

        // Если тариф тестовый, то выкладываем номер минуя отстойник.
        // Найти последнюю закрытую услугу с этим номером
        /** @var UsageVoip $usage */
        $usage = UsageVoip::find()
            ->andWhere(['E164' => $number->number])
            ->andWhere(['<', 'actual_to', $now->format(DateTimeZoneHelper::DATE_FORMAT)])
            ->orderBy('actual_to desc')
            ->limit(1)
            ->one();

        /** @var AccountTariff $accountTariff */
        $accountTariff = AccountTariff::find()
            ->where([
                'voip_number' => $number->number,
                'tariff_period_id' => null
            ])
            ->andWhere(['>', 'id', AccountTariff::DELTA])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        $accountTariffLog = null;
        /** @var AccountTariffLog $accountTariffLog */
        if ($accountTariff) {
            $accountTariffLogs = $accountTariff->accountTariffLogs;
            $accountTariffLog = reset($accountTariffLogs);
            unset($accountTariffLogs);
        }

        $clientAccount = null;
        // если найден "старая" услуга, и не найдена uu-услуга, или uu-услуга старше "старой"
        if ($usage && (!$accountTariff || ($accountTariffLog && $accountTariffLog->actual_from < $usage->actual_to))) {
            $accountTariff = null;
            $clientAccount = $usage->clientAccount;
            // если найдена uu-услуга и её лог тариф, и не найдена "старая" услуга или uu-услуга новее.
        } elseif ($accountTariff && $accountTariffLog && (!$usage || $accountTariffLog->actual_from > $usage->actual_to)) {
            $usage = null;
            $clientAccount = $accountTariff->clientAccount;
        } else {
            $usage = $accountTariff = null;
        }

        if ($clientAccount && $clientAccount->contract->business_process_status_id == BusinessProcessStatus::TELEKOM_MAINTENANCE_TRASH) {
            Number::dao()->toInstock($number);
            return;
        }

        if ($usage && (!$usage->tariff || $usage->tariff->isTest())) {
            Number::dao()->toInstock($number);
            return;
        }

        if ($accountTariff) {
            $isLastTariffTest = false;
            foreach ($accountTariff->getAccountTariffLogs()->each() as $accountTariffLog) {
                if ($accountTariffLog->tariff_period_id) {
                    $isLastTariffTest = $accountTariffLog->tariffPeriod->tariff->isTest;
                    break;
                }
            }

            if ($isLastTariffTest) {
                Number::dao()->toInstock($number);
                return;
            }
        }

        Number::dao()->startHold($number);
    }

    /**
     * @param \app\models\Number $number
     * @throws ModelValidationException
     */
    public function toInstock(\app\models\Number $number, $isWithCheck = false)
    {
        if ($isWithCheck) {
            Assert::isNotInArray($number->status, [
                Number::STATUS_ACTIVE_TESTED,
                Number::STATUS_ACTIVE_COMMERCIAL,
                Number::STATUS_ACTIVE_CONNECTED,
                Number::STATUS_NOTACTIVE_RESERVED
            ]);
        }

        $number->client_id = null;
        $number->usage_id = null;
        $number->uu_account_tariff_id = null;
        $number->hold_from = null;
        $number->hold_to = null;

        $number->status = Number::STATUS_INSTOCK;
        if (!$number->save()) {
            throw new ModelValidationException($number);
        }

        Number::dao()->log($number, NumberLog::ACTION_INVERTRESERVED, 'N');
    }

    /**
     * @param \app\models\Number $number
     * @throws ModelValidationException
     */
    public function toRelease(\app\models\Number $number, $isWithCheck = false, $status = Number::STATUS_RELEASED)
    {
        if ($isWithCheck) {
            Assert::isNotInArray($number->status, [
                Number::STATUS_ACTIVE_TESTED,
                Number::STATUS_ACTIVE_COMMERCIAL,
                Number::STATUS_ACTIVE_CONNECTED,
                Number::STATUS_NOTACTIVE_RESERVED,
            ]);
        }

        Assert::isInArray($status, [Number::STATUS_RELEASED]);

        $number->client_id = null;
        $number->usage_id = null;
        $number->uu_account_tariff_id = null;
        $number->hold_from = null;
        $number->hold_to = null;

        $number->status = $status;
        if (!$number->save()) {
            throw new ModelValidationException($number);
        }

        if ($number->status == Number::STATUS_RELEASED) {
            Number::dao()->log($number, NumberLog::ACTION_CREATE, 'N');
        } else { // Number::STATUS_RELEASED_AND_PORTED
            Number::dao()->log($number, NumberLog::ACTION_CREATE, 'NP');
        }
    }

    /**
     * @param \app\models\Number $number
     * @throws \Exception
     */
    public function unRelease(\app\models\Number $number)
    {
        $transaction = \Yii::$app->db->beginTransaction();
        try {

            $number->client_id = null;
            $number->usage_id = null;
            $number->uu_account_tariff_id = null;
            $number->hold_from = null;
            $number->hold_to = null;

            $number->status = Number::STATUS_NOTSALE;
            if (!$number->save()) {
                throw new ModelValidationException($number);
            }

            Number::dao()->log($number, NumberLog::ACTION_UNRELEASE);

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollback();
            throw $e;
        }
    }

    /**
     * @param \app\models\Number $number
     * @param DateTime|null $holdTo
     */
    public function startHold(\app\models\Number $number, DateTime $holdTo = null)
    {
        Assert::isInArray($number->status, array_merge([Number::STATUS_INSTOCK, Number::STATUS_NOTACTIVE_HOLD], Number::$statusGroup[Number::STATUS_GROUP_ACTIVE]));

        $number->client_id = null;
        $number->usage_id = null;
        $number->uu_account_tariff_id = null;
        $number->status = Number::STATUS_NOTACTIVE_HOLD;

        $now = new DateTime('now', new DateTimeZone('UTC'));
        $number->hold_from = $now->format(DateTimeZoneHelper::DATETIME_FORMAT);

        if ($holdTo) {
            $number->hold_to = $holdTo->format(DateTimeZoneHelper::DATETIME_FORMAT);
        } else {
            $now->add($number->ndcType->getHold());
            $number->hold_to = $now->format(DateTimeZoneHelper::DATETIME_FORMAT);
        }

        $number->save();

        Number::dao()->log($number, NumberLog::ACTION_HOLD);
    }

    /**
     * @param \app\models\Number $number
     */
    public function stopHold(\app\models\Number $number)
    {
        Assert::isEqual($number->status, Number::STATUS_NOTACTIVE_HOLD);

        $number->status = Number::STATUS_INSTOCK;
        $number->hold_to = null;
        $number->save();

        Number::dao()->log($number, NumberLog::ACTION_UNHOLD);
    }

    /**
     * @param \app\models\Number $number
     */
    public function startNotSell(\app\models\Number $number)
    {
        Assert::isInArray($number->status, [Number::STATUS_INSTOCK, Number::STATUS_NOTACTIVE_HOLD]);

        $number->client_id = 764;
        $number->status = Number::STATUS_NOTSALE;
        $number->save();

        Number::dao()->log($number, NumberLog::ACTION_NOTSALE);
    }

    /**
     * @param \app\models\Number $number
     */
    public function stopNotSell(\app\models\Number $number)
    {
        Assert::isEqual($number->status, Number::STATUS_NOTSALE);

        $number->client_id = null;
        $number->status = Number::STATUS_INSTOCK;
        $number->save();

        Number::dao()->log($number, NumberLog::ACTION_SALE);
    }

    /**
     * @param \app\models\Number $number
     * @param string $action
     * @param string $addition
     */
    public function log(\app\models\Number $number, $action, $addition = null)
    {
        $row = new NumberLog();
        $row->e164 = $number->number;
        $row->action = $action;
        $row->client = $number->client_id;
        $row->user = Yii::$app->user->getId();
        $row->addition = $addition;
        $row->save();
    }

    /**
     * @param string $numberE164
     */
    public function actualizeStatusByE164($numberE164)
    {
        $number = Number::findOne(['number' => $numberE164]);
        if ($number) {
            $this->actualizeStatus($number);
        }
    }

    /**
     * @param \app\models\Number $number
     * @throws \Exception
     */
    public function actualizeStatus(\app\models\Number $number)
    {
        $isInFuture = false;

        /** @var UsageVoip $usage */
        $usage = UsageVoip::find()
            ->phone($number->number)
            ->actual()
            ->one();

        if (!$usage) {
            ($usage = UsageVoip::find()
                ->phone($number->number)
                ->inFuture()
                ->one()) && $isInFuture = true;
        }

        $universalUsage = null;

        if (!$usage) {
            $universalUsage = AccountTariff::find()
                ->where([
                    'voip_number' => $number->number,
                    'service_type_id' => ServiceType::ID_VOIP,
                ])
                ->andWhere(['NOT', ['tariff_period_id' => null]])
                ->one();
            if (!$universalUsage) {
                $universalUsage = $this->getActiveAccountTariffByNumber($number, $isInFuture);
            }
        }


        if ($usage) {
            Number::dao()->startActive($number, $usage, null, $isInFuture);
        } elseif ($universalUsage) {
            Number::dao()->startActive($number, null, $universalUsage, $isInFuture);
        } else {
            Number::dao()->stopActive($number);
        }
    }

    /**
     * Получаем услугу по номеру
     *
     * @param Number $number
     * @return AccountTariff|null
     * @throws \Exception
     */
    public function getActiveAccountTariffByNumber(Number $number, bool &$inFuture): ?AccountTariff
    {
        /** @var AccountTariff $universalUsage */
        $universalUsage = AccountTariff::find()
            ->where([
                'voip_number' => $number->number,
                'service_type_id' => ServiceType::ID_VOIP,
            ])
            ->andWhere(['NOT', ['tariff_period_id' => null]])
            ->one();

        if ($universalUsage) {
            return $universalUsage;
        }

        $now = DateTimeZoneHelper::getUtcDateTime();

        // is connecting progress
        if (in_array($number->status, Number::$statusGroup[Number::STATUS_GROUP_ACTIVE]) && $number->uu_account_tariff_id) {

            /** @var AccountTariff $universalUsage */
            $universalUsage = AccountTariff::find()->where([
                'id' => $number->uu_account_tariff_id,
                'service_type_id' => ServiceType::ID_VOIP,
            ])->one();

            if ($logs = $universalUsage->accountTariffLogs) {

                /** @var AccountTariffLog $log */
                if ($log = reset($logs)) {
                    if ($log->tariff_period_id && $log->actual_from_utc < $now) { // still not turned on
                        $inFuture = true;
                        return $universalUsage;
                    }
                }
            }
        }

        // in future
        $accountTariffs = AccountTariff::find()
            ->where([
                'voip_number' => $number->number,
                'service_type_id' => ServiceType::ID_VOIP,
                'tariff_period_id' => null,
            ])
            ->with('accountTariffLogs')
            ->all();

        /** @var AccountTariff $accountTariff */
        foreach ($accountTariffs as $accountTariff) {
            echo PHP_EOL . $accountTariff->id;

            /** @var AccountTariffLog $log */
            if ($logs = $accountTariff->accountTariffLogs) {
                if ($log = reset($logs)) {
                    if ($log->actual_from_utc > $now) {
                        $inFuture = true;
                        return $accountTariff;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Статистика по звонкам за последние 3 месяца
     *
     * @param int $region
     * @param int $dstNumber
     * @return array
     * @throws \yii\db\Exception
     */
    public function getCallsWithoutUsages($region, $dstNumber = null)
    {
        return $this->getCallsWithoutUsagesQuery($region, $dstNumber)
            ->createCommand()
            ->cache(86400)
            ->queryAll();
    }

    /**
     * Статистика по звонкам за заданный период (если не задано, то за 3 последних месяца)
     *
     * @param int $region
     * @param int $dstNumber
     * @param \DateTime|\DateTimeImmutable $dtFrom
     * @param \DateTime|\DateTimeImmutable $dtTo
     * @return ActiveQuery
     */
    public function getCallsWithoutUsagesQuery($region, $dstNumber = null, $dtFrom = null, $dtTo = null)
    {
        if (!$dtFrom) {
            $dtFrom = new \DateTime("now", new \DateTimeZone("UTC"));
            $dtFrom->modify("first day of -3 month, 00:00:00");
        }

        $subQuery = CallsRaw::find()
            ->select([
                'dst_number',
                'd' => (new Expression("DATE_TRUNC('day', connect_time)")),
                'total_calls' => (new Expression('count(*)')),
                'unique_calls' => (new Expression('count(DISTINCT COALESCE(src_number, 0))'))
            ])
            ->andWhere([
                'number_service_id' => null,
                'orig' => false
            ])
            ->groupBy(['dst_number', 'd']);
        
        if ($dtTo) {
            $subQuery->andWhere(['BETWEEN', 'connect_time', $dtFrom->format(DateTimeZoneHelper::DATETIME_FORMAT), $dtTo->format(DateTimeZoneHelper::DATETIME_FORMAT)]);
        } else {
            $subQuery->andWhere(['>=', 'connect_time', $dtFrom->format(DateTimeZoneHelper::DATETIME_FORMAT)]);
        }

        if ($region == 99) {
            $subQuery->andWhere(
                ['between', 'dst_number', 74950000000, 74999000000]
            );
        }

        if ($region){
            $subQuery->andWhere(['server_id' => $region]);
        }
        
        if ($dstNumber) {
            $subQuery->andWhere(['dst_number' => $dstNumber]);
        } 

        $subQuery->andWhere(
            ['between', 'dst_number', 70000000000, 80000000000]
        );

        $query = CallsRaw::find()
            ->select([
                'u' => 'dst_number',
                'c' => (new Expression('SUM(total_calls)')),
                'uc' => (new Expression('SUM(unique_calls)')),
            ])
            ->from(['daily_counts' => $subQuery])
            ->groupBy(['u']);
    
        return $query;
    }
    

    /**
     * Вернуть список статусов
     *
     * @param bool $isWithEmpty
     * @return array
     */
    public function getStatusList($isWithEmpty = false)
    {
        $list = \app\models\Number::$statusList;

        if ($isWithEmpty) {
            $list = ['' => '----'] + $list;
        }

        return $list;
    }

    /**
     * Получаем лог изменений состояния номера
     *
     * @param \app\models\Number $number
     * @return array
     * @throws \yii\db\Exception
     */
    public function getChangeStateLog(\app\models\Number $number)
    {
        return
            Yii::$app->db->createCommand("
                select
                    date_format(`es`.`time`,'%Y-%m-%d %H:%i:%s') `human_time`,
                    `uu`.`user`,
                    `es`.`user` `user_id`,
                    `cl`.`client`,
                    `es`.`client` `client_id`,
                    `es`.`addition`,
                    `es`.`action`
                from `e164_stat` `es`
                left join `clients` `cl` on `cl`.`id` = `es`.`client`
                left join `user_users` `uu` on `uu`.`id` = `es`.`user`
                where `es`.`e164`= :did
                order by `es`.`time` desc, es.pk desc
            ", [
                ':did' => $number->number
            ])->queryAll();
    }

    public function updateDiscountStatus()
    {
        $transaction = Number::getDb()->beginTransaction();
        try {
            $this->_toDiscount();
            $this->_fromDiscount();

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();

            throw $e;
        }
    }

    private function _toDiscount()
    {
        $query = Number::find()
            ->where(['is_with_discount' => 0])
            ->andWhere(new Expression('(COALESCE(unique_calls_per_month_1, 0) + COALESCE(unique_calls_per_month_2, 0) + COALESCE(unique_calls_per_month_3, 0)) > ' . Number::COUNT_CALLS_FOR_DISCOUNT));

        /** @var Number $number */
        foreach ($query->each() as $number) {

            echo PHP_EOL . '(+) ' . $number;
            $number->is_with_discount = 1;

            if (!$number->save()) {
                throw new ModelValidationException($number);
            }
            Number::dao()->log($number, NumberLog::ACTION_WITH_DISCOUNT);
        }
    }

    private function _fromDiscount()
    {
        $query = Number::find()
            ->where(['is_with_discount' => 1])
            ->andWhere(new Expression('(COALESCE(unique_calls_per_month_1, 0) + COALESCE(unique_calls_per_month_2, 0) + COALESCE(unique_calls_per_month_3, 0)) <= ' . Number::COUNT_CALLS_FOR_DISCOUNT));

        /** @var Number $number */
        foreach ($query->each() as $number) {

            echo PHP_EOL . '(-) ' . $number;
            $number->is_with_discount = 0;

            if (!$number->save()) {
                throw new ModelValidationException($number);
            }
            Number::dao()->log($number, NumberLog::ACTION_NO_DISCOUNT);
        }
    }
}
