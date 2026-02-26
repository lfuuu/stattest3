<?php

namespace app\modules\sim\behaviors;

use app\classes\model\ActiveRecord;
use app\exceptions\ModelValidationException;
use app\models\EventQueue;
use app\modules\sim\models\Imsi;
use app\modules\uu\models\AccountTariff;
use yii\base\Behavior;
use yii\db\AfterSaveEvent;

class AccountTariffVoipImsiBehavior extends Behavior
{
    /**
     * @return array
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
        ];
    }

    /**
     * При смене msisdn на IMSI -- синхронизировать imsi/iccid в услугу теелфонии
     *
     * @param AfterSaveEvent $event
     * @throws ModelValidationException
     */
    public function afterUpdate(AfterSaveEvent $event)
    {
        if (!\Yii::$app->isRus()) {
            return;
        }

        /** @var Imsi $model */
        $model = $event->sender;

        if (!array_key_exists('msisdn', $event->changedAttributes)) {
            return;
        }

        if (!preg_match(Imsi::imsiPrefixRegExp, $model->imsi)) {
            return;
        }

        $oldMsisdn = $event->changedAttributes['msisdn'];
        $newMsisdn = $model->msisdn;

        if ($oldMsisdn == $newMsisdn) {
            return;
        }

        /** @var AccountTariff $accountTariff */
        $accountTariff = AccountTariff::find()
            ->where(['voip_number' => $newMsisdn])
            ->andWhere(['NOT', ['tariff_period_id' => null]])
            ->one();

        if (!$accountTariff) {
            return;
        }

        $accountTariff->imsi = $model->imsi;
        $accountTariff->iccid = $model->iccid;

        if (!$accountTariff->save(false)) {
            throw new ModelValidationException($accountTariff);
        }

        EventQueue::go(EventQueue::STATE_VOIP_UPDATE, [
            'client_account_id' => $accountTariff->client_account_id,
            'account_tariff_id' => $accountTariff->id,
            'number' => $accountTariff->voip_number,
        ]);
    }
}
