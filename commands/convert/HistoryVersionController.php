<?php

namespace app\commands\convert;

use app\exceptions\ModelValidationException;
use app\models\Bill;
use app\models\ClientAccount;
use app\models\HistoryVersion;
use app\models\User;
use yii\console\Controller;

class HistoryVersionController extends Controller
{
    /**
     * Восстановить записи HistoryVersion для ClientAccount.is_postpaid.
     * 6 февраля 2026 через базу было сделано обновление напрямую is_postpaid: 0 -> 2, без создания записей в истории.
     *
     * @param string|null $apply без параметра — просмотр, с любым параметром — применение
     */
    public function actionRestoreIsPostpaid($apply = null)
    {

        $changeDate = '2026-02-06';
        $modelClass = ClientAccount::class;
        $halfYearAgo = date('Y-m-d', strtotime('-6 months'));

        $accountsQuery = ClientAccount::find()
//            ->where(['is_postpaid' => ClientAccount::PAYMENT_TYPE_PREPAID_2]);
            ->innerJoin(Bill::tableName(), Bill::tableName() . '.client_id = ' . ClientAccount::tableName() . '.id')
            ->andWhere([ClientAccount::tableName() . '.is_postpaid' => ClientAccount::PAYMENT_TYPE_PREPAID_2])
            ->andWhere(['>=', Bill::tableName() . '.bill_date', $halfYearAgo])
            ->groupBy(ClientAccount::tableName() . '.id')
            ->orderBy(ClientAccount::tableName() . '.id');

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($accountsQuery->each() as $account) {
            /** @var ClientAccount $account */
            $existing = HistoryVersion::findOne([
                'model' => $modelClass,
                'model_id' => $account->id,
                'date' => $changeDate,
            ]);

            if ($existing) {
                $data = json_decode($existing->data_json, true);

                if (isset($data['is_postpaid']) && (int)$data['is_postpaid'] === ClientAccount::PAYMENT_TYPE_PREPAID_2) {
                    $skipped++;
                    continue;
                }

                // На эту дату уже есть запись — обновляем is_postpaid в data_json
                $data['is_postpaid'] = ClientAccount::PAYMENT_TYPE_PREPAID_2;

                echo "UPDATE id={$account->id}, date={$changeDate}" . PHP_EOL;

                if ($apply) {
                    $existing->data_json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
                    if (!$existing->save()) {
                        throw new ModelValidationException($existing);
                    }
                }

                $updated++;
                continue;
            }

            // Записи на эту дату нет — берём последний снапшот до даты изменения
            $previousVersion = HistoryVersion::find()
                ->andWhere(['model' => $modelClass, 'model_id' => $account->id])
                ->andWhere(['<', 'date', $changeDate])
                ->orderBy(['date' => SORT_DESC])
                ->limit(1)
                ->one();
            if ($previousVersion) {
                $data = json_decode($previousVersion->data_json, true);
                $data['is_postpaid'] = ClientAccount::PAYMENT_TYPE_PREPAID_2;
            } else {
                $data = $account->toArray();
            }

            echo "CREATE id={$account->id}, date={$changeDate}" . PHP_EOL;

            if ($apply) {
                $historyVersion = new HistoryVersion([
                    'model' => $modelClass,
                    'model_id' => $account->id,
                    'date' => $changeDate,
                    'data_json' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
                    'user_id' => User::SYSTEM_USER_ID,
                ]);

                if (!$historyVersion->save()) {
                    throw new ModelValidationException($historyVersion);
                }
            }

            $created++;
        }

        echo PHP_EOL . "Result: created={$created}, updated={$updated}, skipped={$skipped}" . PHP_EOL;
    }
}
