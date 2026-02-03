<?php

namespace app\dao\statistics;

use app\helpers\DateTimeZoneHelper;
use app\models\ClientAccount;
use app\models\VirtpbxStat;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\AccountTariffLog;
use app\modules\uu\models\ResourceModel;
use app\modules\uu\models\ServiceType;

class VirtpbxStatDao extends \app\classes\Singleton
{


    /**
     *    Функция возвращает статистику по ВАТС
     * @param int $client_id - id клиента
     * @param int $from - timestamp начала периода
     * @param int $to - timestamp конца периода
     */
    public static function getVpbxStatDetails($client_id, $usage_id, $from, $to)
    {
        $tzUTC = new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_DEFAULT);
        $tzMoscow = new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_MOSCOW);

        $fromDate = new \DateTime(null, $tzUTC);
        $fromDate->setTimestamp($from);
        $fromDate->setTimezone($tzMoscow);

        $toDate = new \DateTime(null, $tzUTC);
        $toDate->setTimestamp($to);
        $toDate->setTimezone($tzMoscow);


        $options = array();
        $totals = array(
            'sum' => 0,
            'for_space' => 0,
            'for_number' => 0,
            'sum_number' => 0,
            'sum_space' => 0,
            'sum_ext_dids' => 0,
            'overrun_per_gb' => 0,
            'overrun_per_port' => 0,
            'ext_did_count' => 0,
            'ext_did_monthly_payment' => 0,
        );
