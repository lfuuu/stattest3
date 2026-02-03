<?php

use app\helpers\DateTimeZoneHelper;
use app\models\ClientAccount;

class VirtpbxStat extends ActiveRecord\Model
{
	static $table_name = "virtpbx_stat";
	/** 
	 *	Получение данных о днях, когда не была получена информация по ВАТС
	 *	@param int $from timestamp начала периода
	 *	@param int $to timestamp конца периода
	 */
	public static function getBadStat($from, $to) 
	{
		$_dates = array();
		$params = array(
			'date_from' => date("Y-m-d", $from),
			'date_to' => date("Y-m-d", $to),
			'day' => date("d", $to),
		);
		
		$tm = new DateTime();
		$tm->setTimestamp($from);
		$one_day = new DateInterval('P1D');
		
		for ($i=1;$i<=$params['day'];$i++) 
		{
			$_dates[] = $tm->getTimestamp();
			$tm->add($one_day);
		}
		
		$options = array();
		$options['select'] = 'UV.id, C.id as client_id, UV.client, UNIX_TIMESTAMP(actual_from) as date_from, UNIX_TIMESTAMP(actual_to) as date_to';
		$options['from'] = 'usage_virtpbx as UV';
		$options['joins'] = 'LEFT JOIN clients as C ON UV.client = C.client';
		$options['conditions'] = array('actual_from <= ? AND actual_to >= ?', $params['date_to'], $params['date_from']);
		$options['group'] = 'client_id';
		$vpbxs = UsageVirtpbx::find('all', $options);

		foreach ($vpbxs as $v) 
		{
			$dates = $_dates;
			if ($dates[0] < $v->date_from || $dates[count($dates)-1] > $v->date_to)
			{
				foreach ($dates as $k => $date)
				{
					if ($date < $v->date_from || $date > $v->date_to)
					{
						unset($dates[$k]);
					}
				}
			}
			$pbx_dates = array();
			$options = array();
			$options['select'] = 'UNIX_TIMESTAMP(date) as ts';
			$options['conditions'] = array(
				'numbers IS NOT NULL AND use_space IS NOT NULL AND client_id = ? AND date >= ? AND date <= ?',
				$v->client_id, 
				$params['date_from'], 
				$params['date_to']
			);
			$temp = self::find('all', $options);
			if (!empty($temp)) 
			{
				foreach ($temp as $ts)
				{
					$pbx_dates[] = $ts->ts;
				}
				$timestamps = array_diff($dates, $pbx_dates);
			} else {
				$timestamps = $dates;
			}
			if (!empty($timestamps))
			{
				$data[$v->client_id]['client'] = $v->client;
				$data[$v->client_id]['ts'] = $timestamps;
			}
		}
		return $data;
	}

