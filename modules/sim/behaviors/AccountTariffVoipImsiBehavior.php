<?php

namespace app\modules\sim\behaviors;

use app\classes\model\ActiveRecord;
use app\exceptions\ModelValidationException;
use app\models\EventQueue;
use app\models\Number;
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
     * Синхронизация imsi/iccid в номер (voip_numbers) и услугу (uu_account_tariff)
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

        if (!preg_match(Imsi::imsiPrefixRegExp, $model->imsi) || !$model->msisdn) {
            return;
        }

        $msisdnChanged = array_key_exists('msisdn', $event->changedAttributes)
            && $event->changedAttributes['msisdn'] != $model->msisdn;
        $imsiChanged = array_key_exists('imsi', $event->changedAttributes);

        if (!$msisdnChanged && !$imsiChanged) {
            return;
        }

        $number = Number::findOne(['number' => $model->msisdn]);
        if ($number) {
            $number->imsi = $model->imsi;
            if ($card = $model->card) {
                $number->warehouse_status_id = $card->status_id;
            }
            if (!$number->save()) {
                throw new ModelValidationException($number);
            }
        }

        /** @var AccountTariff $accountTariff */
        $accountTariff = AccountTariff::find()
            ->where(['voip_number' => $model->msisdn])
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
