<?php

namespace app\modules\uu\tarificator;

use app\helpers\DateTimeZoneHelper;
use app\models\HistoryChanges;
use app\models\User;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\AccountTariffLog;
use app\modules\uu\models\ServiceType;
use app\modules\uu\models\Tariff;
use app\modules\uu\models\TariffPeriod;
use app\widgets\ConsoleProgress;
use Yii;
use yii\base\InvalidParamException;

/**
 * Автоматически закрыть услугу по истечению тестового периода
 * Лучше вызывать по крону. Триггером запускать не надо, иначе нельзя будет отменить закрытие и указать другой тариф вручную
 */
class AutoCloseAccountTariffTarificator extends Tarificator
{
    /**
     * @param int|null $accountTariffId Если указан, то только для этой услуги. Если не указан - для всех
     * @param bool $isWithTransaction
     * @throws \Exception
     */
    public function tarificate($accountTariffId = null, $isWithTransaction = true)
    {
        $db = Yii::$app->db;
        $accountTariffTableName = AccountTariff::tableName();
        $tariffPeriodTableName = TariffPeriod::tableName();
        $tariffTableName = Tariff::tableName();
        $accountTariffLogTableName = AccountTariffLog::tableName();
        $historyChangesTableName = HistoryChanges::tableName();

        $sql = <<<SQL
                SELECT
                    account_tariff.id
                FROM
                    {$accountTariffTableName} account_tariff,
                    {$tariffPeriodTableName} tariff_period,
                    {$tariffTableName} tariff
                WHERE
                    account_tariff.tariff_period_id = tariff_period.id
                    AND tariff_period.tariff_id = tariff.id
                    AND tariff.is_autoprolongation = 0
SQL;
        if ($accountTariffId) {
            $sql .= " AND account_tariff.id = {$accountTariffId} ";
        }

        $query = $db->createCommand($sql)
            ->query();

        $utcTimezone = new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC);

        $progress = new ConsoleProgress($query->count(), function ($string) {
            $this->out($string);
        });
        foreach ($query as $row) {
            $progress->nextStep();

            $transaction = null;
            try {

                $accountTariff = AccountTariff::findOne(['id' => $row['id']]);
                $clientTimezone = $accountTariff->clientAccount->getTimezone();

                $accountLogHugePeriods = $accountTariff->getAccountLogHugeFromToTariffs($isWithFuture = true);
                $accountLogHugePeriod = end($accountLogHugePeriods); // последний

                $tariffPeriod = $accountLogHugePeriod->tariffPeriod;
                if (!$tariffPeriod) {
                    // уже закрыт. Вообще то сюда не должны попасть, потому что выше есть проверка
                    continue;
                }

                $tariff = $tariffPeriod->tariff;
                if ($tariff->is_autoprolongation) {
                    // уже сменили на нетестовый. Вообще то сюда не должны попасть, потому что выше есть проверка
                    continue;
                }

                if ($accountLogHugePeriod->dateTo) {
                    // тестовый тариф уже запланировали закрыть
                    continue;
                }

                $isWithTransaction && $transaction = Yii::$app->db->beginTransaction();

                $chargePeriod = $tariffPeriod->chargePeriod;
                $dateFrom = $accountLogHugePeriod->dateFrom;
                for ($i = 0; $i <= $tariff->count_of_validity_period; $i++) {
                    $dateTo = $chargePeriod->monthscount ? $dateFrom->modify('last day of this month') : $dateFrom;
                    // начать новый период
                    $dateFrom = $dateTo->modify('+1 day');
                }

                // нельзя закрывать в прошлом, иначе пробиллингованные периоды дадут ошибку
                $dateFromNow = (new \DateTimeImmutable())->setTime(0, 0);
                if ($dateFrom < $dateFromNow) {
                    // закрыть сегодняшним числом
                    $dateFrom = $dateFromNow;
                }

                // в $dateFrom таймзона явно не указана - значит, UTC без таймзоны клиента
                // а надо UTC по таймзоне клиента
                $dateFromUtc = (new \DateTimeImmutable(
                    $dateFrom->format(DateTimeZoneHelper::DATE_FORMAT),
                    $clientTimezone)
                )
                    ->setTimezone($utcTimezone);

                // через модель не надо, иначе сработают триггеры и пересчет запустится рекурсивно.
                // поскольку запуск по крону, то он и так все сразу пересчитает
                $accountTariffLogFields = [
                    'account_tariff_id' => $accountTariff->id,
                    'tariff_period_id' => null,
                    'actual_from_utc' => $dateFromUtc
                        ->format(DateTimeZoneHelper::DATETIME_FORMAT),
                    'insert_time' => new \yii\db\Expression('NOW()'),
                ];
                $affectedRows = $db->createCommand()
                    ->insert($accountTariffLogTableName, $accountTariffLogFields)
                    ->execute();
                if (!$affectedRows || !($accountTariffLogId = $db->getLastInsertID())) {
                    throw new InvalidParamException('Ошибка добавления закрытия услуги ' . $accountTariff->id);
                }

                // записать в историю
                $queryData = [
                    'model' => (new AccountTariffLog())->getClassName(),
                    'model_id' => $accountTariffLogId,
                    'parent_model_id' => $accountTariff->id,
                    'user_id' => User::SYSTEM_USER_ID,
                    'created_at' => date(DateTimeZoneHelper::DATETIME_FORMAT),
                    'action' => HistoryChanges::ACTION_INSERT,
                    'data_json' => json_encode($accountTariffLogFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'prev_data_json' => json_encode(null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
                Yii::$app->dbHistory->createCommand()
                    ->insert($historyChangesTableName, $queryData)
                    ->execute();

                // закрыть пакеты, т.к. raw SQL не вызывает EVENT_AFTER_INSERT
                if (isset(ServiceType::$serviceToPackage[$accountTariff->service_type_id])) {
                    AccountTariff::closeAllPackages([
                        'account_tariff_log_id' => $accountTariffLogId,
                    ]);
                }

                $isWithTransaction && $transaction->commit();
            } catch (\Exception $e) {
                $isWithTransaction && $transaction && $transaction->rollBack();
                $this->out(PHP_EOL . 'Error. ' . $e->getMessage() . PHP_EOL);
                Yii::error($e->getMessage());
                if ($accountTariffId) {
                    throw $e;
                }
            }
        }
    }
}
