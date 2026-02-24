<?php

namespace app\commands\convert;

use app\forms\client\ClientAccountOptionsForm;
use app\forms\client\ClientAccountOptionsSaveForm;
use app\models\BusinessProcessStatus;
use app\models\ClientAccount;
use app\models\ClientAccountOptions;
use yii\console\Controller;

class ClientAccountController extends Controller
{
    /**
     * Установка "СБИС. Основание." = "Договор" для ЛС госников
     */
    public function actionSetSbisDocBaseContract()
    {
        $query = ClientAccount::find()
            ->alias('ca')
            ->innerJoinWith(['clientContractModel cc'])
            ->where(['cc.business_process_status_id' => BusinessProcessStatus::TELEKOM_MAINTENANCE_GOVERNMENT_AGENCIES]);

        $total = 0;
        $updated = 0;
        $skipped = 0;

        /** @var ClientAccount $account */
        foreach ($query->each() as $account) {
            $total++;

            $currentValue = $account->getOptionValue(ClientAccountOptions::OPTION_SBIS_DOC_BASE);
            if ($currentValue === ClientAccountOptions::OPTION_SBIS_DOC_BASE_CONTRACT) {
                $skipped++;
                echo "s";
                continue;
            }

            $optionsSaveForm = new ClientAccountOptionsSaveForm();
            $optionsSaveForm->addOptionForm(
                (new ClientAccountOptionsForm())
                    ->setClientAccountId($account->id)
                    ->setOption(ClientAccountOptions::OPTION_SBIS_DOC_BASE)
                    ->setValue(ClientAccountOptions::OPTION_SBIS_DOC_BASE_CONTRACT)
            );
            $optionsSaveForm->save();

            $updated++;
            echo "+";
        }

        echo "\n";
        echo "Всего: {$total}, обновлено: {$updated}, пропущено: {$skipped}\n";
    }
}