//        $options['select'] = '
//					UNIX_TIMESTAMP(date) as mdate,
//					date,
//					use_space,
//					numbers,
//					ext_did_count,
//					0 as diff,
//					0 as diff_number,
//					0 as diff_ext_dids,
//					0 as sum_space,
//					0 as sum_number,
//					0 as sum_ext_dids,
//					0 as sum,
//					0 as for_space,
//					0 as for_number,
//					0 as for_ext_did_count';
//        $options['conditions'] = array(
//            'date >= ? AND date <= ? AND client_id = ? AND usage_id = ?',
//            $fromDate->format(DateTimeZoneHelper::DATE_FORMAT),
//            $toDate->format(DateTimeZoneHelper::DATE_FORMAT),
//            $client_id,
//            $usage_id,
//        );

        $stat_detailed = VirtpbxStat::find()->where([
            'client_id' => $client_id,
            'usage_id' => $usage_id])
            ->andWhere(['between', 'date', $fromDate->format(DateTimeZoneHelper::DATE_FORMAT), $toDate->format(DateTimeZoneHelper::DATE_FORMAT)])->asArray()->all();

        $tax_rate = ClientAccount::findOne($client_id)->getTaxRate();
        $ndsClient = 1 + $tax_rate / 100;
        foreach ($stat_detailed as $k => &$v) {
            $date = new \DateTime($v['date']);

            $tariff = self::getTarifByClient($client_id, $date->getTimestamp(), $usage_id);

            $mb = \app\classes\Utils::bytesToMb($v['use_space']);

            if ($tariff) {
                $nds = $tariff['price_include_vat'] ? 1 : $ndsClient;

                if ($mb > $tariff['space']) {
                    $v['for_space'] = ($mb - $tariff['space']) / 1024;
                    $v['sum_space'] = $nds * ($v['for_space'] * $tariff['overrun_per_gb']) / $date->format('t');
                    $totals['sum_space'] += $v['sum_space'];
                }

                if ($v['numbers'] > $tariff['num_ports']) {
                    $v['for_number'] = $v['numbers'] - $tariff['num_ports'];
                    $v['sum_number'] = $nds * ($v['for_number'] * $tariff['overrun_per_port']) / $date->format('t');
                    $totals['sum_number'] += $v['sum_number'];
                }

                if ($v['ext_did_count'] > $tariff['ext_did_count']) {
                    $v['for_ext_did_count'] = $v['ext_did_count'] - $tariff['ext_did_count'];
                    $v['sum_ext_dids'] = $nds * ($v['for_ext_did_count'] * $tariff['ext_did_monthly_payment']) / $date->format('t');
                    $totals['sum_ext_dids'] += $v['sum_ext_dids'];
                }

                $totals['overrun_per_gb'] = $tariff['overrun_per_gb'];
                $totals['overrun_per_port'] = $tariff['overrun_per_port'];
                $totals['ext_did_monthly_payment'] = $tariff['ext_did_monthly_payment'];
            }

            $v['sum'] = $v['sum_space'] + $v['sum_number'] + $v['sum_ext_dids'];
            $totals['sum'] += $v['sum'];

            if (isset($stat_detailed[$k - 1])) {
                $v['diff'] = $v['use_space'] - $stat_detailed[$k - 1]['use_space'];
                $v['diff_number'] = $v['numbers'] - $stat_detailed[$k - 1]['numbers'];
                $v['diff_ext_dids'] = $v['ext_did_count'] - $stat_detailed[$k - 1]['ext_did_count'];
            } else {
                /** @var VirtpbxStat $prev_day_use_spase */
                $prev_day_use_spase = VirtpbxStat::find()->where([
                    'client_id' => $client_id, 'usage_id' => $usage_id,
                ])->andWhere(['<', 'date', $v['date']])
                    ->orderBy(['date' => SORT_DESC])->one();

                if (!empty($prev_day_use_spase)) {
                    $v['diff'] = $v['use_space'] - $prev_day_use_spase->use_space;
                    $v['diff_number'] = $v['numbers'] - $prev_day_use_spase->numbers;
                    $v['diff_ext_dids'] = $v['ext_did_count'] - $prev_day_use_spase->ext_did_count;
                } else {
                    $v['diff'] = $v['use_space'];
                    $v['diff_number'] = $v['numbers'];
                    $v['diff_ext_dids'] = $v['ext_did_count'];
                }
            }

        }
        unset($v);

        return array($stat_detailed, $totals);
    }


    /**
     * @param int $client_id clients.id (ClientAccount.id)
     * @param int $time unix timestamp московской даты (из UNIX_TIMESTAMP(date))
     * @param int|null $accountTariffId AccountTariff.id (если известен)
     * @return array|null
     */
    public static function getTarifByClient($client_id, $time, $accountTariffId = null)
    {
        static $c = [];
        if (isset($c[$client_id])) {
            return $c[$client_id];
        }
        // Конвертируем московскую дату в UTC для сравнения с actual_from_utc
        $moscowDate = date('Y-m-d', $time);
        $targetDateUtc = (new \DateTime($moscowDate . ' 23:59:59', new \DateTimeZone('Europe/Moscow')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        // Находим AccountTariff
        if ($accountTariffId) {
            $accountTariff = AccountTariff::findOne($accountTariffId);
        } else {
            $accountTariff = AccountTariff::findOne([
                'client_account_id' => $client_id,
                'service_type_id' => ServiceType::ID_VPBX,
            ]);
        }

        if (!$accountTariff) {
            return null;
        }

        // Находим активный лог тарифа на дату
        $accountTariffLog = AccountTariffLog::find()
            ->where(['account_tariff_id' => $accountTariff->id])
            ->andWhere(['<=', 'actual_from_utc', $targetDateUtc])
            ->andWhere(['IS NOT', 'tariff_period_id', null])
            ->orderBy(['actual_from_utc' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(1)
            ->one();

        if (!$accountTariffLog || !$accountTariffLog->tariffPeriod) {
            return null;
        }

        $tariff = $accountTariffLog->tariffPeriod->tariff;
        if (!$tariff) {
            return null;
        }

        $resources = $tariff->tariffResourcesIndexedByResourceId;

        $diskRes = $resources[ResourceModel::ID_VPBX_DISK] ?? null;
        $abonentRes = $resources[ResourceModel::ID_VPBX_ABONENT] ?? null;
        $extDidRes = $resources[ResourceModel::ID_VPBX_EXT_DID] ?? null;

        $result = [
            'space' => ($diskRes ? (float)$diskRes->amount : 0) * 1024, // ГБ → МБ
            'overrun_per_gb' => $diskRes ? (float)$diskRes->price_per_unit : 0,
            'num_ports' => $abonentRes ? (float)$abonentRes->amount : 0,
            'overrun_per_port' => $abonentRes ? (float)$abonentRes->price_per_unit : 0,
            'ext_did_count' => $extDidRes ? (float)$extDidRes->amount : 0,
            'ext_did_monthly_payment' => $extDidRes ? (float)$extDidRes->price_per_unit : 0,
            'price_include_vat' => (int)$tariff->is_include_vat,
        ];

        $c[$client_id] = $result;
        return $result;
    }
}