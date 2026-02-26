<?php

namespace app\commands\convert;

use app\models\EventQueue;
use app\modules\nnp\models\NdcType;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\ServiceType;
use yii\console\Controller;

class VoipImsiController extends Controller
{
    /**
     * Конвертация IMSI/ICCID из voip_numbers/sim_imsi в общее хранилище данных внутри VoIP услуги
     */
    public function actionIndex()
    {
        $query = AccountTariff::find()
            ->alias('at')
            ->where(['at.service_type_id' => ServiceType::ID_VOIP])
            ->innerJoinWith(['number vn'])
            ->andWhere(['vn.ndc_type_id' => NdcType::ID_MOBILE])
            ->orderBy(['at.id' => SORT_ASC]);

        $processed = 0;
        $skipped = 0;
        $unchanged = 0;
        $errors = 0;

        /** @var AccountTariff $accountTariff */
        foreach ($query->each() as $accountTariff) {
            $number = $accountTariff->number;

            if (!$number || !$number->imsi) {
                echo '-';
                $skipped++;
                continue;
            }

            $imsi = $number->imsi;
            $imsiModel = $number->imsiModel;
            $iccid = $imsiModel ? $imsiModel->iccid : null;

            if ($accountTariff->imsi == $imsi && $accountTariff->iccid == $iccid) {
                echo '.';
                $unchanged++;
                continue;
            }

            $accountTariff->imsi = $imsi;
            if ($iccid) {
                $accountTariff->iccid = $iccid;
            }

            if (!$accountTariff->save(false)) {
                echo '!';
                $errors++;
                continue;
            }

            EventQueue::go(EventQueue::STATE_VOIP_UPDATE, [
                'client_account_id' => $accountTariff->client_account_id,
                'account_tariff_id' => $accountTariff->id,
                'number' => $accountTariff->voip_number,
            ]);

            echo '+';
            $processed++;
        }

        echo PHP_EOL;
        echo "processed: {$processed}, skipped: {$skipped}, unchanged: {$unchanged}, errors: {$errors}" . PHP_EOL;
    }
}
