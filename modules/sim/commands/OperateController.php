<?php

namespace app\modules\sim\commands;

use app\helpers\DateTimeZoneHelper;
use app\models\Country;
use app\models\EventQueue;
use app\modules\nnp\models\NdcType;
use app\classes\Utils;
use app\modules\sim\models\Imsi;
use app\modules\sim\models\ImsiExternalStatusLog;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\ServiceType;
use yii\console\Controller;
use yii\db\Expression;

class OperateController extends Controller
{
    /**
     * Проверям на подключение IMSI к моб. номеру
     */
    public function actionCheckMobNumbers($accountId = false)
    {
        $accountTariffQuery = AccountTariff::find()
            ->alias('at')
            ->where([
                'at.service_type_id' => ServiceType::ID_VOIP,
                'vn.ndc_type_id' => NdcType::ID_MOBILE,
                'c.country_id' => Country::RUSSIA,
            ])
            ->andWhere(['not', ['at.tariff_period_id' => null]])
            ->andWhere(new Expression("JSON_EXTRACT(at.calltracking_params, '$.imsi') IS NULL"))
            ->innerJoinWith(['number vn'])
            ->innerJoinWith(['number.city c']);

        if ($accountId) {
            $accountTariffQuery->andWhere(['at.client_account_id' => $accountId]);
        }

        /** @var AccountTariff $accountTariff */
        foreach ($accountTariffQuery->each() as $accountTariff) {
            echo PHP_EOL . $accountTariff->id . ': ' . $accountTariff->voip_number;

            EventQueue::go(EventQueue::SYNC_TELE2_GET_IMSI, [
                'account_tariff_id' => $accountTariff->id,
                'voip_number' => $accountTariff->voip_number,
            ]);

            echo '+';
        }
    }

    /**
     * Запускаем цикл получения статусов IMSI в Tele2
     */
    public function actionRunGetImsiStatusLoop()
    {
        $countError = 0;
        do {
            $imsies = Imsi::dao()->getImsiesForGetStatus();

            foreach ($imsies as $imsi) {
                echo PHP_EOL . date(DateTimeZoneHelper::DATETIME_FORMAT) . ': ' . $imsi . ': ';
                try {
                    Imsi::dao()->getSubscriberStatus($imsi, $isSaveResultToLog = true, $isSilentWhenSaving = false);

                    $imsi = Imsi::findOne(['imsi' => $imsi]);
                    $log = $imsi->getExternalStatusLog()->orderBy(['id' => SORT_DESC])->one();
                    if ($log) {
                        echo $log;
                    }

                } catch (\Exception $e) {
                    \Yii::error($e);
                    $countError++;
                    echo PHP_EOL . 'Error: ' . $countError . ' => ' . $e->getMessage();

                    if ($countError >= 3) {
                        break 2;
                    }
                }
                sleep(3);
            }
        } while (true);
        echo PHP_EOL . 'exit';
    }

    /**
     * Замена дублирующихся записей в sim_imsi_external_status_log на ref-маркер
     */
    public function actionCleanExternalStatusLog()
    {
        $db = ImsiExternalStatusLog::getDb();
        $table = ImsiExternalStatusLog::tableName();
        $refJson = Utils::toJson(ImsiExternalStatusLog::REF_STATUS);

        $imsies = $db->createCommand("SELECT DISTINCT imsi FROM {$table} ORDER BY imsi")->queryColumn();
        echo 'IMSI count: ' . count($imsies) . PHP_EOL;

        $totalConverted = 0;

        foreach ($imsies as $imsi) {
            $rows = $db->createCommand(
                "SELECT id, status FROM {$table} WHERE imsi = :imsi ORDER BY id ASC",
                [':imsi' => $imsi]
            )->queryAll();

            $lastFullStatus = null;
            $convertedIds = [];

            foreach ($rows as $row) {
                $statusNorm = json_encode(json_decode($row['status'], true));

                if ($statusNorm === json_encode(ImsiExternalStatusLog::REF_STATUS)) {
                    continue;
                }

                if ($lastFullStatus !== null && $statusNorm === $lastFullStatus) {
                    $convertedIds[] = $row['id'];
                } else {
                    $lastFullStatus = $statusNorm;
                }
            }

            if ($convertedIds) {
                $db->createCommand()->update(
                    $table,
                    ['status' => new Expression("'{$refJson}'::jsonb")],
                    ['id' => $convertedIds]
                )->execute();
                $totalConverted += count($convertedIds);
            }

            echo $imsi . ': converted ' . count($convertedIds) . PHP_EOL;
        }

        echo PHP_EOL . 'Total converted: ' . $totalConverted . PHP_EOL;
    }
}