	/**
	*	Функция возвращает статистику по ВАТС
	*	@param int $client_id - id клиента
	*	@param int $from - timestamp начала периода
	*	@param int $to - timestamp конца периода
	*/
	public static function getVpbxStatDetails($client_id, $usage_id, $from, $to)
	{
	    $tzUTC = new DateTimeZone(DateTimeZoneHelper::TIMEZONE_DEFAULT);
        $tzMoscow = new DateTimeZone(DateTimeZoneHelper::TIMEZONE_MOSCOW);

        $fromDate = new DateTime(null, $tzUTC);
        $fromDate->setTimestamp($from);
        $fromDate->setTimezone($tzMoscow);

        $toDate = new DateTime(null, $tzUTC);
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
		$options['select'] = '
					UNIX_TIMESTAMP(date) as mdate,
					date,
					use_space, 
					numbers,
					ext_did_count,
					0 as diff, 
					0 as diff_number,
					0 as diff_ext_dids,
					0 as sum_space, 
					0 as sum_number,
					0 as sum_ext_dids,
					0 as sum,
					0 as for_space,
					0 as for_number,
					0 as for_ext_did_count';
		$options['conditions'] = array(
						'date >= ? AND date <= ? AND client_id = ? AND usage_id = ?',
						$fromDate->format(DateTimeZoneHelper::DATE_FORMAT),
						$toDate->format(DateTimeZoneHelper::DATE_FORMAT),
						$client_id,
						$usage_id,
		);
		$stat_detailed = self::find('all', $options);
        $tax_rate = ClientAccount::findOne($client_id)->getTaxRate();
        $ndsClient = 1 + $tax_rate/100;
		foreach ($stat_detailed as $k => &$v)
		{
		    $date = new DateTime($v->date);

			$tarif_info = TarifVirtpbx::getTarifByClient($client_id, $v->mdate, $usage_id);

			$mb = \app\classes\Utils::bytesToMb($v->use_space);

			if ($tarif_info) {
				$nds = $tarif_info->price_include_vat ? 1 : $ndsClient;

				if ($mb > $tarif_info->space) {
					$v->for_space = ($mb - $tarif_info->space) / 1024;
					$v->sum_space = $nds * ($v->for_space * $tarif_info->overrun_per_gb) / $date->format('t');
					$totals['sum_space'] += $v->sum_space;
				}

				if ($v->numbers > $tarif_info->num_ports) {
					$v->for_number = $v->numbers - $tarif_info->num_ports;
					$v->sum_number = $nds * ($v->for_number * $tarif_info->overrun_per_port) / $date->format('t');
					$totals['sum_number'] += $v->sum_number;
				}

				if ($v->ext_did_count > $tarif_info->ext_did_count) {
					$v->for_ext_did_count = $v->ext_did_count - $tarif_info->ext_did_count;
					$v->sum_ext_dids = $nds * ($v->for_ext_did_count * $tarif_info->ext_did_monthly_payment) / $date->format('t');
					$totals['sum_ext_dids'] += $v->sum_ext_dids;
				}

				$totals['overrun_per_gb'] = $tarif_info->overrun_per_gb;
				$totals['overrun_per_port'] = $tarif_info->overrun_per_port;
				$totals['ext_did_monthly_payment'] = $tarif_info->ext_did_monthly_payment;
			}

			$v->sum = $v->sum_space + $v->sum_number + $v->sum_ext_dids;
			$totals['sum'] += $v->sum;

			if (isset($stat_detailed[$k-1]))
			{
				$v->diff = $v->use_space -$stat_detailed[$k-1]->use_space;
				$v->diff_number = $v->numbers -$stat_detailed[$k-1]->numbers;
				$v->diff_ext_dids = $v->ext_did_count - $stat_detailed[$k - 1]->ext_did_count;
			} else 	{
				$options = array();
				$options['select'] = 'use_space, numbers, ext_did_count';
				$options['conditions'] = array(
								'date < ? AND client_id = ? AND usage_id = ?',
								date('Y-m-d', $v->mdate),
								$client_id,
								$usage_id,
				);
				$options['limit'] = 1;
				$options['order'] = 'date desc';
				$options['offset'] = 0;
				$prev_day_use_spase = self::find('first', $options);
				if (!empty($prev_day_use_spase)) {
					$v->diff = $v->use_space -$prev_day_use_spase->use_space;
					$v->diff_number = $v->numbers -$prev_day_use_spase->numbers;
					$v->diff_ext_dids = $v->ext_did_count - $prev_day_use_spase->ext_did_count;
				} else {
					$v->diff = $v->use_space;
					$v->diff_number = $v->numbers;
					$v->diff_ext_dids = $v->ext_did_count;
				}
			}

		}
		unset($v);

		return array($stat_detailed, $totals);
    }

	/**
	*	Функция возвращает статистику по ВАТС
	*	@param int $client_id - id клиента
	*	@param int $from - timestamp начала периода 
	*	@param int $to - timestamp конца периода
	*/
	public static function getVpbxStatDetailsFormated($client_id, $usage_id, $from, $to)
    {
        if (!$from || strtotime('2000-01-01') > $from || $to < $from || round(($to-$from)/86400) > 100)
        {
            $total = [
                'sum_space' => 0,
                'sum_number' => 0,
                'sum_ext_dids' => 0,
                'sum' => 0,
            ];

            return ['data' => [], 'total' => $total];
        }

        list($_data, $total) = \app\dao\statistics\VirtpbxStatDao::me()->getVpbxStatDetails($client_id, $usage_id, $from, $to);

        $data = [];
        $fields = [
            'mdate',
            'use_space', 'numbers', 'ext_did_count',
            'diff', 'diff_number', 'diff_ext_dids',
            'sum_space', 'sum_number', 'sum_ext_dids', 'sum',
            'for_space', 'for_number'
        ];

        foreach($_data as $l)
        {
            $line = $l;//ApiLk::_exportModelRow($fields, $l);

            $line['use_space']  = smarty_modifier_bytesize($line['use_space'], 'b');
            $line['diff']       = smarty_modifier_bytesize($line['diff'], 'b');
            $line['for_space']  = smarty_modifier_bytesize($line['for_space'], 'Gb');

            $line['sum_space']    = number_format($line['sum_space'], 2, ',', ' ');
            $line['sum_number']   = number_format($line['sum_number'], 2, ',', ' ');
            $line['sum_ext_dids'] = number_format($line['sum_ext_dids'], 2, ',', ' ');
            $line['sum']          = number_format($line['sum'], 2, ',', ' ');

            $data[] = $line;
        }

        $total['sum_space']  = number_format($total['sum_space'],  2, ',', ' ');
        $total['sum_number'] = number_format($total['sum_number'], 2, ',', ' ');
        $total['sum_ext_dids'] = number_format($total['sum_ext_dids'], 2, ',', ' ');
        $total['sum']        = number_format($total['sum'], 2, ',', ' ');

        return ['data' => $data, 'total' => $total];
    }

}
