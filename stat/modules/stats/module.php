<?php

use app\dao\reports\ReportUsage;
use app\dao\reports\ReportUsageDao;
use app\helpers\DateTimeZoneHelper;
use app\models\flows\TraffFlow1d;
use app\models\ClientAccount;
use app\models\Param;
use app\models\StatVoipFreeCache;
use app\classes\BaseView;
use app\models\UsageIpPorts;
use app\models\UsageIpRoutes;
use app\models\SmsStat;

class m_stats extends IModule{
    private $_inheritances = array();

    public function __construct()
    {
//        $this->_addInheritance(new m_stats_);
    }

    public function __call($method, array $arguments = array())
    {
        foreach ($this->_inheritances as $inheritance) {
            $inheritance->invoke($method, $arguments);
        }
    }

    protected function _addInheritance(Inheritance $inheritance)
    {
        $this->_inheritances[get_class($inheritance)] = $inheritance;
        $inheritance->module = $this;
    }

	function stats_default($fixclient){
		$this->stats_voip($fixclient);
	}

	function stats_internet($fixclient){
		global $db,$design;
		if(!$fixclient){
			trigger_error2('Выберите клиента');
			return;
		}
        $clientNick = ClientAccount::findOne($fixclient)->client;
		
		$route=get_param_raw('route','');
		
		$dateFrom = new DatePickerValues('date_from', 'first');
		$dateTo = new DatePickerValues('date_to', 'last');

		$from = $dateFrom->getTimestamp();
		$to = $dateTo->getTimestamp();
		
		DatePickerPeriods::assignStartEndMonth($dateFrom->day, 'prev_', '-1 month');
		DatePickerPeriods::assignPeriods(new DateTime());

		$ip_group = get_param_integer('ip_group',0);
		$design->assign('ip_group',$ip_group);

		$detality = get_param_protected('detality','day');
		$design->assign('detality',$detality);

		list($routes_all,$routes_allB)=$this->get_routes_list($clientNick);

		//если сеть не задана, выводим все подсети клиента.
		if($route){
			if(isset($routes_all[$route])){
				$routes=array($routes_all[$route]);
			}else{
				trigger_error2('Выбрана неправильная сеть');
				return;
			}
		}else{
			$routes=array();
			foreach($routes_allB as $r)
				$routes[] = $r;
		}

		$is_coll = get_param_integer('is_coll',0);
		$design->assign('is_collocation',$is_coll);

		$stats = $this->GetStatsInternet($from, $to, $detality, $routes);
		if(!$stats)
			return;

		$design->assign('stats',$stats);
		$design->assign('route',$route);
		$design->assign('routes_all',$routes_all);
		if (get_param_raw('xml')){
			header("Content-type: text/xml");
			$design->ProcessEx('stats/internet_xml.tpl');
		} else {
			$design->AddMain('stats/internet.tpl');
			$design->AddMain('stats/internet_form.tpl');
		}
	}

	function stats_ppp($fixclient){
		global $db,$design;

		$login=get_param_integer('login',0);
		if (!$fixclient) {trigger_error2('Выберите клиента'); return;}
        $clientNick = ClientAccount::findOne(['id' => $fixclient])->client;

		if ($login){
			$db->Query('select * from usage_ip_ppp where (client="'.$clientNick.'") and (id="'.$login.'")');
			if (!($r=$db->NextRecord())) {trigger_error2('Логин не существует'); return; }
			$logins=array($r['login']);

			$db->Query('select * from usage_ip_ppp where (client="'.$clientNick.'") and (login!="") order by login');
			$logins_all=array();
			while ($r=$db->NextRecord()){
				$logins_all[]=$r;
			}
		} else {
			//список всех сетей, нужен для вывода их списка.
			$db->Query('select * from usage_ip_ppp where (client="'.$clientNick.'") and (login!="") order by login');
			$logins_all=array(); $logins=array();
			while ($r=$db->NextRecord()){
				$logins[]=$r['login'];
				$logins_all[]=$r;
			}
		}

		$dateFrom = new DatePickerValues('date_from', 'first');
		$dateTo = new DatePickerValues('date_to', 'last');

		$from = $dateFrom->getTimestamp();
		$to = $dateTo->getTimestamp();
		
		DatePickerPeriods::assignStartEndMonth($dateFrom->day, 'prev_', '-1 month');
		DatePickerPeriods::assignPeriods(new DateTime());

		$detality=get_param_protected('detality','day');

		$stats=$this->GetStatsPPP($from,$to,$detality,$logins);

		$design->assign('detality',$detality);
		$design->assign('stats',$stats);
		$design->assign('login',$login);
		$design->assign('logins_all',$logins_all);
		if (get_param_raw('xml')){
			header("Content-type: text/xml");
			$design->ProcessEx('stats/ppp_xml.tpl');
		} else {
			$design->AddMain('stats/ppp.tpl');
			$design->AddMain('stats/ppp_form.tpl');
		}
	}

    /**
     * @param $fixClient
     * @throws Exception
     */
    public function stats_voip_profit($fixClient)
    {
        global $design;

        session_write_close();

        $report = null;
        try {
            $report = ReportUsage::createFromRequest($fixClient, true);
            $report->fetchStatistic();
        } catch (\LogicException $e) {
            Yii::$app->session->addFlash('error', 'Ошибка логики: ' . $e->getMessage());
        } catch (\Exception $e) {
            Yii::$app->session->addFlash('error', 'Неизвестная ошибка: ' . $e->getMessage());
        }

        if ($report) {
            foreach ($report->getAttributes() as $key => $value) {
                $design->assign($key, $value);
            }
        }

        $design->AddMain('stats/voip_profit_form.tpl');
        $design->AddMain('stats/voip_profit.tpl');
    }

    /**
     * @param $fixClient
     * @throws Exception
     */
    public function stats_voip_profit_new($fixClient)
    {
        global $design;

        session_write_close();

        $report = null;
        try {
            $report = ReportUsage::createFromRequest($fixClient, true, true);
            $report->fetchStatistic();
        } catch (\LogicException $e) {
            Yii::$app->session->addFlash('error', 'Ошибка логики: ' . $e->getMessage());
        } catch (\Exception $e) {
            Yii::$app->session->addFlash('error', 'Неизвестная ошибка: ' . $e->getMessage());
        }

        if ($report) {
            foreach ($report->getAttributes() as $key => $value) {
                $design->assign($key, $value);
            }
        }

        $design->AddMain('stats/voip_profit_form.tpl');
        $design->AddMain('stats/voip_profit.tpl');
    }

    /**
     * @param $fixClient
     * @param bool $isForCompany
     * @throws \yii\base\Exception
     */
    public function stats_voip($fixClient)
    {
        global $design;

        if (!$fixClient) {
            trigger_error2('Клиент не выбран');
            return;
        }

        $phone = get_param_protected('phone', '');
        session_write_close();

        /** @var ClientAccount $account */
        $account = ClientAccount::findOne($fixClient);

        $usagesData = ReportUsageDao::me()->getUsageVoipAndTrunks($account);

        if (!$usagesData) {
            trigger_error2("У клиента нет подключенных телефонных номеров и транков!");
            return;
        }

        $dateFrom = new DatePickerValues('date_from', 'first');
        $dateTo = new DatePickerValues('date_to', 'last');

        $from = $dateFrom->getTimestamp();
        $to = $dateTo->getTimestamp();

        DatePickerPeriods::assignStartEndMonth($dateFrom->day, 'prev_', '-1 month');
        DatePickerPeriods::assignPeriods(new DateTime());

        $destination = get_param_raw('destination', 'all');
        !in_array($destination, array('all', '0', '0-m', '0-f', '0-f-z', '1', '1-m', '1-f', '2', '3')) && $destination = 'all';

        $direction = get_param_raw('direction', 'both');
        !in_array($direction, array('both', 'in', 'out')) && $direction = 'both';

        $design->assign('phone', $phone);
        $design->assign('phones', ReportUsageDao::me()->usagesToSelect($usagesData));
        $design->assign('destination', $destination);
        $design->assign('direction', $direction);
        $design->assign('detality', $reportType = get_param_protected('detality', 'day'));
        $design->assign('paidonly', $paidOnly = get_param_integer('paidonly', 0));
        $design->assign('timezone', $timezone = get_param_raw('timezone', $account->timezone_name));
        $design->assign('timezones', ReportUsageDao::me()->getTimezones($account, $usagesData['voip']));
        $design->assign('tariffs', ReportUsageDao::me()->getTariffs($account));
        $design->assign('tariff_id', $tariffId = get_param_raw('tariff_id', ''));

        $design->assign('filtersb', ReportUsageDao::me()->getFiltersB($account, $dateFrom->getValue(), $dateTo->getValue()));
        $design->assign('filterb', $filterb = get_param_raw('filterb', ''));

        list($usageIds, $regions, $isTrunk) = ReportUsageDao::me()->reportConfig($phone, $usagesData);

        $stats = [];

        $isTrunk && $regions = 'trunk';

        if ($usageIds) {
            if (!($stats = ReportUsageDao::me()->getUsageVoipStatistic(
                $regions,
                $from,
                $to,
                $reportType,
                $account->id,
                $usageIds,
                $paidOnly,
                $destination,
                $direction,
                $isFull = false,
                $packages = [],
                $timezone,
                $tariffId,
                $filterb
            ))
            ) {
                return;
            }
        }

        $design->assign('stats',$stats);
        $design->assign('price_include_vat', $account->price_include_vat);
        $design->AddMain('stats/voip_client_form.tpl');
        $design->AddMain('stats/voip_client.tpl');
    }

    function getUnUsageCalls()
    {
        $param = null;

        if ($param = Param::findOne(["param" => "stat_voip_free__last_make"]))
        {
            if ($param && $param->value && strtotime($param->value) > strtotime("midnight"))
            {
                return true;
            }
        }

        $res = $this->makeUnUsageCalls();

        if (!$param)
        {
            $param = new Param;
            $param->param = "stat_voip_free__last_make";
        }
        $param->value = date("Y-m-d H:i:s");
        $param->save();


        return true;
    }

    function makeUnUsageCalls()
    {
        ini_set('max_execution_time', 3000);

        global $pg_db;

        $fromTime = strtotime("first day of -3 month, midnight");

        $data = $pg_db->AllRecords("
        select 
            dst_number,
            to_char(connect_time, 'MM') as mnth,
            sum(1) as count_calls
            from 
            \"calls_raw\".\"calls_raw\"
            where 
            connect_time > '".date("Y-m-d H:i:s", $fromTime)."' 
            and number_service_id is null 
            and orig = true
            group by dst_number, mnth
            order by dst_number, mnth
            ");


        StatVoipFreeCache::getDb()->createCommand()->truncateTable("stat_voip_free_cache")->execute();

        $count = 0;
        $b = [];
        foreach($data as $line)
        {
            $b[] = [$line["dst_number"], $line["mnth"], $line["count_calls"]];

            if (0 == ($count++ % 10000))
            {
                StatVoipFreeCache::getDb()->createCommand()->batchInsert("stat_voip_free_cache", ["number", "month", "calls"], $b)->execute();
                $b = [];
            }
        }

        if ($b)
        {
            StatVoipFreeCache::getDb()->createCommand()->batchInsert("stat_voip_free_cache", ["number", "month", "calls"], $b)->execute();
        }

        return true;
    }

	function stats_callback($fixclient){
		global $db,$design,$fixclient_data;
		if (!$fixclient) {trigger_error2('Выберите клиента');return;}

		$dateFrom = new DatePickerValues('date_from', 'first');
		$dateTo = new DatePickerValues('date_to', 'last');

		$from = $dateFrom->getTimestamp();
		$to = $dateTo->getTimestamp();
		
		DatePickerPeriods::assignStartEndMonth($dateFrom->day, 'prev_', '-1 month');
		DatePickerPeriods::assignPeriods(new DateTime());

		$detality=get_param_protected('detality','day');

		$stats=$this->GetStatsCallback($from,$to,$detality,$fixclient);
		if (!$stats) return;
		$design->assign('detality',$detality);
		$design->assign('stats',$stats);
		$design->AddMain('stats/callback.tpl');
		$design->AddMain('stats/callback_form.tpl');
	}

    /**
     * Статистика по Интернет
     *
     * @param string $from Дата начала
     * @param string $to Дата окончания
     * @param string $detality Детализация
     * @param array $routes список ip-адресов/ip-сетей для отчета
     * @param int $isCollocation
     * @return app\models\HistoryVersion|app\models\UsageTechCpe
     */
    function GetStatsInternet($from, $to, $detality, $routes)
    {
        $tz = new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC);
        $fromDt = new DateTime('now', $tz);
        $fromDt->setTimestamp($from);

        $toDt = new DateTime('now', $tz);
        $toDt->setTimestamp($to);
        $toDt->setTime(23, 59, 59);


        $stat = TraffFlow1d::getStatistic($fromDt, $toDt, $detality, $routes);


        if (!$stat) {
            return [];
        }

        return $stat;
    }

    function FindByNumber($region , $from, $to, $find, $timezone = "Europe/Moscow")
    {
        global $pg_db;

        $R = array();
        $geo = array();


        $from = new DateTime(date('Y-m-d', $from), new DateTimeZone($timezone));
        $to = new DateTime(date('Y-m-d 23:59:59', $to), new DateTimeZone($timezone));

        $from->setTimezone(new DateTimeZone('UTC'));
        $to->setTimezone(new DateTimeZone('UTC'));

        $dt = new DateTime();
        $dt->setTimezone(new DateTimeZone($timezone));

        $offset = $dt->getOffset();

        foreach($pg_db->AllRecords($q =
                  "SELECT orig, src_number, dst_number, billed_time as len, geo_id, connect_time FROM calls_raw.calls_raw
                  WHERE \"connect_time\" BETWEEN '".$from->format('Y-m-d H:i:s')."' AND '".$to->format('Y-m-d H:i:s.999999')."'
                  AND '".$find."' in (src_number, dst_number)
                  AND server_id = '".$region."'
                  AND number_service_id is not null
                  LIMIT 1000") as $l)
        {
          $l["time"] = mdate("d месяца Y г. H:i:s", strtotime($l["connect_time"])+$offset);

          if ($l['len']>=24*60*60) $d=floor($l['len']/(24*60*60)); else $d=0;
          $l["len"]=($d?($d.'d '):'').gmdate("H:i:s",$l['len']-$d*24*60*60);

          if (isset($l['geo_id']))
          {
            if (!isset($geo[$l['geo_id']]))
              $geo[$l['geo_id']] = $pg_db->GetValue('select name from geo.geo where id='.((int)$l['geo_id']));

            $l['geo'] = $geo[$l['geo_id']];

            if ($l['mob'] == 't')
              $l['geo'] .= ' (mob)';
          } else
            $l['geo'] = '';


          $R[] = $l;
        }

      return $R;
    }

    function GetStatsVoIP($region,$from,$to,$detality,$client_id,$usage_arr,$paidonly = 0,$skipped = 0, $destination='all',$direction='both', $timezone = "Europe/Moscow", $regions = array(), $isFull = false){
        global $pg_db;

        if (!$timezone)
            $timezone = 'Europe/Moscow';

        if (!($timezone instanceof DateTimeZone)) {
            $timezone = new DateTimeZone($timezone);
        }

        $from = new DateTime(date('Y-m-d', $from), $timezone);
        $to = new DateTime(date('Y-m-d 23:59:59', $to), $timezone);

        $offset = $from->getOffset();

        $from->setTimezone(new DateTimeZone('UTC'));
        $to->setTimezone(new DateTimeZone('UTC'));

        $clientAccount = ClientAccount::findOne($client_id);
        $tax_rate = $clientAccount->getTaxRate();

        if ($detality=='call'){
            $group='';
            $format='d месяца Y г. H:i:s';
        } elseif ($detality=='year'){
            $group=" group by date_trunc('year',cr.connect_time + '{$offset} second'::interval)";
            $format='Y г.';
        } elseif ($detality=='month'){
            $group=" group by date_trunc('month',cr.connect_time + '{$offset} second'::interval)";
            $format='Месяц Y г.';
        } elseif ($detality=='day'){
            $group=" group by date_trunc('day',cr.connect_time + '{$offset} second'::interval)";
            $format='d месяца Y г.';
        } else {
            $group='';
            $format='d месяца Y г. H:i:s';
        }
        $W=array('AND');

        $W[] = "cr.connect_time>='".$from->format('Y-m-d H:i:s')."'";
        $W[] = "cr.connect_time<='".$to->format('Y-m-d H:i:s.999999')."'";

        $zone_filter = false;

        if($destination != 'all'){
            list($dg, $mobile, $zone) = explode("-", $destination);
            $dest = intval($dg);
            if ($dest == 0)
                $W[] = 'cr.destination_id<='.$dest;
            else
                $W[] = 'cr.destination_id='.$dest;
            if(isset($mobile)){
                if ($mobile == 'm') {
                    $W[] = 'cr.mob=true';
                }elseif ($mobile == 'f') {
                    $W[] = 'cr.mob=false';
                }
            }
            if ($dg == 0 && $mobile == 'f') {
                $zone_filter = true;
                switch ($zone) {
                    case 'z':
                        $W[] = 'g.id is null';
                        break;
                    default:
                        $W[] = 'g.id is not null';
                        break;
                }
            }
        }

        if($direction <> 'both'){
            if($direction == 'in')
                $W[] = 'cr.orig=false';
            else
                $W[] = 'cr.orig=true';
        }

        $W[]=(isset($usage_arr) && count($usage_arr) > 0) ? 'cr.number_service_id IN (' . implode($usage_arr, ',') . ')' : 'FALSE';

        if ($paidonly) {
            $W[]='abs(cr.cost)>0.0001';
        }

        global $db;

        if ($detality != 'dest') {
            $R=array();
            $sql="
                            select
                                    ".($group?'':'cr.id,')."
                                    ".($group?'':'cr.src_number,')."
                                    ".($group?'':'cr.geo_id,')."
                                    ".($group?'':'cr.geo_mob,')."
                                    ".($group?'':'cr.dst_number,')."
                                    ".($group?'':'cr.orig,');
            if ($detality == 'day') $sql.= " date_trunc('day',cr.connect_time + '{$offset} second'::interval) as ts1, ";
            elseif ($detality == 'month') $sql.= " date_trunc('month',cr.connect_time + '{$offset} second'::interval) as ts1, ";
            elseif ($detality == 'year') $sql.= " date_trunc('year',cr.connect_time + '{$offset} second'::interval) as ts1, ";
            else $sql.= " cr.connect_time + '{$offset} second'::interval as ts1, ";

            $sql .=
                ($group ? '-sum' : '-') . '(cr.cost) as price,' .
                ($group ? 'sum' : '') . '(' . ($paidonly ? 'case abs(cr.cost)>0.0001 when true then cr.billed_time else 0 end' : 'cr.billed_time') . ') as ts2,' .
                ($group ? 'sum(' . ($paidonly ? 'case abs(cr.cost)>0.0001 when true then 1 else 0 end' : 1) . ')' : '1') . ' as cnt
                    from
                        calls_raw.calls_raw cr' .
                        ($zone_filter
                            ? ' left join billing.instance_settings iss on iss.city_geo_id = cr.geo_id
                                    left join geo.geo g on g.id = iss.city_geo_id'
                            : ''
                        ) .
                    ' where ' . MySQLDatabase::Generate($W) . $group . '
                    order by
                        ts1 asc
                    limit ' . ($isFull ? 50000 : 5000);

            $pg_db->Query($sql);

            if ($pg_db->NumRows()==5000) trigger_error2('Статистика отображается не полностью. Сделайте ее менее детальной или сузьте временной период');
            $rt=array('price'=>0, 'ts2'=>0,'cnt'=>0,'is_total'=>true);
            $geo = array();

            //while ($r=$db_calls->NextRecord()){
            $records = $pg_db->AllRecords();
            foreach($records as $r)
            {
                if (isset($r['geo_id']))
                {
                    if (!isset($geo[$r['geo_id']]))
                        $geo[$r['geo_id']] = $pg_db->GetValue('select name from geo.geo where id='.((int)$r['geo_id']));
                    $r['geo'] = $geo[$r['geo_id']];
                    if ($r['geo_mob'] == 't') $r['geo'] .= ' (mob)';
                } else $r['geo'] = '';

                $dt = explode(' ', $r['ts1']);
                $d = explode('-', $dt[0]);
                if (count($dt)>1)
                    $t = explode(':', $dt[1]);
                else $t=array('0','0','0');
                $ts = mktime($t[0],$t[1],intval($t[2]),$d[1],$d[2],$d[0]);
                $r['tsf1']=mdate($format,$ts);
                $r['mktime'] = $ts;
                $r['is_total'] = false;

                if ($r['ts2']>=24*60*60) $d=floor($r['ts2']/(24*60*60)); else $d=0;
                $r['tsf2']=($d?($d.'d '):'').gmdate("H:i:s",$r['ts2']);
                $r['price'] = number_format($r['price'], 2, '.','');
                //if (!$group && !$paidonly && ($r['ts2']<0) && isset($Trans[-$r['ts2']])) $r['cause']=$Trans[-$r['ts2']]; else $r['cause']='';

                $R[]=$r;
                $rt['price']+=$r['price'];
                $rt['cnt']+=$r['cnt'];
                $rt['ts2']+=$r['ts2'];
            }
            $rt['ts1']= 'Итого';
            $rt['tsf1']='<b>Итого</b>';
            $rt['num_to']='&nbsp;';
            $rt['num_from']='&nbsp;';
            if ($rt['ts2']>=24*60*60) $d=floor($rt['ts2']/(24*60*60)); else $d=0;
            $rt['tsf2']='<b>'.($d?($d.'d '):'').gmdate("H:i:s",$rt['ts2']-$d*24*60*60).'</b>';

            if ($clientAccount->price_include_vat) {
                $rt['price_without_tax'] = number_format($rt['price'] * 100 / (100 + $tax_rate), 2, '.', '');
                $rt['price_with_tax'] = number_format($rt['price'], 2, '.', '');
                $rt['price'] = $rt['price_with_tax'] . ' (включая НДС)';
            } else {
                $rt['price_without_tax'] = number_format($rt['price'], 2, '.', '');
                $rt['price_with_tax'] = number_format($rt['price'] * (100 + $tax_rate) / 100, 2, '.', '');
                $rt['price'] = $rt['price_without_tax'] . ' (<b>' . $rt['price_with_tax'] . ' - Сумма с НДС</b>)';
            }

            $R['total']=$rt;
        }else{
            $sql="  select destination_id as dest, mob, -sum(cost) as price, sum(billed_time) as len, sum(1) as cnt
                            from calls_raw.calls_raw as cr ".
                            ($zone_filter
                                ? 'left join billing.instance_settings iss on iss.city_geo_id = cr.geo_id
                                   left join geo.geo g on g.id = iss.city_geo_id'
                                : ''
                            ) . "
                            where server_id=" . intval($region) . " and  ".MySQLDatabase::Generate($W) . 
                            "GROUP BY cr.destination_id, cr.mob";
            $R = array(     'mos_loc'=>  array('tsf1'=>'Местные Стационарные','cnt'=>0,'len'=>0,'price'=>0,'is_total'=>false),
                            'mos_mob'=> array('tsf1'=>'Местные Мобильные','cnt'=>0,'len'=>0,'price'=>0,'is_total'=>false),
                            'rus_fix'=> array('tsf1'=>'Россия Стационарные','cnt'=>0,'len'=>0,'price'=>0,'is_total'=>false),
                            'rus_mob'=> array('tsf1'=>'Россия Мобильные','cnt'=>0,'len'=>0,'price'=>0,'is_total'=>false),
                            'int'=>     array('tsf1'=>'Международка','cnt'=>0,'len'=>0,'price'=>0,'is_total'=>false));
            //$db_calls->Query($sql);
            $pg_db->Query($sql);
            //while ($r=$db_calls->NextRecord()){
            while ($r=$pg_db->NextRecord()){
                if ($r['dest'] <= 0 && $r['mob'] == 'f'){
                    $R['mos_loc']['len'] += $r['len'];
                    $R['mos_loc']['price'] += $r['price'];
                    $R['mos_loc']['cnt'] += $r['cnt'];
                }elseif ($r['dest'] <= 0 && $r['mob'] == 't'){
                    $R['mos_mob']['len'] += $r['len'];
                    $R['mos_mob']['price'] += $r['price'];
                    $R['mos_mob']['cnt'] += $r['cnt'];
                }elseif ($r['dest'] == 1 && $r['mob'] == 'f'){
                    $R['rus_fix']['len'] += $r['len'];
                    $R['rus_fix']['price'] += $r['price'];
                    $R['rus_fix']['cnt'] += $r['cnt'];
                }elseif ($r['dest'] == 1 && $r['mob'] == 't'){
                    $R['rus_mob']['len'] += $r['len'];
                    $R['rus_mob']['price'] += $r['price'];
                    $R['rus_mob']['cnt'] += $r['cnt'];
                }elseif ($r['dest'] == 2 || $r['dest'] == 3){
                    $R['int']['len'] += $r['len'];
                    $R['int']['price'] += $r['price'];
                    $R['int']['cnt'] += $r['cnt'];
                }
            }
            $cnt = 0; $len = 0; $price = 0;
            foreach($R as $k => $r){
                $cnt += $r['cnt'];
                $len += $r['len'];
                $price += $r['price'];
                if ($r['len']>=24*60*60) $d=floor($r['len']/(24*60*60)); else $d=0;
                $R[$k]['tsf2']='<b>'.($d?($d.'d '):'').gmdate("H:i:s",$r['len']-$d*24*60*60).'</b>';
                $R[$k]['price'] = number_format($r['price'], 2, '.','');
            }
            $rt['is_total']=true;
            $rt['tsf1']='<b>Итого</b>';
            if ($len>=24*60*60) $d=floor($len/(24*60*60)); else $d=0;
            $rt['tsf2']='<b>'.($d?($d.'d '):'').gmdate("H:i:s",$len-$d*24*60*60).'</b>';

            if ($clientAccount->price_include_vat) {
                $rt['price_without_tax'] = number_format($price * 100 / (100 + $tax_rate), 2, '.', '');
                $rt['price_with_tax'] = number_format($price, 2, '.', '');
                $rt['price'] = $rt['price_with_tax'] . ' (включая НДС)';
            } else {
                $rt['price_without_tax'] = number_format($price, 2, '.', '');
                $rt['price_with_tax'] = number_format($price * (100 + $tax_rate) / 100, 2, '.', '');
                $rt['price'] = $rt['price_without_tax'] . ' (<b>' . $rt['price_with_tax'] . ' - Сумма с НДС</b>)';
            }

            $rt['cnt']=$cnt;
            $R['total'] = $rt;
        }
        return $R;
    }

	function GetStatsCallback($from,$to,$detality,$client_id){
		global $db;
		$group='';
		if ($detality=='no'){
			$group=' group by 1';
			$format='';
		} else if ($detality=='year'){
			$group=' group by YEAR(ts)';
			$format='Y г.';
		} elseif ($detality=='month'){
			$group=' group by MONTH(ts)';
			$format='Месяц Y г.';
		} elseif ($detality=='day'){
			$group=' group by DATE(ts)';
			$format='d месяца Y г.';
		} else {
			$group='';
			$format='d месяца Y г. H:i:s';
		}
		$groupQ=$group?'sum':'';
		$whsql='(C.ts>=FROM_UNIXTIME('.$from.')) AND (C.ts<FROM_UNIXTIME('.$to.'+86400)) AND (client_id='.$client_id.')';

		$R=array();
		$sql="select C.*,PA.phone_num as num_from,PB.phone_num as num_to,".
				$groupQ."(A.tarif_sum) as priceFrom,".
				$groupQ."(B.tarif_sum) as priceTo,".
				$groupQ."(A.tarif_sum+B.tarif_sum) as price,".
				"UNIX_TIMESTAMP(ts) as ts1,".
				$groupQ."(B.lengthResult) as ts2 ".
				"from usage_callback_sess as C INNER JOIN usage_nvoip_sess as A ON A.id=C.sess_id_from INNER JOIN usage_nvoip_sess as B ON B.id=C.sess_id_to ".
				"INNER JOIN usage_nvoip_phone as PA ON PA.phone_id=A.phone_id ".
				"INNER JOIN usage_nvoip_phone as PB ON PB.phone_id=B.phone_id ".
				"where ".$whsql.$group." ORDER by C.ts ASC LIMIT 5000";
		$db->Query($sql);
        $rt=array('price'=>0,'priceFrom'=>0,'priceTo'=>0, 'ts2'=>0);

        $countRow = 0;
		while ($r=$db->NextRecord()){
			$r['tsf1']=mdate($format,$r['ts1']);
			if ($r['ts2']>=24*60*60) $d=floor($r['ts2']/(24*60*60)); else $d=0;
			$r['tsf2']=($d?($d.'d '):'').gmdate("H:i:s",$r['ts2']);
			$R[]=$r;
			$rt['price']+=$r['price'];
			$rt['priceFrom']+=$r['priceFrom'];
			$rt['priceTo']+=$r['priceTo'];
            $rt['ts2']+=$r['ts2'];
            $countRow++;
        }
		if ($countRow == 5000) trigger_error2('Статистика отображается не полностью. Сделайте ее менее детальной или сузьте временной период');

		$rt['ts1']='Итого';
		$rt['tsf1']='<b>Итого</b>';
		$rt['price']='<b>'.$rt['price'].'</b>';
		$rt['num_to']='&nbsp;';
		$rt['num_from']='&nbsp;';
		if ($rt['ts2']>=24*60*60) $d=floor($rt['ts2']/(24*60*60)); else $d=0;
		$rt['tsf2']='<b>'.($d?($d.'d '):'').gmdate("H:i:s",$rt['ts2']-$d*24*60*60).'</b>';
		$R[]=$rt;
		return $R;
	}

	function GetStatsPPP($from,$to,$detality,$logins){
		global $db;
		$group='';
		if ($detality=='year'){
			$group=' group by YEAR(AcctStartTime)';
			$format='Y г.';
		} elseif ($detality=='month'){
			$group=' group by MONTH(AcctStartTime)';
			$format='Месяц Y г.';
		} elseif ($detality=='day'){
			$group=' group by DATE(AcctStartTime)';
			$format='d месяца Y';
		} elseif ($detality=='login') {
			$group=' group by UserName';
			$format='&\nb\sp;';
		} else {
			$group='';
			$format='d месяца Y г. H:i:s';
		}

		$whsql='(AcctStartTime>=FROM_UNIXTIME('.$from.')) AND (AcctStartTime<FROM_UNIXTIME('.$to.'+86400))';
		if (!count($logins)) return array();
		if (count($logins)==1){
			foreach ($logins as $r) $whsql.=' AND (UserName="'.$r.'")';
		} else {
			$whsql.='';
			foreach ($logins as $k=>$r) $logins[$k]='"'.$r.'"';
			$p=implode(',',$logins);
			$whsql.=' AND (UserName IN ('.$p.'))';
		}

		$R=array();
		$sql="select UserName as login,".
					($group?'sum':'')."(AcctInputOctets) as in_bytes,".
					($group?'sum':'')."(AcctOutputOctets) as out_bytes,".
					"UNIX_TIMESTAMP(AcctStartTime) as ts1,".
					($group?'sum':'')."(AcctSessionTime) as ts2 ".
					"from radacct where ".$whsql.$group." ORDER by AcctStartTime ASC LIMIT 5000";
		$db->Query($sql);
        $rt=array('ts2'=>0,'in_bytes'=>0,'out_bytes'=>0);

        $countRow = 0;
		while ($r=$db->NextRecord()){
			$r['tsf1']=mdate($format,$r['ts1']);
			if ($r['ts2']>=24*60*60) $d=floor($r['ts2']/(24*60*60)); else $d=0;
			$r['tsf2']=($d?($d.'d '):'').gmdate("H:i:s",$r['ts2']);
			$R[]=$r;
			$rt['in_bytes']+=$r['in_bytes'];
			$rt['out_bytes']+=$r['out_bytes'];
            $rt['ts2']+=$r['ts2'];
            $countRow++;
		}
		if ($countRow == 5000) trigger_error2('Статистика отображается не полностью. Сделайте ее менее детальной или сузьте временной период');
		$rt['ts1']='Итого';
		$rt['tsf1']='<b>Итого</b>';
		$rt['login']='&nbsp;';
		if ($rt['ts2']>=24*60*60) $d=floor($rt['ts2']/(24*60*60)); else $d=0;
		$rt['tsf2']='<b>'.($d?($d.'d '):'').gmdate("H:i:s",$rt['ts2']-$d*24*60*60).'</b>';
		$R[]=$rt;
		return $R;
	}

	function GetRouteListByClient($client,$val = ''){
		global $db;
		$R=array();// and usage_ip_ports.trafcounttype="flows"
		$db->Query('select usage_ip_routes.* from usage_ip_routes left join usage_ip_ports on usage_ip_ports.id=usage_ip_routes.port_id where usage_ip_ports.client="'.$client.'" order by usage_ip_routes.net');
		$i=0;
		while ($r=$db->NextRecord()){
			$i++; $r['parity']=($i%2==0 ? 'odd' : 'even');
			$r['selected']=($r['net']==$val ? 'selected' : '');
			netmask_to_net_sum($r['net'],$r['ip'],$r['sum'],$r['ip_max']);
			if ($r['sum']!=1) $r['sum']=' (' . $r['sum'] . ')'; else $r['sum']='';
			$R[$r['id']]=$r;
		}
		return $R;
	}

    function get_routes_list_ip($routes_allB)
    {
        $routes_all = $routes_allB;
        foreach ($routes_allB as $k => $R) {
            $r = $R['net'];

            if (!$r) {
                continue;
            }

            if (!preg_match('/(\d+\.\d+\.\d+\.\d+)(\/(\d+))?/', $r, $m)) {
                return null;
            }

            $sum = 1;
            if (isset($m[3]) && $m[3] > 0) {
                for ($i = $m[3]; $i < 32; $i++) {
                    $sum *= 2;
                }
            }

            $ip = $m[1];
            $ipInt =ip2long($ip);

            for ($i = 0; $i < $sum; $i++) {
                $ip = long2ip($ipInt+$i);
                $routes_all[$ip] = [
                    'net' => $ip,
                    'actual_from' => $R['actual_from'],
                    'actual_to' => $R['actual_to']
                ];
            }
        }

        return $routes_all;
    }

    function get_routes_list($client)
    {
        $routes_all = array();

        //список всех сетей, нужен для вывода их списка.
        $V = UsageIpPorts::find()
            ->select('id')
            ->where(['client' => $client])
            ->orderBy(['id' => SORT_ASC])
            ->column();

        if (!$V) {
            return [[], []];
        }

        $query = UsageIpRoutes::find()
            ->where([
                'port_id' => $V
            ])
            ->orderBy([
                'net' => SORT_ASC,
                'id' => SORT_ASC
            ]);

        /** @var UsageIpRoutes $r */
        foreach ($query->each() as $r) {
            if ($r['net']) {
                $routes_all[$r->net] = [
                    'net' => trim($r->net),
                    'actual_from' => trim($r->actual_from),
                    'actual_to' => trim($r->actual_to)
                ];
            }
        }

        $routes_all_f = $this->get_routes_list_ip($routes_all);

        return [$routes_all_f, $routes_all];
    }

	function get_client_stats($client,$from,$to){
		list($a,$b)=$this->get_routes_list($client);
		$stats=$this->GetStatsInternet($from,$to,'no',$b);
		$c=count($stats); $r=$stats[$c-1];
		return array('in_bytes'=>$r['in_bytes'],'out_bytes'=>$r['out_bytes']);
	}

	function stats_send_add($fixclient){
		global $db,$design;
		$clients=get_param_raw('clients');
		$year = get_param_integer('year');
		$month = get_param_integer('month');
		$bytes=get_param_raw('bytes');
		$max_bytes=get_param_raw('max_bytes');
		$port_id=get_param_raw('port_id');
		$flag=get_param_raw('flag');
		if (!$year || !$month || !is_array($flag) || !is_array($bytes) || !is_array($max_bytes) || !is_array($port_id) || !is_array($clients)) return;// $this->stats_send_view($fixclient);
		foreach ($clients as $i=>$c) if (isset($flag[$i]) && $flag[$i]){
			@$db->Query('insert into stats_send (client,state,year,month,port_id,bytes,max_bytes,message) value ("'.$c.'","ready",'.$year.','.$month.','.$port_id[$i].','.$bytes[$i].','.$max_bytes[$i].',"'.$email[$i].'")');
		}
		return $this->stats_send_view($fixclient);
	}

	function stats_send_process($fixclient){
		global $design,$db;
		$is_test=get_param_integer('test',1);
		$cont=get_param_integer('cont',0);
		$db->Query('select client from stats_send where (!last_send || (last_send+INTERVAL 1 DAY < NOW())) AND (state!="sent") group by client order by state,last_send desc,client LIMIT 5');
		$C=array(); while ($r=$db->NextRecord()) $C[$r['client']]=$r['client'];
		foreach ($C as $client){
			$this->to_client($client,$is_test);
		}

		if (count($C)) $q='IF (client IN ("'.implode('","',$C).'"),1,0)'; else $q='0';
		$db->Query('select stats_send.*, c.id AS clientid,'.$q.' as cur_sent from stats_send
		inner join clients c ON c.client = stats_send.client
		order by cur_sent desc,state,last_send desc,client');
		$R=array(); while ($r=$db->NextRecord()) {
			$r['cur_sent']=(isset($C[$r['client']]))?1:0;
			if (isset($R[$r['client']])){
				$R[$r['client']][]=$r;
			} else $R[$r['client']]=array($r);
		}

		$design->assign('send_clients',$R);
		$design->assign('refresh',30*$cont);
		if ($cont) {
			trigger_error2('Отправка следующих 5ти уведомлений произойдёт через 30 секунд');
		}
		$design->AddMain('stats/send.tpl');
	}
	function stats_send_view($fixclient){
		global $db,$design;
		$db->Query('select * from stats_send order by state,last_send desc,client');
		$R=array(); while ($r=$db->NextRecord()) {
			if (isset($R[$r['client']])){
				$R[$r['client']][]=$r;
			} else $R[$r['client']]=array($r);
		}

		$design->assign('send_clients',$R);
		$design->AddMain('stats/send.tpl');
	}

	function to_client($client,$is_test = 1){
		global $db;
		$db->Query('select *,ROUND(bytes/(1024*1024),1) as mbytes,ROUND(max_bytes/(1024*1024),1) as mmax from stats_send where (client="'.$client.'") and (!last_send || (last_send+INTERVAL 1 DAY < NOW())) AND (state!="sent") group by client order by state,last_send desc,client LIMIT 5');
		$R=array(); while ($r=$db->NextRecord()) $R[]=$r;

		$db->Query('select * from clients where client="'.$client.'"');
		$C=$db->NextRecord();

		$subj="оПЕБШЬЕМХЕ РПЮТТХЙЮ";
		$body="сБЮФЮЕЛШЕ цНЯОНДЮ!" . "\n" . "яННАЫЮЕЛ бЮЛ, ВРН бШ ОПЕБШЯХКХ РПЮТТХЙ МЮ НДМНЛ ХГ БЮЬХУ ХМРЕПМЕР-ОНДЙКЧВЕМХИ.\nоН ЙЮФДНЛС ХГ ОНДЙКЧВЕМХИ РПЮТТХЙ ЯНЯРЮБКЪЕР:\n";
		foreach ($R as $r) {
			$body.=$r['mbytes'].' / '.$r['mmax'].'\n';
		}
		$body.="\n";

		$headers = "From: MCN Info <info@mcn.ru>\n";
		$headers.= "Content-Type: text/plain; charset=windows-1251\n";

		if ((defined('MAIL_TEST_ONLY') && (MAIL_TEST_ONLY==1)) || $is_test) $C['email']='andreys75@mcn.ru, shepik@yandex.ru';

		error_close();
		ob_start();
		$msg='Адрес получателя: '.$C['email'].'<br>';
		if (!$C['email']) $msg='Адрес получателя не указан<br>';

		if ($C['email'] && (mail ($C['email'],$subj,$body,$headers))){
			$db->Query('update stats_send set state="sent",last_send=NOW(),message="'.$msg.'" where (client="'.$client.'") and (state!="sent")');
		} else {
			$db->Query('update stats_send set state="error",last_send=NOW(),message="'.$msg.AddSlashes(ob_get_contents()).'" where (client="'.$client.'") and (state!="sent")');
		}
		ob_end_clean();
		error_init();
	}

	function stats_report_voip_e164_free(){
		global $db,$design;

		$query = "
			SELECT
				`int`.`e164`,
				`cl`.`client`,
				`cl`.`id`
			FROM
				(
					SELECT
						`uv`.`e164`,
						MAX(`uv`.`actual_to`) `to`
					FROM
						`usage_voip` `uv`
					WHERE
						`e164` NOT IN (
							SELECT
								`e164`
							FROM
								`usage_voip`
							WHERE
								DATE(NOW()) BETWEEN `actual_from` AND `actual_to`
							AND
								LENGTH(`e164`) = 11
						)
					AND
						LENGTH(`uv`.`e164`) = 11
					AND
						SUBSTRING(`uv`.`e164` FROM 1 FOR 4) <> '7095'
					AND
						SUBSTRING(`uv`.`e164` FROM 5 FOR 3) in ('638','950')
					GROUP BY
						`uv`.`e164`
				) `int`
			LEFT JOIN
				`usage_voip` `v`
			ON
				`v`.`e164` = `int`.`e164`
			AND
				`v`.`actual_to` = `int`.`to`
			LEFT JOIN
				`clients` `cl`
			ON
				`cl`.`client` = CAST(`v`.`client` AS CHAR)
		";
		$ret = $db->AllRecords($query,null,MYSQLI_ASSOC);

		$design->assign('e164',$ret);
		$design->AddMain('stats/voip_e164_free.tpl');
	}

	function stats_report_sms_gate($fixuser){
		global $db,$design;

		$dateFrom = new DatePickerValues('date_from', 'today');
		$dateTo = new DatePickerValues('date_to', 'today');
		$dateFrom->format = 'Y-m-d';$dateTo->format = 'Y-m-d';
		$date_from = $dateFrom->getDay();
		$date_for = $dateTo->getDay();

		$cf = (isset($_REQUEST['client_fil']))?(int)$_REQUEST['client_fil']:0;
		$clients = array();
		// <editor-fold defaultstate="collapsed" desc="clients_query">
		$query_clients = "
			select
				`u`.`client`,
				`u`.`client_id`,
				IF(`u`.`client`='".addcslashes($fixuser, "\\'")."' or `u`.`client_id`=".$cf.",'1','0') `current`
			from
				`usage_sms_gate` `u`
			order by
				`u`.`client`
		";
		// </editor-fold>
		$db->Query($query_clients);
		while($row=$db->NextRecord(MYSQLI_ASSOC)){
			$clients[$row['client_id']] = array(
				'client'=>$row['client'],
				'id'=>$row['client_id'],
				'current'=>$row['current']
			);
			if($row['current']==1)
				$curc = $row['client_id'];
		}
		if(!isset($curc)){
			$curc = 0;
		}
		$design->assign('clients',$clients);

        $sms = SmsStat::getSmsByClientDate($curc, $date_from, $date_for);
        $stat = [];
        $stat = array(
			'rows'=>array(),
			'total'=>0
		);
        foreach($sms as $index => $row){
            $stat['rows'][] = $row;
            $stat['total'] += $row['count'];
        }
		$design->assign('stat',$stat);
		$design->AddMain('stats/sms_gate.tpl');
	}

	function stats_report_services(){
		global $db,$design;
		if(isset($_REQUEST['s_i'])){
			$idxs = array('s_i','s_p','s_v','s_c','s_e');
			foreach($idxs as $key){
				$$key = $_REQUEST[$key];
			}
			foreach($idxs as $key){
				if($$key == 'unset')
					unset($$key);
				elseif($$key == 'on')
					$$key = true;
				else
					$$key = false;
				if(isset($$key)){
					$design->assign($key,$$key);
				}
			}
		}
                
                $def_statuses = array('work','negotiations','testing','connecting','debt','suspended','operator','distr','blocked');
		$tarif_extra_id = get_param_raw("s_s_e", 0);
		$tarif_internet_id = get_param_raw("s_s_i", 0);
		$statuses = get_param_raw("status", $def_statuses);
		$design->assign("s_s_e", $tarif_extra_id);
		$design->assign("s_s_i", $tarif_internet_id);
		$design->assign("statuses", $statuses);
                
                $fields = '';
                $W = array("AND");
                $W[] = "`c`.`status` IN ('".implode("','", $statuses)."')";
                $join = '';
                $group ='';
                
                if (isset($s_i) || isset($s_v) || isset($s_c))
                {
                    $fields .= "`uip`.`id` `iport_id`,
                                `ti`.`id` `itarif_id`,
				`ti`.`name` `itarif`,
				`ti`.`type` `itype`,";
                    $group .=",`uip`.`id`,
                             `ti`.`id`,
                             `ti`.`name`,
                             `ti`.`type`";
                    $join .= "
                        LEFT JOIN `usage_ip_ports` `uip` ON (`uip`.`client` = `c`.`client` AND NOW() BETWEEN `uip`.`actual_from` AND `uip`.`actual_to`) 
			LEFT JOIN `log_tarif` `lti` ON (`lti`.`id_service` = `uip`.`id` AND `lti`.`service` = 'usage_ip_ports')
			LEFT JOIN `tarifs_internet` `ti` ON (`ti`.`id` = `lti`.`id_tarif`)";
                    $W[] = "(`lti`.`id` is null or `lti`.`id` = (
					SELECT
						`id`
					FROM
						`log_tarif`
					WHERE
						`service` = 'usage_ip_ports'
					AND
						`date_activation` <= NOW()
					AND
						`id_service` = `uip`.`id`
					ORDER BY
						`date_activation` desc,
						`ts` desc,
						`id` desc
					LIMIT 1
				))";
                    if ($tarif_internet_id > 0)
                    {
                        $W[] = '`ti`.`id`=' . $tarif_internet_id;
                    }
                }
                if (isset($s_p))
                {
                    $fields .= "`uv`.`id` `voip_id`,
                                `tv`.`id` `ptarif_id`,
				`tv`.`name` `ptarif`,";
                    $group .= " ,`uv`.`id`,
                                `tv`.`id`,
				`tv`.`name`";
                    $join .= "
                        LEFT JOIN `usage_voip` `uv` ON (`uv`.`client` = `c`.`client` AND NOW() BETWEEN `uv`.`actual_from` AND `uv`.`actual_to`)
			LEFT JOIN `log_tarif` `ltv` ON (`ltv`.`id_service` = `uv`.`id` AND `ltv`.`service` = 'usage_voip')
			LEFT JOIN `tarifs_voip` `tv` ON (`tv`.`id` = `ltv`.`id_tarif`)";
                    $W[] = "(`ltv`.`id` is null or `ltv`.`id` = (
					SELECT
						`id`
					FROM
						`log_tarif`
					WHERE
						`service` = 'usage_voip'
					AND
						`date_activation` <= NOW()
					AND
						`id_service` = `uv`.`id`
					ORDER BY
						`date_activation` desc,
						`ts` desc,
						`id` desc
					LIMIT 1
				))";
                }
                if (isset($s_e))
                {
                    $fields .= "`ue`.`id` `extra_id`,
				`te`.`id` `etarif_id`,
				`te`.`description` `etarif`,";
                    $group .= ",`ue`.`id`,
				`te`.`id`,
				`te`.`description`";
                    $join .= "
                        LEFT JOIN 
				(
                                    (
                                        SELECT 
                                            id,client,actual_from,actual_to,status,tarif_id
                                        FROM 
                                            `usage_extra` 
                                    )
                                    union
                                    (
                                        SELECT 
                                            id,client,actual_from,actual_to,status,tarif_id
                                        FROM 
                                            `usage_welltime` 
                                    )
                                ) `ue` ON (`ue`.`client` = `c`.`client` AND NOW() BETWEEN `ue`.`actual_from` AND `ue`.`actual_to`)
			LEFT JOIN `tarifs_extra` `te` ON (`te`.`id` = `ue`.`tarif_id`)";
                    if ($tarif_extra_id)
                    {
                        $W[] = '`te`.`id`=' . $tarif_extra_id;
                    }
                }

		$query = "
			SELECT
                                ".$fields."
				`c`.`id`,
				`c`.`client`
			FROM
				`clients` `c`
                        ".$join."
			WHERE 
                            ".MySQLDatabase::Generate($W)."
			group by
				`c`.`id`,
				`c`.`client`
                                ".$group."
			order by
				`c`.`client`
		";

		if(isset($_REQUEST['fix']))
			$db->Query($query);
		$clients = array();
		$appending_scheme = array(
			'E'=>array(),
			'P'=>array(),
			'I'=>array(),
			'V'=>array(),
			'C'=>array()
		);
		while($row=$db->NextRecord(MYSQLI_ASSOC)){
			if(!isset($clients[$row['id']])){
				$clients[$row['id']] = array(
					'id'=>$row['id'],
					'client'=>$row['client'],
					'I'=>array('flag'=>false,'tarifs'=>array()),
					'P'=>array('flag'=>false,'tarifs'=>array()),
					'V'=>array('flag'=>false,'tarifs'=>array()),
					'C'=>array('flag'=>false,'tarifs'=>array()),
					'E'=>array('flag'=>false,'tarifs'=>array())
				);
			}
			if(isset($s_e)){
				if($row['etarif_id']){
					/*$clients[$row['id']]['E']['flag'] = true;
					$clients[$row['id']]['E']['tarifs'][] = array(
						'id'=>$row['etarif_id'],
						'name'=>$row['etarif']
					);*/
					$fl = true;
					if(!isset($appending_scheme['E'][$row['id']])){
						$appending_scheme['E'][$row['id']] = array();
						$appending_scheme['E'][$row['id']][] = $row['extra_id'];
					}elseif(!in_array($row['extra_id'],$appending_scheme['E'][$row['id']])){
						$appending_scheme['E'][$row['id']][] = $row['extra_id'];
					}else{
						$fl = false;
					}
					if($fl){
						$clients[$row['id']]['E']['flag'] = true;
						$clients[$row['id']]['E']['tarifs'][] = array(
							'id'=>$row['etarif_id'],
							'name'=>$row['etarif']
						);
					}
				}
			}if(isset($s_p)){
				if($row['ptarif_id']){
					/*$clients[$row['id']]['P']['flag'] = true;
					$clients[$row['id']]['P']['tarifs'][] = array(
						'id'=>$row['ptarif_id'],
						'name'=>$row['ptarif']
					);*/
					$fl = true;
					if(!isset($appending_scheme['P'][$row['id']])){
						$appending_scheme['P'][$row['id']] = array();
						$appending_scheme['P'][$row['id']][] = $row['voip_id'];
					}elseif(!in_array($row['voip_id'],$appending_scheme['P'][$row['id']])){
						$appending_scheme['P'][$row['id']][] = $row['voip_id'];
					}else{
						$fl = false;
					}
					if($fl){
						$clients[$row['id']]['P']['flag'] = true;
						$clients[$row['id']]['P']['tarifs'][] = array(
							'id'=>$row['ptarif_id'],
							'name'=>$row['ptarif']
						);
					}
				}
			}if(isset($s_i)){
				if($row['itype'] == 'I'){
					/*$clients[$row['id']]['I']['flag'] = true;
					$clients[$row['id']]['I']['tarifs'][] = array(
						'id'=>$row['itarif_id'],
						'name'=>$row['itarif']
					);
					continue;*/
					$fl = true;
					if(!isset($appending_scheme['I'][$row['id']])){
						$appending_scheme['I'][$row['id']] = array();
						$appending_scheme['I'][$row['id']][] = $row['iport_id'];
					}elseif(!in_array($row['iport_id'],$appending_scheme['I'][$row['id']])){
						$appending_scheme['I'][$row['id']][] = $row['iport_id'];
					}else{
						$fl = false;
					}
					if($fl){
						$clients[$row['id']]['I']['flag'] = true;
						$clients[$row['id']]['I']['tarifs'][] = array(
							'id'=>$row['itarif_id'],
							'name'=>$row['itarif']
						);
						continue;
					}
				}
			}if(isset($s_v)){
				if($row['itype'] == 'V'){
					/*$clients[$row['id']]['V']['flag'] = true;
					$clients[$row['id']]['V']['tarifs'][] = array(
						'id'=>$row['itarif_id'],
						'name'=>$row['itarif']
					);
					continue;*/
					$fl = true;
					if(!isset($appending_scheme['V'][$row['id']])){
						$appending_scheme['V'][$row['id']] = array();
						$appending_scheme['V'][$row['id']][] = $row['iport_id'];
					}elseif(!in_array($row['iport_id'],$appending_scheme['V'][$row['id']])){
						$appending_scheme['V'][$row['id']][] = $row['iport_id'];
					}else{
						$fl = false;
					}
					if($fl){
						$clients[$row['id']]['V']['flag'] = true;
						$clients[$row['id']]['V']['tarifs'][] = array(
							'id'=>$row['itarif_id'],
							'name'=>$row['itarif']
						);
						continue;
					}
				}
			}if(isset($s_c)){
				if($row['itype'] == 'C'){
					/*$clients[$row['id']]['C']['flag'] = true;
					$clients[$row['id']]['C']['tarifs'][] = array(
						'id'=>$row['itarif_id'],
						'name'=>$row['itarif']
					);
					continue;*/
					$fl = true;
					if(!isset($appending_scheme['C'][$row['id']])){
						$appending_scheme['C'][$row['id']] = array();
						$appending_scheme['C'][$row['id']][] = $row['iport_id'];
					}elseif(!in_array($row['iport_id'],$appending_scheme['C'][$row['id']])){
						$appending_scheme['C'][$row['id']][] = $row['iport_id'];
					}else{
						$fl = false;
					}
					if($fl){
						$clients[$row['id']]['C']['flag'] = true;
						$clients[$row['id']]['C']['tarifs'][] = array(
							'id'=>$row['itarif_id'],
							'name'=>$row['itarif']
						);
						continue;
					}
				}
			}
		}

		$show = array();
		foreach($clients as $k=>$c){
			if(
				(!isset($s_i) || (isset($s_i) && (($s_i && $c['I']['flag']) || (!$s_i && !$c['I']['flag']))))
			&&
				(!isset($s_p) || (isset($s_p) && (($s_p && $c['P']['flag']) || (!$s_p && !$c['P']['flag']))))
			&&
				(!isset($s_v) || (isset($s_v) && (($s_v && $c['V']['flag']) || (!$s_v && !$c['V']['flag']))))
			&&
				(!isset($s_c) || (isset($s_c) && (($s_c && $c['C']['flag']) || (!$s_c && !$c['C']['flag']))))
			&&
				(!isset($s_e) || (isset($s_e) && (($s_e && $c['E']['flag']) || (!$s_e && !$c['E']['flag']))))
			){
				$show[] =& $clients[$k];
			}
		}
		unset($clients);
		$tarifs = array(
			'I'=>array(),
			'P'=>array(),
			'V'=>array(),
			'C'=>array(),
			'E'=>array()
		);
		$tarifs_map = array(
			'I'=>array(),
			'P'=>array(),
			'V'=>array(),
			'C'=>array(),
			'E'=>array()
		);
		foreach($show as $k=>$v){
			foreach(array('I','P','V','C','E') as $tt){
				if($v[$tt]['flag']){
					foreach($v[$tt]['tarifs'] as $t){
						$tarifs[$tt][] = array(
							'id'=>$t['id'],
							'name'=>$t['name'],
							'client'=>&$show[$k]
						);
						$tarifs_map[$tt][count($tarifs[$tt])-1] = $t['name'];
					}
				}
			}
		}

		foreach($tarifs_map as $tt=>$v){
			asort($tarifs_map[$tt]);
		}

		//clients name sort
		$tarifs_map_new = array();
		$tarifs_buf = array();
		$cur_tar = null;
		foreach($tarifs_map as $ttype=>&$tars){
			$tarifs_map_new[$ttype] = array();
			foreach($tarifs_map[$ttype] as $record_key=>$tname){
				if(is_null($cur_tar))
					$cur_tar = $tname;
				if($tname <> $cur_tar){
					asort($tarifs_buf);
					foreach($tarifs_buf as $rkey=>&$cl){
						$tarifs_map_new[$ttype][$rkey] = $tarifs_map[$ttype][$rkey];
					}
					$tarifs_buf = array();
					$cur_tar = $tname;
				}
				$tarifs_buf[$record_key] = $tarifs[$ttype][$record_key]['client']['client'];
			}
			asort($tarifs_buf);
			foreach($tarifs_buf as $rkey=>&$cl){
				$tarifs_map_new[$ttype][$rkey] = $tarifs_map[$ttype][$rkey];
			}
			$tarifs_buf = array();
		}
		unset($tarifs_map);
		$tarifs_map =& $tarifs_map_new;

		$design->assign('i_tarifs',$db->AllRecords("select id, name from tarifs_internet where status not in ('archive') order by name"));
		$design->assign('e_tarifs',$db->AllRecords("select id, description, code, price, currency from tarifs_extra where status not in ('archive') order by code, description"));

		$design->assign('fix',isset($_REQUEST['fix']));
		$design->assign('scount',count($show));
		$design->assign('icount',count($tarifs_map['I']));
		$design->assign('pcount',count($tarifs_map['P']));
		$design->assign('vcount',count($tarifs_map['V']));
		$design->assign('ccount',count($tarifs_map['C']));
		$design->assign('ecount',count($tarifs_map['E']));
		$design->assign_by_ref('tarifs',$tarifs);
		$design->assign_by_ref('tarifs_map',$tarifs_map);
		$design->assign_by_ref('show',$show);
		$design->AddMain('stats/report_services.html');
	}

    function stats_report_agent()
    {
        include_once 'AgentReport.php';
        AgentReport::getReport();
    }

    function stats_report_sale_channel($fixclient)
    {
        global $db,$design,$user;

        $date_begin = get_param_raw('date_from', date('Y-m-d'));
        $date_end = get_param_raw('date_to',  date('Y-m-d'));
        $design->assign(array('date_begin'=>$date_begin, 'date_end'=>$date_end));
        $doer_filter = $doer_filter_ = get_param_protected('doer_filter','null');
        $design->assign('doer_filter_selected',$doer_filter);
        
        $all_doers = array();
        $dDoers = array('null' => 'Все');
        foreach($db->AllRecords("
                        SELECT
                            `id`,
                            `depart`,
                            `name`
                        FROM
                            `courier`
                        WHERE
                            `enabled`='yes' and `depart`='Региональный представитель'
                        ORDER BY
                            `depart`,
                            `name`
                    ", null, MYSQLI_ASSOC) as $id => $d)
        {
            $dDoers[$d["id"]] = $d["name"];
            $all_doers[] = $d["id"];
            if ($user->_Data['courier_id'] == $d["id"]) {
                $dDoers = array($d["id"]=>$d["name"]);
                break;
            }
        }
        $design->assign('doer_filter', $dDoers);

        $doerId = 0;
        if($doer_filter == 'null'){
            $doer_filter = ' AND `cr`.`id` IN('.implode(',', $all_doers).')';
        }else{
            $doerId = (int)$doer_filter;
            $doer_filter = '
                    AND `cr`.`id` = '.((int)$doer_filter);
        }

        $state_filter = $state_filter_ = get_param_protected('state_filter', 'null');
        
        $design->assign("state_filter_selected", $state_filter);
        
        if($state_filter == "null")
        {
            $state_filter = " not in (2,20,21,39,40)";
        }elseif($state_filter == 2 || $state_filter == 20){
            $state_filter = " in (2,20,39,40)";
        }else{
            $state_filter = ' = "'.$state_filter.'"';
        }
        
        $query = "
                SELECT distinct
                    DATE(`date`) `date`,
                    `courier_name`,
                    `company`,
                    `task`,
                    `cur_state`,
                    `client_id`,
                    `tt_id`,
                    `type`,
                    trouble_cur_state,
                    `bill_no`
                FROM
                    (
                        SELECT distinct
                            `ts`.`date_start` `date`,
                            `cr`.`name` `courier_name`,
                            `cl`.`company` `company`,
                            `tt`.`problem` `task`,
                            `ts`.`state_id` `cur_state`,
                            `tt`.`id` `tt_id`,
                            `cl`.`id` `client_id`,
                            'ticket' `type`,
                            cts.state_id `trouble_cur_state`,
                            `tt`.`bill_no`
                        FROM `tt_stages` `ts`
        
                        INNER JOIN `tt_doers` `td` ON `td`.`stage_id` = `ts`.`stage_id`
                        INNER JOIN `courier` `cr` ON `cr`.`id` = `td`.`doer_id` ".$doer_filter."
        
                        LEFT JOIN `tt_troubles` `tt`  ON `tt`.`id` = `ts`.`trouble_id`
        
                        LEFT JOIN `tt_stages`   `cts` ON cts.stage_id = tt.cur_stage_id
                        LEFT JOIN `clients`     `cl`  ON `cl`.`client` = `tt`.`client`
        
                        WHERE
                            /*`ts`.`state_id` = 4
                        AND */
                            cts.state_id ".$state_filter."
                        and
                            `ts`.`date_start` BETWEEN '".$date_begin." 00:00:00' AND '".$date_end." 23:59:59'
                    ) `tbl`
                ORDER BY
                    `date`,
                    `company`,
                    `courier_name`
            ";
        $ret = array();

        $db->Query($query);
        $sumBonus = 0;
        $count = 0;
        
        while($row=$db->NextRecord(MYSQLI_ASSOC)){
            if(!isset($ret[$row['date']])){
                $ret[$row['date']] = array('rowspan'=>0,'doers'=>array());
            }
        
            $ret[$row['date']]['rowspan']++;
        
            $bonus = 0;
            $row["bill_sum"] = $row["sum_good"] = $row["sum_service"] = $row["count_service"] = $row["count_good"] = 0;
        
            $ret[$row['date']]['doers'][] = array(
                            'name'=>$row['courier_name'],
                            'company'=>$row['company'],
                            'task'=>stripslashes($row['task']),
                            'cur_state'=>$row['cur_state'],
                            'tt_id'=>$row['tt_id'],
                            'client_id'=>$row['client_id'],
                            'type'=>$row['type'],
                            'trouble_cur_state'=>$row['trouble_cur_state'],
                            'bill_no' => $row["bill_no"],
                            'bill_sum' => $row["bill_sum"],
                            'sum_good' => $row["sum_good"],
                            'count_good' => $row["count_good"],
                            'sum_service' => $row["sum_service"],
                            'count_service' => $row["count_service"],
                            'bonus' => $bonus
            );
            $count++;
        
        }

        $design->assign('sum_bonus',$sumBonus);
        $design->assign('count',$count);
        $design->assign_by_ref('report_data',$ret);
        if(get_param_protected('print',false)){
            $design->assign('print',true);
            $design->ProcessEx('stats/sale_channel_report.tpl');
        }else{
            if(count($_POST)>0)
                $design->assign(
                        'print_report',
                        '?module=stats'.
                        '&action=report_sale_channel'.
                        '&print=yes'.
                        '&date_from='.$date_begin.
                        '&date_to='.$date_end.
                        (($doer_filter_<>'null')?'&doer_filter='.$doer_filter_:'').
                        (($state_filter_<>'null')?'&state_filter='.$state_filter_:'')
                );
        
            $design->assign(
                    'l_state_filter',
                    array_merge(
                            array(
                                array('id'=>'null','name'=>'Все (кроме: закрыт, отказ)'),
                            ),
                            $db->AllRecords("
                        SELECT
                            `id`,
                            `name`
                        FROM
                            `tt_states`
                        where
                            pk & 17703 #2047
                        ORDER BY
                            `name`
                    ", null, MYSQLI_ASSOC)
                    )
            );
        
            $design->assign('tt_states_list',$db->AllRecords('select * from tt_states','id',MYSQLI_ASSOC));
        
            $design->AddMain('stats/sale_channel_report.tpl');
        }
        
    }

    function stats_agent_settings($fixclient) 
    {
	if (!$fixclient) 
	{
		trigger_error2('Выберите клиента');
		return;
	}
	global $design,$fixclient_data,$db;
	
	if ($fixclient_data['is_agent'] != "Y")
	{
		trigger_error2('Клиент не является агентом');
		return;
	}
	$interest = null;
	$row_exists = AgentInterests::exists($fixclient_data['id']);
	if ($row_exists)
	{
		$interest = AgentInterests::find($fixclient_data['id']);
	}
	$sale_channel = $db->GetValue('SELECT name FROM sale_channels_old WHERE is_agent = 1 AND dealer_id = ' . $fixclient_data['id']);
	$design->assign('interest', $interest);
	$design->assign('sale_channel', $sale_channel);
	$interests_types = array(
		'prebills' => 
			array(
				'name' => '% от абонентских плат',
				'subtypes' => 
					array(
						'all' => array(
							'name' => 'Все',
							'field_name' => 'per_abon'
						),
					),
			), 
		'bills' => 
			array(
				'name' => '% от суммы счетов',
				'subtypes' => 
					array(
						'all' => array(
							'name' => 'Все',
							'field_name' => 'per_bill_sum'
						),
					),
			)
	);
	$design->assign('interests_types', $interests_types);
	
	$design->AddMain('stats/agent_settings.tpl');
	
    }
    function stats_save_agent_settings($fixclient)
    {
	if (!$fixclient) 
	{
		trigger_error2('Выберите клиента');
		header('Location: ?module=stats&action=agent_settings');
        exit;
	}
	global $fixclient_data;
	
	if ($fixclient_data['is_agent'] != "Y")
	{
		trigger_error2('Клиент не является агентом');
		header('Location: ?module=stats&action=agent_settings');
        exit;
	}
	
	$interests = get_param_raw('interest', array());
	$interest = get_param_raw('interest_type', 0);
	$row_exists = AgentInterests::exists($fixclient_data['id']);
	if ($row_exists)
	{
		$agent = AgentInterests::find($fixclient_data['id']);
		$agent->interest = $interest;
		foreach ($interests as $k => $v)
		{
			$agent->$k = $v;
		}
		$agent->save();
	} else {
		$data = array('client_id' => $fixclient_data['id'], 'interest' => $interest);
		foreach ($interests as $k => $v)
		{
			$data[$k] = $v;
		}
		AgentInterests::create($data);
	}
	header('Location: ?module=stats&action=agent_settings');

    }

function stat_test($itemNum, $dateFrom, $dateTo)
{
    global $db;
    $vv = $db->AllRecords("select l.bill_no, cast(s.date_start as date) as ds,  client
            from tt_troubles t , tt_stages s, tt_stages s2, newbill_lines l, newbills b, g_goods g
            where s.date_start between '".$dateFrom."' and '".$dateTo."'  and s.trouble_id = t.id and s.state_id = 18 and t.bill_no = l.bill_no and
            item_id =g.id and  g.num_id = ".$itemNum." and b.bill_no = l.bill_no and client ='WiMaxComstar'  AND b.biller_version = " . ClientAccount::VERSION_BILLER_USAGE . "
            and s2.stage_id =cur_stage_id and s2.state_id != 21  group by b.bill_no");

    $d = array();
    foreach($vv as $v)
    {
        if(!isset($d[$v["ds"]]))
        {
            $d[$v["ds"]] = array("income" => 0, "outlay" => 0);
        }

        $d[$v["ds"]]["outlay"]++;
    }
    return $d;
}

function make_calend($date, $data)
{
    $o = "";
    $d = strtotime(date("Y-m", $date)."-01 00:00:00");

    $wd = date("w", $d);
    if($wd == 0) $wd = 7;

    $sd_offset = $wd-1;

    $de = strtotime("+1 month -1 day", $d);

    $wde = date("w", $de);
    if($wde == 0) $wde = 7;
    $ed_offset = 7-$wde;



    $days = (round(($de-$d)/86400)+1)+$sd_offset+$ed_offset;


    $cStart = $sd_offset > 0 ? strtotime("- ".$sd_offset." days", $d) : 0;
    $o .=  "<table valign=top border=1 style='border-collapse:collapse;'>";
    $o .= "<tr><td>ПН</td><td>ВТ</td><td>СР</td><td>ЧТ</td><td>ПТ</td><td>СБ</td><td>ВС</td></tr>";
    $o .= "<tr>";
    for($i = 0; $i < $days; $i++)
    {
        if($i != 0 && $i != $days && $i % 7 == 0)
            $o .=  "</tr><tr>";


        $_d = strtotime("+ ".$i." days", $cStart);

        $_dd = date("Y-m-d", $_d);
        $o .= "<td valign=top onclick=\"ll('".$_dd."')\">";

        $o .= date("d M",$_d);

        $o.="<table border=0 width=100%>";
        foreach($data as $pId => $pData)
        {
            $o .= "<tr><td style='font: normal 6pt sans-serif;'>".$pId."</td><td>&nbsp;";
            if(isset($pData["data"][$_dd]))
            {
                $v = $pData["data"][$_dd];
                $o .= ($v["income"] ? "<b style='color:red;'>+".$v["income"]."</b>" : "");
                $o .= "</td><td>&nbsp;";
                $o .= $v["outlay"] ? "<b style='color:green;'>-".$v["outlay"]."<b>" : "";
            }else{
                $o .= "</td><td>&nbsp;";
            }
            $o .= "</td></tr>";
        }
        $o .= "</table>";

        $o .= "</td>";
    }
    $o .= "</tr>";
    $o .= "</table>";

    return $o;
}
function make_sum(&$dd)
{
    $sum = array("outlay" => 0, "income" => 0);
    foreach($dd["data"] as $d)
    {
        $sum["outlay"] += $d["outlay"];
        $sum["income"] += $d["income"];
    }
    $dd["sum"] = $sum;

}
function stats_report_wimax($fixclient, $genReport = false){
    global $db,$design;

    if($genReport)
    {
	$dateFrom = new DatePickerValues('dateNoRequest', '-1 day');
	$d1 = $d2 = $dateFrom->getDay();
	$date = $d1 == $d2 ? 'за '.$d1 : 'с '.$d1.' по '.$d2;
	$dateFrom->format = 'Y-m-d';
	$d1 = $d2 = $dateFrom->getDay();
    }else{
	$dateFrom = new DatePickerValues('date_from', 'today');
	$dateTo = new DatePickerValues('date_to', 'today');
	$d1 = $dateFrom->getDay();
        $d2 = $dateTo->getDay();
	$date = $d1 == $d2 ? 'за '.$d1 : 'с '.$d1.' по '.$d2;
	$dateFrom->format = 'Y-m-d';$dateTo->format = 'Y-m-d';
	$d1 = $dateFrom->getDay();
        $d2 = $dateTo->getDay();
    }
    
    $design->assign("date", $date);


    $r = $db->AllRecords($sql=
            "SELECT
            i.req_no,
            i.bill_no,
            (
             select group_concat(item)
             from newbill_lines nl
             where nl.bill_no = i.bill_no
            ) as param,
            tr.date_creation, i.fio, i.phone, st.name as state_name,
            (
             select group_concat( concat(date_edit, ' ', st.name,' ',comment) SEPARATOR ' // ')
             from tt_troubles tr2
             left join tt_stages ts2 on (tr2.id = ts2.trouble_id)
             left join tt_states st2 on st2.id = ts2.state_id
             where tr2.bill_no = i.bill_no
            ) as comment,
            i.comment2
            FROM `newbills_add_info` i, newbills b
            left join tt_troubles tr using (bill_no)
            left join tt_stages ts on (tr.cur_stage_id = ts.stage_id)
            left  join tt_states st on st.id = ts.state_id
#where order_given = 'WiMAX COMSTAR'
            where i.bill_no = b.bill_no and client_id = 9322
            and (
                    (ts.state_id in(2,20,21) and date_edit between '".$d1." 00:00:00' and '".$d2." 23:59:59')
                    or ts.state_id not in(2,20,21)
                )");

    //printdbg($sql);

    $design->assign("d", $r);
    $design->assign("showSelects", !$genReport);
    if($genReport)
    {
        return array( $date, $design->fetch("stats/wimax.html", null, null, false));
    }else{
        $design->AddMain("stats/wimax.html");
    }
}

function stats_courier_sms($fixclient, $genReport = false){
    global $db,$design;

    if($genReport)
    {
	$dateFrom = new DatePickerValues('dateNoRequest', '-1 day');
	$d1 = $d2 = $dateFrom->getDay();
	$date = $d1 == $d2 ? 'за '.$d1 : 'с '.$d1.' по '.$d2;
	$dateFrom->format = 'Y-m-d';
	$d1 = $d2 = $dateFrom->getDay();
    }else{
	$dateFrom = new DatePickerValues('date_from', 'today');
	$dateTo = new DatePickerValues('date_to', 'today');
	$d1 = $dateFrom->getDay();
        $d2 = $dateTo->getDay();
	$date = $d1 == $d2 ? 'за '.$d1 : 'с '.$d1.' по '.$d2;
	$dateFrom->format = 'Y-m-d';$dateTo->format = 'Y-m-d';
	$d1 = $dateFrom->getDay();
        $d2 = $dateTo->getDay();
    }

    $design->assign("date", $date);


    $r = $db->AllRecords(
            "SELECT s.sms_sender as phone, c.name, sum(1) as count, group_concat(bill_no) bills FROM `newbill_sms` s
            left join courier c on s.sms_sender = c.phone
            where sms_send between '".$d1." 00:00:00' and '".$d2." 23:59:59' group by sms_sender");

    foreach($r as &$l)
        $l["bills"] = explode(",", $l["bills"]);

    $design->assign("d", $r);
    $design->assign("showSelects", !$genReport);
    if($genReport)
    {
        return array( $date, $design->fetch("stats/courier_sms.html", null, null, false));
    }else{
        $design->AddMain("stats/courier_sms.html");
    }
}

function stats_support_efficiency($fixclient)
{
    global $db,$design;

    $m = array();
    $total = array(
        "monitoring"   => 0, 
        "trouble"      => 0, 
        "consultation" => 0,
        "task"         => 0
        );

    $date = "";

    $usages = array(
            "" => "Без услуги",
            "usage_extra" => "Доп услуги",
            "usage_ip_ports" => "Интернет",
            "usage_voip" => "Телефония",
            "usage_virtpbx" => "Виртуальная АТС",
            "usage_welltime" => "Welltime"
            );
    $usage = array_keys($usages);

	$date_from = new DatePickerValues('date_from', 'first');
	$date_to = new DatePickerValues('date_to', 'last');
	$dateFrom = $date_from->getDay();$dateTo = $date_to->getDay();
	$date = $dateFrom == $dateTo ? 'за '.$dateFrom : 'с '.$dateFrom.' по '.$dateTo;
	$date_from->format = 'Y-m-d';$date_to->format = 'Y-m-d';
	$dateFrom = $date_from->getDay();$dateTo = $date_to->getDay();

    $onCompleted_users = array();
    $onCompleted_data = array();
    $onCompleted_total = array();
    $onCompleted_rating = array();
    
    $onCompleted_users2 = $onCompleted_data2 = $onCompleted_total2 = $onCompleted_rating2 = array();
    
    if(get_param_raw("make_report", "") == "OK")
    {
        $usage = get_param_raw("usage", $usage);

        $r = $db->AllRecords(
                $q = "
                    select *, 
                        count(1) as c, 
                        sum(rating) rating, 
                        sum(rating_count) as rating_count 
                    from (
                        SELECT 
                            uu.name,
                            user_author, 
                            trouble_subtype,
                            (select sum(rating) from tt_stages  where trouble_id =tt.id) as rating,
                            (select sum(if(rating=0,0,1)) from tt_stages where trouble_id =tt.id) as rating_count
                        FROM `tt_troubles` tt ,user_users uu 
                    where 
                        usergroup ='support' 
                        and uu.user = tt.user_author 
                        and date_creation between '".$dateFrom." 00:00:00' and '".$dateTo." 23:59:59' 
                        and trouble_type in ('trouble', 'task', 'support_welltime')
                        and service in ('".implode("','", $usage)."')
                        order by uu.name
                      ) a 
                    group by user_author, trouble_subtype
                ");

        $count = 0;
        foreach($r as $l)
        {
            if($l["trouble_subtype"] == "") continue;

            if(!isset($m[$l["user_author"]]))
                $m[$l["user_author"]] = array(
                    "name" => $l["name"], 
                    "count" => $count++, 
                    "data" => array()
                );

            $m[$l["user_author"]]["data"][$l["trouble_subtype"]] = array(
                    "count" => $l["c"],
                    "rating_avg" => ($l["rating_count"] > 0 ? $l["rating"] / $l["rating_count"] : 0),
                    "rating_count" => $l["rating_count"]
                    );

            if(isset($total[$l["trouble_subtype"]]))
                $total[$l["trouble_subtype"]] += $l["c"];
        }

        list($onCompleted_data, $onCompleted_users, $onCompleted_total, $onCompleted_rating) = $this->stats_support_efficiency__basisOnCompleted($dateFrom, $dateTo, $usage);
        list($onCompleted_data2, $onCompleted_users2, $onCompleted_total2, $onCompleted_rating2) = $this->stats_support_efficiency__basisOnStartDate($dateFrom, $dateTo, $usage);
    }

    $design->assign('usages', $usages);
    $design->assign('usages_selected', $usage);

    $design->assign(array(
                    "on_completed_data"=>$onCompleted_data,
                    "on_completed_users"=>$onCompleted_users,
                    "on_completed_total"=>$onCompleted_total,
                    "on_completed_rating"=>$onCompleted_rating,
                    "on_completed_data2"=>$onCompleted_data2,
                    "on_completed_users2"=>$onCompleted_users2,
                    "on_completed_total2"=>$onCompleted_total2,
                    "on_completed_rating2"=>$onCompleted_rating2
                    ));
    
    $design->assign("date", $date);
    $design->assign("d", $m);
    $design->assign("total", $total);
    $design->AddMain("stats/support_efficiency.html");
}
function stats_support_efficiency__basisOnStartDate(&$dateFrom, &$dateTo, &$usage)
{
    global $db;

    $rs = $db->AllRecords($q = "
            SELECT
                trouble_subtype as type,
                ts.trouble_id,
                ts.state_id,
                user_main,
                user_edit,
                tt.user_author,
                rating,
                user_rating
            FROM
                tt_stages ts
            LEFT JOIN `tt_troubles` tt ON tt.id = ts.trouble_id
            LEFT JOIN `user_users` u ON u.user = tt.user_author
            WHERE
                usergroup IN ('support','manager') AND 
                date_creation between '".$dateFrom." 00:00:00' and '".$dateTo." 23:59:59' AND 
                trouble_type in ('trouble', 'task', 'support_welltime') AND 
                service in ('".implode("','", $usage)."')
            ORDER BY tt.id, ts.stage_id
            ");
    $tmp = array();
    $rating = array();
    $counter = array(
                    "7" => array(), // completed
                    "2" => array()  // closed
    );

    $total = array(
                    "7" => array(), // completed
                    "2" => array()  // closed
    );

    $users = array();

    $troubleId = 0;
    foreach ($rs as $r)
    {
        if ($r["state_id"] == 7 && strlen($r["user_rating"])) {
            if (!isset($tmp[$r["trouble_id"]]))
                $tmp[$r["trouble_id"]] = array('type'=>$r["type"],'user_rating'=>'','7'=>0,'2'=>0,'1u'=>array());

            if ($r["rating"] > 0) {
                if (strlen($tmp[$r["trouble_id"]]['user_rating']) && $tmp[$r["trouble_id"]]['user_rating']!=$r["user_rating"])
                    $tmp[$r["trouble_id"]]['1u'][]=$tmp[$r["trouble_id"]]['user_rating'];

                $tmp[$r["trouble_id"]]['type']=$r["type"];
                $tmp[$r["trouble_id"]]['user_rating']=$r["user_rating"];
                $tmp[$r["trouble_id"]]['7']=$r["rating"];
            }
        }
        if ($r["state_id"] == 2 && strlen($r["user_rating"])) {
            if (isset($tmp[$r["trouble_id"]]) && $r["rating"] > 0 && strlen($r["user_rating"])) {
                $tmp[$r["trouble_id"]]['2']=$r["rating"];
            }
        }
        // new trouble, reset
        if ($r["trouble_id"] != $troubleId)
        {
            $troubleId = $r["trouble_id"];
            $state = $r["state_id"];
            $user = $r["user_main"];

            continue; //this first stage
        }

        $user = $r["user_author"];

        if ($state != $r["state_id"])
        {
            if($r["state_id"] == 7 || $r["state_id"] == 2)
            {
                if(!isset($counter[$r["state_id"]][$r["type"]]))
                    $counter[$r["state_id"]][$r["type"]] = array();

                if(!isset($counter[$r["state_id"]][$r["type"]][$user]))
                    $counter[$r["state_id"]][$r["type"]][$user] = 0;

                $counter[$r["state_id"]][$r["type"]][$user]++;


                if (!isset($total[$r["state_id"]][$r["type"]]))
                    $total[$r["state_id"]][$r["type"]] = 0;

                $total[$r["state_id"]][$r["type"]]++;


                $users[$user] = $user;
            }

            $state = $r["state_id"];
        }
    }
    foreach ($tmp as $k=>$rat) {
        if (strlen($rat['user_rating'])) {
            if (!isset($rating[$rat['user_rating']]))
                $rating[$rat['user_rating']] = array();
            if (!isset($rating[$rat['user_rating']][$rat['type']]))
                $rating[$rat['user_rating']][$rat['type']] = array('7'=>0,'2'=>0);

            $rating[$rat['user_rating']][$rat['type']]['7']+=$rat['7'];
            $rating[$rat['user_rating']][$rat['type']]['2']+=$rat['2'];

            if (!isset($users[$rat['user_rating']])) $users[$rat['user_rating']] = $rat['user_rating'];
        }
    }

    foreach($db->AllRecords("select user, name from user_users where user in ('".implode("','", $users)."')") as $u)
    {
        $users[$u["user"]] = $u["name"];
    }

    return array($counter, $users, $total, $rating);
}

function stats_support_efficiency__basisOnCompleted(&$dateFrom, &$dateTo, &$usage)
{
    global $db;

    $rs = $db->AllRecords($q = "
            SELECT
                trouble_subtype as type,
                ts.trouble_id,
                ts.state_id,
                user_main,
                user_edit,
                tt.user_author,
                rating,
                user_rating
            FROM
                tt_stages ts
            LEFT JOIN `tt_troubles` tt ON tt.id = ts.trouble_id
            LEFT JOIN `user_users` u ON u.user = tt.user_author
            WHERE
                ts.state_id IN(2,7) AND 
                usergroup IN ('support','manager') AND 
                date_edit between '".$dateFrom." 00:00:00' and '".$dateTo." 23:59:59' AND 
                trouble_type in ('trouble', 'task', 'support_welltime') AND 
                service in ('".implode("','", $usage)."') 
            ORDER BY tt.id, ts.stage_id
            ");
    $tmp = array();
    $rating = array();
    $counter = array(
        "7" => array(), // completed
        "2" => array(),  // closed
        "2w7" => array()  //closed without completed
        );

    $total = array(
        "7" => array(), // completed
        "2" => array(),  // closed
        "2w7" => array()  //closed without completed
    );

    $users = array();

    $troubleId = 0;
    foreach ($rs as $r)
    {
        if (!isset($tmp[$r["trouble_id"]]))
            $tmp[$r["trouble_id"]] = array('type'=>$r["type"], 'user_author'=>$r["user_author"], 'user_7'=>'', 'user_2'=>'','user_rating'=>'','7'=>0,'2'=>0,'1u'=>array());
        
        if ($r["state_id"] == 7) {
            $tmp[$r["trouble_id"]]['user_7'] = $r["user_edit"];

            if ($r["rating"] > 0 && strlen($r["user_rating"])) {
                if (strlen($tmp[$r["trouble_id"]]['user_rating']) && $tmp[$r["trouble_id"]]['user_rating']!=$r["user_rating"]) 
                    $tmp[$r["trouble_id"]]['1u'][]=$tmp[$r["trouble_id"]]['user_rating'];

                $tmp[$r["trouble_id"]]['user_rating']=$r["user_rating"];
                $tmp[$r["trouble_id"]]['7']=$r["rating"];
            }
        }
        
        if ($r["state_id"] == 2) {
            $tmp[$r["trouble_id"]]['user_2'] = $r["user_edit"];
            if ($r["rating"] > 0 && strlen($r["user_rating"])) {
                $tmp[$r["trouble_id"]]['2']=$r["rating"];
            }
        }
    }

    foreach ($tmp as $trouble_id=>$t) {
        
        //counter calculation
        
        if (strlen($t['user_7'])) {
            if (!isset($counter['7'][$t['type']])) $counter['7'][$t['type']] = array();
            if (!isset($total['7'][$t['type']])) $total['7'][$t['type']] = 0;
            
            if (!isset($counter['7'][$t['type']][$t['user_7']])) 
                $counter['7'][$t['type']][$t['user_7']] = 0;
            
            $counter['7'][$t['type']][$t['user_7']]++;
            $total['7'][$t["type"]]++;
            
            if (!isset($users[$t['user_7']])) 
                $users[$t['user_7']] = $t['user_7'];
        }
        
        
        if (strlen($t['user_2'])) {
            if (strlen($t['user_7'])) {
                // closed and completed
                if (!isset($counter['2'][$t['type']])) $counter['2'][$t['type']] = array();
                if (!isset($total['2'][$t['type']])) $total['2'][$t['type']] = 0;
                
                if (!isset($counter['2'][$t['type']][$t['user_7']]))
                    $counter['2'][$t['type']][$t['user_7']] = 0;
                
                $counter['2'][$t['type']][$t['user_7']]++;
                $total['2'][$t["type"]]++;
                
                if (!isset($users[$t['user_7']])) 
                    $users[$t['user_7']] = $t['user_7'];
            } else {
                // closed not completed
                if (!isset($counter['2w7'][$t['type']])) $counter['2w7'][$t['type']] = array();
                if (!isset($total['2w7'][$t['type']])) $total['2w7'][$t['type']] = 0;
                
                if (!isset($counter['2w7'][$t['type']][$t['user_2']]))
                    $counter['2w7'][$t['type']][$t['user_2']] = 0;
                
                $counter['2w7'][$t['type']][$t['user_2']]++;
                $total['2w7'][$t["type"]]++;
                
                if (!isset($users[$t['user_2']])) 
                    $users[$t['user_2']] = $t['user_2'];
            }
        
        }

        //rating calculation
        if (strlen($t['user_rating'])) {
            if (!isset($rating[$t['user_rating']])) 
                $rating[$t['user_rating']] = array();
            if (!isset($rating[$t['user_rating']][$t['type']])) 
                $rating[$t['user_rating']][$t['type']] = array('7'=>0,'2'=>0);

            $rating[$t['user_rating']][$t['type']]['7']+=$t['7'];
            $rating[$t['user_rating']][$t['type']]['2']+=$t['2'];
            
            if (!isset($users[$t['user_rating']])) 
                $users[$t['user_rating']] = $t['user_rating'];
        }
    }

    foreach($db->AllRecords("select user, name from user_users where user in ('".implode("','", $users)."')") as $u)
    {
        $users[$u["user"]] = $u["name"];
    }

    return array($counter, $users, $total, $rating);
}

function stats_report_netbynet($fixclient, $genReport = false, $viewLink = true){
    $this->stats_report_plusopers($fixclient, 'nbn', $genReport, $viewLink);
}

function stats_report_onlime($fixclient, $genReport = false, $viewLink = true){
    $this->stats_report_plusopers($fixclient, 'onlime', $genReport, $viewLink);
}

function stats_report_onlime2($fixclient, $genReport = false, $viewLink = true){
    $this->stats_report_plusopers($fixclient, 'onlime2', $genReport, $viewLink);
}

function stats_report_onlime_all($fixclient, $genReport = false, $viewLink = true){
    $this->stats_report_plusopers($fixclient, 'onlime_all', $genReport, $viewLink);
}

function stats_report_plusopers($fixclient, $client, $genReport = false, $viewLink = true){
    global $db,$design;

    $viewLink = $viewLink && !defined("no_link");

    $design->assign("viewLink", $viewLink);

    $names = array(
            "nbn"     => "NetByNet",
            "onlime"  => "OnLime",
            "onlime2" => "OnLime2",
            "onlime_all" => "OnLime 1+2"
            );
    $reports = array(
            "nbn"     => "netbynet",
            "onlime2" => "onlime2",
            "onlime"  => "onlime",
            "onlime_all" => "onlime_all"
            );

    $report = "report_".$reports[$client];
    $design->assign("client_name", $names[$client]);
    $design->assign("report",  $report);

    if(in_array(get_param_raw("do", ""), array("add", "edit")))
    {
        $error = "";
        $info = "";
        $a = array("bill_no" => get_param_raw("bill_no", ""));

        if(get_param_raw("info_form", ""))
        {
        	$db->QueryUpdate("params", "param", array(
        		"param" => "onlime_info",
        		"value" => get_param_raw("onlime_info","")
        	)
        	);
        }

        if(get_param_raw("form", ""))
        {
        	list($fields, $error, $a) = $this->report_plusopers__getFields_and_check($client, $a);

            if(!$error)
            {

                $i = $db->GetRow("select * from newbills_add_info where bill_no = '".$db->escape($a["bill_no"])."'");

                $rNBN = new requestPlusOper();
                $info = $rNBN->create($client, $a);

                $isNewDateDeliv = false;
                if($i && $i["comment1"])
                {
                    @list(, $i["date_deliv"]) = explode(": ", $i["comment1"]);
                    $isNewDateDeliv = $i["date_deliv"] != $a["date_deliv"];
                }

                if(($a["comment"] || $isNewDateDeliv) && $a["bill_no"])
                {
                    $tId = $db->GetValue("select id from tt_troubles where bill_no = '".$a["bill_no"]."'");
                    $curStageId = $db->GetValue("select cur_stage_id from tt_troubles where bill_no = '".$a["bill_no"]."'");
                    $stateId = $db->GetValue("select state_id from tt_stages where stage_id = '".$curStageId."'");

                    if($isNewDateDeliv)
                        $a["comment"] .= "\nДоставка на: ".$a["date_deliv"];

                    $tsid = $db->QueryInsert('tt_stages',array(
                                'trouble_id'=>$tId,
                                'state_id'=>$stateId,
                                'user_main'=>$client,
                                'date_start'=>array('now()'),
                                'date_finish_desired'=>array('now()'),
                                'comment' => trim($a["comment"])
                                )
                            );
                }

                /*
                header("Location: ./?".($viewLink ? "module=stats&action=".$report : "")."");
                exit();
                */
                $a = array("bill_no" => "", "qty" => 1);

            }
        }else{
            $billNo = get_param_raw("bill_no", "");
            $a = $this->report_plusopers__Load($client, $billNo);

            if(!$billNo) //on add
            {
            	$a["qty"] = 1;
            }
        }

        $qtys = array();
        for($i =0; $i<=10 ; $i++)
        	$qtys[$i]=$i;

        $design->assign("a", $a);
        $design->assign("error", $error);
        $design->assign("info", $info);

        $design->assign("qtys", $qtys);
        $design->assign("once", 4);

        $design->assign("deliv_type", array("moskow" => "По Москве","mkad" => "За МКАД"));
        $design->assign("onlime_info", $db->GetValue("select value from params where param='onlime_info'"));

        $design->AddMain("stats/".$reports[$client]."_add.html");
        return;
    }

    if($genReport)
    {
        $dateFrom = new DatePickerValues('dateNoRequest', '-1 day');
        $d1 = $d2 = $dateFrom->getDay();
        $date = $d1 == $d2 ? 'за '.$d1 : 'с '.$d1.' по '.$d2;
        $dateFrom->format = 'Y-m-d';
        $d1 = $d2 = $dateFrom->getDay();
    }else{
        $dateFrom = new DatePickerValues('date_from', 'first');
        $dateTo = new DatePickerValues('date_to', 'last');
        $d1 = $dateFrom->getDay();
        $d2 = $dateTo->getDay();
        $date = $d1 == $d2 ? 'за '.$d1 : 'с '.$d1.' по '.$d2;
        $dateFrom->format = 'Y-m-d';$dateTo->format = 'Y-m-d';
        $d1 = $dateFrom->getDay();
        $d2 = $dateTo->getDay();

        $filterPromoAll = array("all"=> "Все заявки", "promo" => "По акции", "no_promo" => "Не по акции");
        $filterPromo = get_param_raw("filter_promo", "all");

        $design->assign("filter_promo_all", $filterPromoAll);
        $design->assign("filter_promo", $filterPromo);
    }
    
    $design->assign("date", $date);

    if($client == "onlime_all")
    {
        list($r1, $closeList1, $deliveryList1) = $this->report_plusopers__getCount("onlime", $d1, $d2, $filterPromo);
        list($r2, $closeList2, $deliveryList2) = $this->report_plusopers__getCount("onlime2", $d1, $d2, $filterPromo);

        foreach($r1 as $k => $v) $r2[$k]+= $v;

        $r = $r2;

    }else{
        list($r, $closeList, $deliveryList) = $this->report_plusopers__getCount($client, $d1, $d2, $filterPromo);
    }


    $design->assign("s", $r);

    $list = array();

    if(($listType = get_param_raw("list", "")) != "")
    {
        if($client == "onlime_all")
        {
            $list1 = $this->report_plusopers__getList("onlime", $listType, $d1, $d2, $deliveryList1, $closeList1, $filterPromo);
            $list2 = $this->report_plusopers__getList("onlime2", $listType, $d1, $d2, $deliveryList2, $closeList2, $filterPromo);

            $list = array_merge($list1, $list2);
        }else{
            $list = $this->report_plusopers__getList($client, $listType, $d1, $d2, $deliveryList, $closeList, $filterPromo);
        }
    }

    $total = [
        "count_3" => 0,
        "count_9" => 0,
        "count_11" => 0,
        "count_12" => 0,
        "count_17" => 0,
        "count_18" => 0,
        "count_19" => 0,
        "count_22" => 0,
        "count_28" => 0,
        "count_41" => 0,
        "count_44" => 0,
        "count_45" => 0,
    ];

    foreach ($list as $l) {
        $total["count_3"] += $l["count_3"];
        $total["count_9"] += $l["count_9"];
        $total["count_11"] += $l["count_11"];
        $total["count_12"] += $l["count_12"];
        $total["count_17"] += $l["count_17"];
        $total["count_18"] += $l["count_18"];
        $total["count_19"] += $l["count_19"];
        $total["count_22"] += $l["count_22"];
        $total["count_28"] += $l["count_28"];
        $total["count_41"] += $l["count_41"];
        $total["count_44"] += $l["count_44"];
        $total["count_45"] += $l["count_45"];
    }

    $design->assign("list", $list);
    $design->assign("total", $total);

    unset($_GET["list"]);
    $url = "";
    foreach($_GET as $k => $v)
    {
        $url .= ($url ? "&" :"").$k."=".$v;
    }

    $design->assign("url", $url);

    $design->assign("showSelects", !$genReport);



    if(get_param_raw("export", "") == "excel")
    {
        foreach($list as  &$l) {
            $l["count_3"] = (int)$l["count_3"];
            $l["count_9"] = (int)$l["count_9"];
            $l["count_11"] = (int)$l["count_11"];
            $l["count_12"] = (int)$l["count_12"];
            $l["count_17"] = (int)$l["count_17"];
            $l["count_18"] = (int)$l["count_18"];
            $l["count_19"] = (int)$l["count_19"];
            $l["count_22"] = (int)$l["count_22"];
            $l["count_28"] = (int)$l["count_28"];
            $l["count_41"] = (int)$l["count_41"];
            $l["count_44"] = (int)$l["count_44"];
            $l["count_45"] = (int)$l["count_45"];
            $design->assign("i_stages", $l["stages"]);
            $design->assign("last", 1000);
            $html = $design->fetch("stats/onlime_stage.tpl");
            $html = str_replace(array("\r","\n", "<br>", "    ", "   ", "   ", "  "), array("","", "\n", " ", " ", " ", " "), $html);
            $l["stages_text"] = strip_tags($html);
        }
        unset($l);

        $list[] = $total+array("date_creation" => "Итого:");

        $sTypes = array(
                "work"   => array("sql" => "is_rollback =0 and state_id not in (2,20,21)",
                                  "title" => ($client == "nbn" ? "в работе" : "В Обработке")),
                "close"  => array("sql" => "is_rollback =0 and state_id in (2,20)",
                                  "title" => ($client == "nbn" ? "закрытые" : "Доставлен")),
                "reject" => array("sql" => "is_rollback =0 and state_id = 21",
                                  "title" => ($client == "nbn" ? "в отказе" : "Отказ")),
                "delivery" => array(                            "title" => "доставка")
                );

        $this->GenerateExcel("OnLime__".str_replace(" ", "_", $sTypes[$listType]["title"])."__".$d1."__".$d2,
                array(
                    "Оператор" => "fio_oper",
                    "Номер счета OnLime" => "req_no",
                    "Номер счета Маркомнет Сервис" => "bill_no",
                    "Дата создания заказа" => "date_creation",
                    "Кол-во Onlime-Telecard" => "count_3",
                    "Кол-во HD-ресивер OnLime" => "count_9",
                    "Кол-во HD-ресивер с диском" => "count_11",
                    "NetGear Беспроводной роутер, JNR3210-1NNRUS" => "count_12",
                    "ТВ-приставка «Стандарт»" => "count_17",
                    "Zyxel KEENETIC EXTRA Беспроводной роутер" => "count_18",
                    "D-Link DWA-182/RU/C1A Беспроводной адаптер" => "count_19",
                    "Gigaset C530 IP IP-телефон" => "count_22",
                    "Приставка, SML-482 HD Base с опцией Wi-Fi" => "count_28",
                    "Пульт универсальный" => "count_41",
                    "Видеокамера Hikvision DS-2CD-VC1W" => "count_44",
                    "Пульт RM-E12" => "count_45",
                    "Серийные номера" => "serials",
                    "Номер купона" => "coupon",
                    "ФИО клиента" => "fio",
                    "Телефон клиента" => "phone",
                    "Адрес" => "address",
                    "Дата доставки желаемая" => "date_deliv",
                    "Дата доставки фактическа" => "date_delivered",
                    "Этапы" => "stages_text"
                    ),
                $list);

    }

    if($genReport)
    {
        return array( $date, $design->fetch("stats/".$reports[$client].".html", null, null, false));
    }else{

        $design->AddMain("stats/".$reports[$client].".html");
    }
}

function stats_onlime_details($fixclient)
{
    global $design;
    $order_id = get_param_integer('order_id', '');
    $data = array();
    if ($order_id && OnlimeOrder::exists($order_id))
    {
        $order = OnlimeOrder::find($order_id);
        $data = unserialize($order->order_serialize);
        if (isset($data['id']))
        {
            $data['id'] = $order_id;
        }
        if (isset($data['date']))
        {
            $data['date'] = strtotime($data['date']);
        }
        if (isset($data['delivery']['date']))
        {
            $data['delivery']['date'] = strtotime($data['delivery']['date']);
        }
    }
    $design->assign('data', $data);
    $design->ProcessEx('errors.tpl');
    $design->ProcessEx('stats/onlime_details.tpl');
    return;
    
}
private function GenerateExcel($workSheetTitle, $head, $list)
{
    $objPHPExcel = new PHPExcel();

    $objPHPExcel->setActiveSheetIndex(0);

    $sheet = $objPHPExcel->getActiveSheet();

    
	foreach(array(10, 12, 21, 11, 29, 35, 33, 14, 14, 88) as $columnIndex => $width)
		$sheet->getColumnDimensionByColumn($columnIndex+1)->setWidth($width);

    $idx = 0;
    foreach($head as $title => $field)
        $sheet->setCellValueByColumnAndRow($idx++, 2, $title);//, $fHeader);

    foreach($list as $rowIdx => $l)
    {
        $colIdx = 0;
        foreach($head as $title => $field)
        {
            $sheet->setCellValueByColumnAndRow(
                $colIdx++, 
                3+$rowIdx, 
                isset($l[$field]) ?  strip_tags($l[$field]) : ""
            );
        }
    }

    $oeWriter = new PHPExcel_Writer_Excel5($objPHPExcel);

    header("Content-type: application/vnd.ms-excel");
    header('Content-Disposition: attachment; filename="'.$workSheetTitle.'.xls"');
    $oeWriter->save('php://output');
    exit();
}


private function report_plusopers__addressToStr($l)
{
	if(strpos($l["address"], "^") !== false)
	{
		list($street, $home, $bild, $porch, $floor, $flat, $intercom) = explode(" ^ ", $l["address"]." ^  ^  ^  ^  ^  ^  ^  ^ ");
		$a = $street;
		if($home) $a .= ", д.".$home;
		if($bild) $a .= " стр.".$bild;
		if($porch) $a .= ", подъезд ".$porch;
		if($floor) $a .= ", этаж ".$floor;
		if($flat)  $a .= ", кв.".$flat;
		if($intercom) $a .= " (домофон: ".$intercom.")";

		return $a;
	}else{
		return $l["address"];
	}
}

private function report_plusopers__phoneToStr($l)
{
	if(strpos($l["phone"], "^") !== false)
	{
		list($home, $mob, $work) = explode(" ^ ", $l["phone"]." ^  ^  ^ ");
		$p = array();

		if($home) $p[] = "Домашний: ".$home;
		if($mob) $p[] = "Сотовый: ".$mob;
		if($work) $p[] = "Рабочий: ".$work;

		return implode("<br/>", $p);
	}else{
		return $l["phone"];
	}
}

private function report_plusopers__getFields_and_check($client, $a)
{
	$fields = array();
	$error = "";

	$fields["fio"] = "ФИО";

	if($client == "nbn")
	{
		$fields["req"] = "Номер заявки";
		$fields["address"] = "Адресс";
		$fields["phone"] = "Контактный телефон";
	}


	if($client == "onlime" || $client == "onlime2") // need check
	{
		$fields["fio_oper"] = "ФИО оператора";
		$fields["date_deliv"] = "Желаемая дата и время заказа";
	}

	foreach($fields as $k => $v)
	{
		$a[$k] = trim(get_param_raw($k, ""));
		if(empty($a[$k]) && !$error)
		{
			$error = "Поле \"".$v."\" не заполненно";
		}
	}

	if(isset($fields["date_deliv"]) && !$error && !strtotime($a["date_deliv"]))
	{
		$error = "Неверная дата доставки заказа!";
    }


    $a["qty"] = get_param_raw("qty", 1);

	if(!$error && $a["qty"] == 0)
	{
		$error = "Позиции заказа не выбранны";
	}


	if($client == "onlime" || $client == "onlime2")
	{
		$address="";
		$phone = "";

		foreach(array("address_street", "address_house", "address_building", "address_porch",
						"address_floor", "address_flat", "address_interkom") as $f)
		{
			$a[$f] = get_param_raw($f, "");
			$address .= $a[$f]." ^ ";
		}

		foreach(array("phone_home", "phone_mobil", "phone_work") as $f)
		{
			$a[$f] = get_param_raw($f, "");
			$phone .= $a[$f]." ^ ";
		}

		$a["address"] = $address;
		$a["phone"] = $phone;
	}


	$a["deliv_type"] = get_param_raw("deliv_type", "moskow");
	$a["comment"] = trim(get_param_raw("comment", ""));

	return array($fields, $error, $a);
}

private function report_plusopers__Load($client, $billNo)
{
	global $db;
	$a = array();

	if($billNo && $a = $db->GetRow("select * from newbills_add_info where bill_no = '".$db->escape($billNo)."'"))
	{
		$a["req"] = $a["req_no"];
		$a["comment"] = $db->GetValue("select comment from newbills where bill_no = '".$db->escape($billNo)."'");

		if($a["comment1"])
		{
			@list(, $a["date_deliv"]) = explode(": ", $a["comment1"]);
		}

		if($a["comment2"])
		{
			@list(, $a["fio_oper"]) = explode(": ", $a["comment2"]);
		}

		if($client == "nbn")
		{
			$a["qty"] = $db->GetValue("select amount from newbill_lines where bill_no = '".$billNo."' and item_id = '4e8cc21b-d476-11e0-9255-d485644c7711'");
			$a["deliv_type"] = $db->GetValue("select amount from newbill_lines where bill_no = '".$billNo."' and item_id = 'a449a3f7-d918-11e0-bdf8-00155d21fe06'") ? "moskow" : "mkad";
		}else{//onlime
			$a["qty"] = $db->GetValue("select amount from newbill_lines where bill_no = '".$billNo."' and
				item_id in ('ea05defe-4e36-11e1-8572-00155d881200', 'f75a5b2f-382f-11e0-9c3c-d485644c7711','6d2dfd2a-211e-11e3-95df-00155d881200')");
			$a["deliv_type"] = $db->GetValue("select amount from newbill_lines where bill_no = '".$billNo."' and item_id = '81d52242-4d6c-11e1-8572-00155d881200'") ? "moskow" : "mkad";

			$address = explode(" ^ ", $a["address"]);
			foreach(array("address_street", "address_house", "address_building", "address_porch",
									"address_floor", "address_flat", "address_interkom") as $idx => $f)
				$a[$f] = @$address[$idx];

			$phone = explode(" ^ ", $a["phone"]);
			foreach(array("phone_home", "phone_mobil", "phone_work") as $idx => $f)
				$a[$f] = @$phone[$idx];
		}
	}

	return $a;
}

private function report_plusopers__getCount($client, $d1, $d2, $filterPromo)
{
    global $db;


    $deliveryList = $db->AllRecords($sql = "
                select
                    t.id as trouble_id, req_no,fio,phone,t.bill_no,date_creation
                from
                    (
                    select
                        s.trouble_id, max(s.stage_id) as max_stage_id
                    from
                        tt_stages s, tt_doers d ,(select
                                    t.id as trouble_id
                                from
                                    tt_troubles t, tt_stages s, tt_doers d
                                where
                                        date_start between '".$d1." 00:00:00' and '".$d2." 23:59:59'
                                    and t.id = s.trouble_id
                                    and t.client='".$client."'
                                    and d.stage_id = s.stage_id
                                group by
                                    t.id) ts
                    where
                            s.stage_id = d.stage_id
                        and s.trouble_id = ts.trouble_id
                    group by
                        s.trouble_id
                    ) as m, tt_stages s, tt_stages s2, tt_troubles t, newbills_add_info a
                where
                        m.max_stage_id = s.stage_id
                    and s.date_start between '".$d1." 00:00:00' and '".$d2." 23:59:59'
                    and t.id = m.trouble_id
                    and t.bill_no = a.bill_no
                    and s2.stage_id = t.cur_stage_id 
                    and s2.state_id in (2,20)
                ");

    $addWhere = "";
    $addJoin = "";

    if($filterPromo == "promo")
    {
        $addWhere = "and coupon != ''";
        $addJoin = "left join onlime_order oo on (oo.bill_no = b.bill_no)";
    }elseif($filterPromo == "no_promo")
    {
        $addWhere = "and (coupon = '' or coupon is null)";
        $addJoin = "left join onlime_order oo on (oo.bill_no = b.bill_no)";
    }

if($client != "nbn")
{
	$closeList = $db->AllRecords("
		select t.id as trouble_id, req_no, fio, phone, address, t.bill_no, date_creation,

			(select date_start
			from tt_stages s, tt_doers d
			where s.stage_id = d.stage_id and s.trouble_id = t.id
				order by s.stage_id desc limit 1) as date_delivered,

				(select sum(amount) from newbill_lines nl
                        where item_id in ('ea05defe-4e36-11e1-8572-00155d881200', 'f75a5b2f-382f-11e0-9c3c-d485644c7711', '6d2dfd2a-211e-11e3-95df-00155d881200')
                        and nl.bill_no = t.bill_no) as count_3,

				(select sum(amount) from newbill_lines nl
                        where item_id in ('4acdb33c-0319-11e2-9c41-00155d881200', '14723f35-d423-11e3-9fe5-00155d881200')
                        and nl.bill_no = t.bill_no) as count_9,

				(select sum(amount) from newbill_lines nl
                        where item_id in ('72904487-32f6-11e2-9369-00155d881200', '2c6d3955-d423-11e3-9fe5-00155d881200')
                        and nl.bill_no = t.bill_no) as count_11,

				(select sum(amount) from newbill_lines nl
                        where item_id in ('e1a5bf94-0764-11e4-8c79-00155d881200')
                        and nl.bill_no = t.bill_no) as count_12,

				(select sum(amount) from newbill_lines nl
                        where item_id in ('4dff356b-41a0-11e5-93ad-00155d881200')
                        and nl.bill_no = t.bill_no) as count_17,

				(select sum(amount) from newbill_lines nl
                        where item_id in ('55b6f916-b3fb-11e3-9fe5-00155d881200')
                        and nl.bill_no = t.bill_no) as count_18,

				(select sum(amount) from newbill_lines nl
                        where item_id in ('14265ab3-9bca-11e4-8402-00155d881200')
                        and nl.bill_no = t.bill_no) as count_19,

				(select sum(amount) from newbill_lines nl
                        where item_id in ('4454e4d5-a79e-11e4-a330-00155d881200')
                        and nl.bill_no = t.bill_no) as count_22,

				(select sum(amount) from newbill_lines nl
                        where item_id in ('d78e0644-6dbc-11e5-9421-00155d881200', '3a0a1bfe-c8c2-11e5-af87-00155d881200')
                        and nl.bill_no = t.bill_no) as count_28,
                        
				(select sum(amount) from newbill_lines nl
                        where item_id ='8d4c0c88-145b-11e7-9abf-00155d881200'
                        and nl.bill_no = t.bill_no) as count_41,
                                                
				(select sum(amount) from newbill_lines nl
                        where item_id ='bc938b25-2991-11e7-b423-00155d881200'
                        and nl.bill_no = t.bill_no) as count_44,
                        
				(select sum(amount) from newbill_lines nl
                        where item_id ='230bbc32-36fc-11e7-9b8f-00155d881200'
                        and nl.bill_no = t.bill_no) as count_45,

        (select group_concat(serial SEPARATOR ', ') from g_serials s where s.bill_no = t.bill_no) as serials,
        (select concat(coupon) from onlime_order oo where oo.bill_no = t.bill_no) as coupon,

                a.comment1 as date_deliv,
                a.comment2 as fio_oper

		from tt_stages s, tt_troubles t, newbills_add_info a, newbills b
        ".$addJoin."
		where
				s.trouble_id = t.id
			and s.date_start between '".$d1." 00:00:00' and '".$d2." 23:59:59'
			and t.client='".$client."'
			and s.state_id in (2,20)
			and t.bill_no = a.bill_no
            and b.bill_no = t.bill_no
            and is_rollback = 0
            and b.biller_version = " . ClientAccount::VERSION_BILLER_USAGE . "
            ".$addWhere."
            group by s.trouble_id
	        ");

}else{
    $closeList = array();
}


    $r = $db->GetRow($q = "
                SELECT
                    sum(is_rollback) as rollback,
                    sum(if(is_rollback =0 and state_id in (2,20),1,0)) as close,
                    sum(if(is_rollback = 0 and state_id = 21,1,0)) as reject,
                    sum(if(is_rollback = 0 and state_id not in (2, 20, 21) ,1,0)) as work,
                    count(1)                                   as count
                FROM
                    `tt_troubles` t, tt_stages s, newbills b
                    ".$addJoin."
                where
                        t.client = '".$client."'
                    and b.bill_no = t.bill_no
                    and s.stage_id = t.cur_stage_id
                    and b.biller_version = " . ClientAccount::VERSION_BILLER_USAGE . "
                    and date_creation between '".$d1." 00:00:00' and '".$d2." 23:59:59'
                    ".$addWhere)+array("delivery" => count($deliveryList));

    if($client != "nbn")
    {
      //$closeCount = 0;
      //foreach($closeList as $l) $closeCount += $l["count_cards"];
      $r["close"] = count($closeList);//$closeCount;
    }
    return array($r, $closeList, $deliveryList);
}

private function report_plusopers__getList($client, $listType, $d1, $d2, $deliveryList, $closeList, $filterPromo)
{
    global $design, $db;


    $addWhere = "";
    $addJoin = "";

    if($filterPromo == "promo")
    {
        $addWhere = "and coupon != ''";
        $addJoin = "left join onlime_order oo on (oo.bill_no = b.bill_no)";
    }elseif($filterPromo == "no_promo")
    {
        $addWhere = "and (coupon = '' or coupon is null)";
        $addJoin = "left join onlime_order oo on (oo.bill_no = b.bill_no)";
    }

        $sTypes = array(
                "work"   => array("sql" => "is_rollback =0 and state_id not in (2,20,21)",
                                  "title" => ($client == "nbn" ? "в работе" : "В Обработке")),
                "close"  => array("sql" => "is_rollback =0 and state_id in (2,20)",
                                  "title" => ($client == "nbn" ? "закрытые" : "Доставлен")),
                "reject" => array("sql" => "is_rollback =0 and state_id = 21",
                                  "title" => ($client == "nbn" ? "в отказе" : "Отказ")),
                "delivery" => array(                            "title" => "доставка")
                );

        if($client != "nbn")  // onlime
        {
            $sTypes["rollback"] = array("sql" => "is_rollback =1", "title" => "Возврат");
        }

        if(isset($sTypes[$listType]))
        {
            $design->assign("listType", $listType);
        	if(($client == "onlime" || $client == "onlime2") && $listType == "close")
        	{
        		$list = $closeList;
        	}elseif($listType == "delivery")
            {
                $list = $deliveryList;
            }else{
                $list = $db->AllRecords($q = "
                        SELECT
                            t.id as trouble_id, t.bill_no, t.problem,
                            req_no, fio, phone, address, date_creation
                            ".($client != "nbn" ?
                    ", 
                    
                    
				(select sum(amount) from newbill_lines nl
                        where item_id in ('ea05defe-4e36-11e1-8572-00155d881200', 'f75a5b2f-382f-11e0-9c3c-d485644c7711', '6d2dfd2a-211e-11e3-95df-00155d881200')
                        and nl.bill_no = t.bill_no) as count_3,

				(select sum(amount) from newbill_lines nl
                        where item_id in ('4acdb33c-0319-11e2-9c41-00155d881200', '14723f35-d423-11e3-9fe5-00155d881200')
                        and nl.bill_no = t.bill_no) as count_9,

				(select sum(amount) from newbill_lines nl
                        where item_id in ('72904487-32f6-11e2-9369-00155d881200', '2c6d3955-d423-11e3-9fe5-00155d881200')
                        and nl.bill_no = t.bill_no) as count_11,

				(select sum(amount) from newbill_lines nl
                        where item_id in ('e1a5bf94-0764-11e4-8c79-00155d881200')
                        and nl.bill_no = t.bill_no) as count_12,

				(select sum(amount) from newbill_lines nl
                        where item_id in ('4dff356b-41a0-11e5-93ad-00155d881200')
                        and nl.bill_no = t.bill_no) as count_17,

				(select sum(amount) from newbill_lines nl
                        where item_id in ('55b6f916-b3fb-11e3-9fe5-00155d881200')
                        and nl.bill_no = t.bill_no) as count_18,

				(select sum(amount) from newbill_lines nl
                        where item_id in ('14265ab3-9bca-11e4-8402-00155d881200')
                        and nl.bill_no = t.bill_no) as count_19,

				(select sum(amount) from newbill_lines nl
                        where item_id in ('4454e4d5-a79e-11e4-a330-00155d881200')
                        and nl.bill_no = t.bill_no) as count_22,

				(select sum(amount) from newbill_lines nl
                        where item_id in ('d78e0644-6dbc-11e5-9421-00155d881200', '3a0a1bfe-c8c2-11e5-af87-00155d881200')
                        and nl.bill_no = t.bill_no) as count_28,
                        
				(select sum(amount) from newbill_lines nl
                        where item_id ='8d4c0c88-145b-11e7-9abf-00155d881200'
                        and nl.bill_no = t.bill_no) as count_41,
                        
				(select sum(amount) from newbill_lines nl
                        where item_id ='bc938b25-2991-11e7-b423-00155d881200'
                        and nl.bill_no = t.bill_no) as count_44,
                        
				(select sum(amount) from newbill_lines nl
                        where item_id ='230bbc32-36fc-11e7-9b8f-00155d881200'
                        and nl.bill_no = t.bill_no) as count_45,

        (select group_concat(serial separator ', ') from g_serials s where s.bill_no = t.bill_no) as serials,
        (select concat(coupon) from onlime_order oo where oo.bill_no = t.bill_no) as coupon,

                            (select date_start from tt_stages s, tt_doers d where s.stage_id = d.stage_id and s.trouble_id = t.id order by s.stage_id desc limit 1) as date_delivered,
                            i.comment1 as date_deliv,
                            i.comment2 as fio_oper
                            " : "")."
                        FROM
                            `tt_troubles` t, tt_stages s, newbills_add_info i, newbills b
                            ".$addJoin."
                        WHERE
                                t.client = '".$client."'
                            AND s.stage_id = t.cur_stage_id
                            AND date_creation between '".$d1." 00:00:00' AND '".$d2." 23:59:59'
                            AND i.bill_no = t.bill_no
                            AND t.bill_no = b.bill_no
                            AND b.biller_version = " . ClientAccount::VERSION_BILLER_USAGE . "
                            AND ".$sTypes[$listType]["sql"]."
                            ".$addWhere."
                        ORDER BY
                            date_creation");

            }

            foreach($list as &$l)
            {
            	if($client == "onlime" || $client == "onlime2")
            	{
	                if(isset($l["date_deliv"]) && $l["date_deliv"])
	                {
	                    @list(, $l["date_deliv"]) = explode(": ", $l["date_deliv"]);
	                }

	                if(isset($l["fio_oper"]) && $l["fio_oper"])
	                {
	                	@list(, $l["fio_oper"]) = explode(": ", $l["fio_oper"]);
	                }

	                $l["address"] = $this->report_plusopers__addressTOStr($l);
	                $l["phone"] = $this->report_plusopers__phoneToStr($l);
            	}


				$l['stages'] = $db->AllRecords('
					SELECT
						S.*,
						IF(S.date_edit=0,NULL,date_edit) as date_edit,
						tts.name as state_name
					FROM
						tt_stages as S
					INNER JOIN
						tt_states tts
					ON
						tts.id = state_id
					WHERE
						trouble_id='.$l['trouble_id'].'
					ORDER BY
						stage_id ASC
				');
                foreach($l["stages"] as &$s)
                {
				$query = "
					SELECT
						`td`.`doer_id`,
						`cr`.`name`,
						`cr`.`depart`
					FROM
						`tt_doers` `td`
					LEFT JOIN
						`courier` `cr`
					ON
						`cr`.`id` = `td`.`doer_id`
					WHERE
						`td`.`stage_id` = ".$s['stage_id']."
					ORDER BY
						`cr`.`depart`,
						`cr`.`name`
				";
				$s['doers']=$db->AllRecords($query,null,MYSQLI_ASSOC);
			}
            }
            $design->assign("listName", $sTypes[$listType]["title"]);
        }
        return $list;
}


    public function stats_report_phone_sales()
    {
        global $db, $design;
        $from_y = get_param_raw("from_y", date('Y'));
        $from_m = get_param_raw("from_m", date('m'));
        $to_y = get_param_raw("to_y", date('Y'));
        $to_m = get_param_raw("to_m", date('m'));

        // для последующего использования в sql-выражениях:
        $from_date = $from_y . "-" . $from_m . "-01";
        $to_date = date("Y-m-t", mktime(0, 0, 0, $to_m, 1, $to_y));

        $month_list = array('Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь');
        $selector_mm = array();

        for ($i = 1; $i <= 12; $i++)
            $selector_mm[$i] = $month_list[$i - 1];

        $selector_yy = array(date('Y') + 1, date('Y'), date('Y') - 1, date('Y') - 2);

        // для показа в интерфейсе:l
        $from = "01." . $from_m . "." . $from_y;
        $to = date("t.m.Y", mktime(0, 0, 0, $to_m, 1, $to_y));

        $curr_phones = $db->AllRecords('
            SELECT
              u.region,
              COUNT(*) as count_num,
              SUM(no_of_lines) as count_lines
            FROM
              usage_voip u
            WHERE
              CAST(NOW() as DATE)  BETWEEN u.actual_from AND u.actual_to  AND
              E164 NOT LIKE "7800%" AND
              LENGTH(E164) > 4
            GROUP BY
              u.region'

        , 'region');

        $curr_vpbx = $db->AllRecordsAssoc(
                'SELECT
            c.region, COUNT(*) as count_vpbx
            FROM
                usage_virtpbx as u
            LEFT JOIN
                clients as c ON c.client = u.client
            WHERE
                CAST(NOW() as DATE)  BETWEEN u.actual_from AND u.actual_to
            GROUP BY
                c.region
            ', 'region', 'count_vpbx'
        );

        $curr_8800 = $db->AllRecords('
            select
              u.region,
              count(*) as count_num,
              sum(no_of_lines) as count_lines
            from
              usage_voip u
            where
              u.E164 LIKE "7800%" AND
              CAST(NOW() as DATE)  BETWEEN u.actual_from AND u.actual_to
            group by
          u.region', 'region');

        $curr_no_nums = $db->AllRecords('
            select
              u.region,
              count(*) as count_num,
              sum(no_of_lines) as count_lines
            from
              usage_voip u
            where
              LENGTH(u.E164) < 5 AND
              CAST(NOW() as DATE)  BETWEEN u.actual_from AND u.actual_to
            group by
          u.region', 'region');

        $region_clients_count = $db->AllRecordsAssoc("
            SELECT
                COUNT(id) as clients,
                region
            FROM
                clients
            WHERE
                status IN ('testing', 'conecting', 'work') AND
                region > 0
            GROUP BY
                region
            ORDER BY
                region DESC
            ", 'region', 'clients');


        $regions = $db->AllRecords("select id, short_name, name from regions order by id desc");
        $reports = array();

        $tmp_m = $to_m; // for itterations
        $tmp_y = $to_y;

        $this->_getPhoneSellByPeriod($reports, $from_y, $from_m, $to_y, $to_m, $month_list, true);

        while (mktime(0, 0, 0, $tmp_m, 1, $tmp_y) >= mktime(0, 0, 0, $from_m, 1, $from_y)) // process all monthes in interval
        {
            $tmp_date_from = $tmp_y . '-' . $tmp_m . '-01';
            $tmp_date_to = date("Y-m-t", mktime(0, 0, 0, $tmp_m, 1, $tmp_y));
            $client_ids = array();

            $region_sums = $db->AllRecordsAssoc($q = "
                SELECT
                    b.region,
                    ROUND(SUM(a.sum)) as sum
                FROM
                    newbills as a
                LEFT JOIN
                    clients as b ON a.client_id = b.id
                LEFT JOIN
                    client_contract as c ON c.id = b.contract_id
                LEFT JOIN
                    client_contragent as d ON c.contragent_id = d.id
                WHERE
                    a.bill_date >= CAST('" . $tmp_date_from . "' AS DATE) AND
                    a.bill_date <= CAST('" . $tmp_date_to . "' AS DATE) AND
                    b.region > 0 AND
                    b.status IN ('testing', 'conecting', 'work') AND
                    d.legal_type IN ('legal', 'ip') AND
                    sum > 0
                    AND a.biller_version = " . ClientAccount::VERSION_BILLER_USAGE . "
                GROUP BY
                    b.region
                ORDER BY
                    b.region DESC
              ", 'region', 'sum');

            $region_sums['all'] = 0;

            foreach ($region_sums as $k => $v) {
                $region_sums['all'] += $v;
            }

            $res = $db->AllRecords("
                select
                    u.region,
                    u.E164 as phone,
                    u.no_of_lines,
                    c.id as client_id,
                    ifnull(c.created >= date_add('" . $tmp_date_from . "',interval - 1 month), 0) as is_new,
                    s.name as sale_channel,
                    s.id as sale_channel_id,
                    s.courier_id as courier_id
                from usage_voip u
                left join clients c on c.client=u.client
                left join sale_channels_old s on s.id=c.sale_channel
                where
                    u.actual_from>=CAST('" . $tmp_date_from . "' AS DATE)
                    and u.actual_from<=CAST('" . $tmp_date_to . "' AS DATE)
                group by
                    u.region, u.E164, c.id, c.created, s.name  ");

            $res_vpbx = $db->AllRecords("
                select
                    c.region,
                    u.id,
                    c.id as client_id,
                    ifnull(c.created >= date_add('" . $tmp_date_from . "',interval - 1 month), 0) as is_new,
                    s.name as sale_channel,
                    s.id as sale_channel_id,
                    s.courier_id as courier_id
                from usage_virtpbx u
                left join clients c on c.client=u.client
                left join sale_channels_old s on s.id=c.sale_channel
                where
                    u.actual_from>=CAST('" . $tmp_date_from . "' AS DATE)
                    and u.actual_from<=CAST('" . $tmp_date_to . "' AS DATE)
                group by
                    c.region, c.id, c.created, s.name  ");

            $sale_nums = array('all' => array('new' => 0, 'old' => 0, 'all' => 0));
            $sale_8800 = array('all' => array('new' => 0, 'old' => 0, 'all' => 0));
            $sale_vpbx = array('all' => array('new' => 0, 'old' => 0, 'all' => 0));
            $sale_nonums = array('all' => array('new' => 0, 'old' => 0, 'all' => 0));
            $sale_lines = array('all' => array('new' => 0, 'old' => 0, 'all' => 0));
            $sale_clients = array('all' => array('new' => 0, 'old' => 0, 'all' => 0));
            $sale_channels = array('all' => array('vpbx' => array('new' => 0, 'old' => 0, 'all' => 0), 'nums' => array('new' => 0, 'old' => 0, 'all' => 0), 'lines' => array('new' => 0, 'old' => 0, 'all' => 0), 'visits' => 0), "managers" => array());
            $clients = array();
            $clients_vpbx = array();
            $vpbx_clients = array('all' => array('new' => 0, 'old' => 0, 'all' => 0));

            foreach ($res as $r) {
                $client_ids[$r['client_id']] = 0;
                if (strlen($r['phone']) > 4) //номера
                {
                    if (strpos($r['phone'], '7800') === 0) {
                        if (!isset($sale_8800[$r['region']])) {
                            $sale_8800[$r['region']] = array('new' => 0, 'old' => 0, 'all' => 0);
                        }
                        if ($r['is_new'] > 0) {
                            $sale_8800[$r['region']]['new'] += 1;
                            $sale_8800['all']['new'] += 1;
                        } else {
                            $sale_8800[$r['region']]['old'] += 1;
                            $sale_8800['all']['old'] += 1;
                        }
                        $sale_8800[$r['region']]['all'] += 1;
                        $sale_8800['all']['all'] += 1;
                    } else {
                        if (!isset($sale_nums[$r['region']])) {
                            $sale_nums[$r['region']] = array('new' => 0, 'old' => 0, 'all' => 0);
                        }
                        if ($r['is_new'] > 0) {
                            $sale_nums[$r['region']]['new'] += 1;
                            $sale_nums['all']['new'] += 1;
                        } else {
                            $sale_nums[$r['region']]['old'] += 1;
                            $sale_nums['all']['old'] += 1;
                        }
                        $sale_nums[$r['region']]['all'] += 1;
                        $sale_nums['all']['all'] += 1;
                    }

                } else { //линия без номера

                    if (!isset($sale_nonums[$r['region']]))
                        $sale_nonums[$r['region']] = array('new' => 0, 'old' => 0, 'all' => 0);

                    if ($r['is_new'] > 0) {
                        $sale_nonums[$r['region']]['new'] += 1;
                        $sale_nonums['all']['new'] += 1;
                    } else {
                        $sale_nonums[$r['region']]['old'] += 1;
                        $sale_nonums['all']['old'] += 1;
                    }
                    $sale_nonums[$r['region']]['all'] += 1;
                    $sale_nonums['all']['all'] += 1;
                }

                //линии
                if (!isset($sale_lines[$r['region']]))
                    $sale_lines[$r['region']] = array('new' => 0, 'old' => 0, 'all' => 0);

                $sale_lines[$r['region']][$r['is_new'] ? 'new' : 'old'] += $r["no_of_lines"];
                $sale_lines['all'][$r['is_new'] ? 'new' : 'old'] += $r["no_of_lines"];

                $sale_lines[$r['region']]['all'] += $r["no_of_lines"];
                $sale_lines['all']['all'] += $r["no_of_lines"];


                if (!isset($clients[$r['client_id']])) {
                    $clients[$r['client_id']] = $r['client_id'];

                    if (!isset($sale_clients[$r['region']]))
                        $sale_clients[$r['region']] = array('new' => 0, 'old' => 0, 'all' => 0);

                    if ($r['is_new'] > 0) {
                        $sale_clients[$r['region']]['new'] += 1;
                        $sale_clients['all']['new'] += 1;
                    } else {
                        $sale_clients[$r['region']]['old'] += 1;
                        $sale_clients['all']['old'] += 1;
                    }
                    $sale_clients[$r['region']]['all'] += 1;
                    $sale_clients['all']['all'] += 1;
                }

                if (!isset($sale_channels['managers'][$r['sale_channel']]))
                    $sale_channels['managers'][$r['sale_channel']] = array('sale_channel_id' => $r['sale_channel_id'], 'vpbx' => array('new' => 0, 'old' => 0, 'all' => 0), 'nums' => array('new' => 0, 'old' => 0, 'all' => 0), 'lines' => array('new' => 0, 'old' => 0, 'all' => 0), 'clients' => array(), 'visits' => 0, 'courier_id' => $r['courier_id']);
                $sale_channels['managers'][$r['sale_channel']]['nums']['all'] += 1;
                $sale_channels['managers'][$r['sale_channel']]['lines']['all'] += $r['no_of_lines'];
                $sale_channels['all']['lines']['all'] += $r['no_of_lines'];
                $sale_channels['managers'][$r['sale_channel']]['clients'][] = $r['client_id'];
                $sale_channels['all']['nums']['all'] += 1;
                if ($r['is_new']) {
                    $sale_channels['managers'][$r['sale_channel']]['nums']['new'] += 1;
                    $sale_channels['managers'][$r['sale_channel']]['lines']['new'] += $r['no_of_lines'];
                    $sale_channels['all']['nums']['new'] += 1;
                    $sale_channels['all']['lines']['new'] += $r['no_of_lines'];
                } else {
                    $sale_channels['managers'][$r['sale_channel']]['nums']['old'] += 1;
                    $sale_channels['managers'][$r['sale_channel']]['lines']['old'] += $r['no_of_lines'];
                    $sale_channels['all']['nums']['old'] += 1;
                    $sale_channels['all']['lines']['old'] += $r['no_of_lines'];
                }
            }
            foreach ($res_vpbx as $r) {
                $client_ids[$r['client_id']] = 0;

                if (!isset($sale_vpbx[$r['region']]))
                    $sale_vpbx[$r['region']] = array('new' => 0, 'old' => 0, 'all' => 0);

                if ($r['is_new'] > 0) {
                    $sale_vpbx[$r['region']]['new'] += 1;
                    $sale_vpbx['all']['new'] += 1;
                } else {
                    $sale_vpbx[$r['region']]['old'] += 1;
                    $sale_vpbx['all']['old'] += 1;
                }
                $sale_vpbx[$r['region']]['all'] += 1;
                $sale_vpbx['all']['all'] += 1;

                if (!isset($clients_vpbx[$r['client_id']])) {
                    $clients_vpbx[$r['client_id']] = $r['client_id'];

                    if (!isset($vpbx_clients[$r['region']]))
                        $vpbx_clients[$r['region']] = array('new' => 0, 'old' => 0, 'all' => 0);

                    if ($r['is_new'] > 0) {
                        $vpbx_clients[$r['region']]['new'] += 1;
                        $vpbx_clients['all']['new'] += 1;
                    } else {
                        $vpbx_clients[$r['region']]['old'] += 1;
                        $vpbx_clients['all']['old'] += 1;
                    }
                    $vpbx_clients[$r['region']]['all'] += 1;
                    $vpbx_clients['all']['all'] += 1;
                }


                if (!isset($sale_channels['managers'][$r['sale_channel']]))
                    $sale_channels['managers'][$r['sale_channel']] = array('sale_channel_id' => $r['sale_channel_id'], 'vpbx' => array('new' => 0, 'old' => 0, 'all' => 0), 'nums' => array('new' => 0, 'old' => 0, 'all' => 0), 'lines' => array('new' => 0, 'old' => 0, 'all' => 0), 'clients' => array(), 'visits' => 0, 'courier_id' => $r['courier_id']);
                $sale_channels['managers'][$r['sale_channel']]['vpbx']['all'] += 1;
                $sale_channels['managers'][$r['sale_channel']]['clients'][] = $r['client_id'];
                $sale_channels['all']['vpbx']['all'] += 1;
                if ($r['is_new']) {
                    $sale_channels['managers'][$r['sale_channel']]['vpbx']['new'] += 1;
                    $sale_channels['all']['vpbx']['new'] += 1;
                } else {
                    $sale_channels['managers'][$r['sale_channel']]['vpbx']['old'] += 1;
                    $sale_channels['all']['vpbx']['old'] += 1;
                }
            }
            /*
      //Выезды
      $res = $db->AllRecords("
              select
                count(*) as cnt,
                c.id as client_id
              from
                tt_stages ts
              left join tt_troubles tt on tt.id=ts.trouble_id
              left join clients c on c.client=tt.client
              where
                ts.state_id=4 and
                ts.date_edit>=date_add('$date',interval -$mm month) and
                ts.date_edit<date_add('$date',interval -$mm+1 month) and
                c.id in (" . implode(',', array_keys($client_ids)) . ")
              group by c.id
              ");
      foreach ($res as $r) {
        foreach ($sale_channels['managers'] as $manager=>&$val) {
            if (in_array($r['client_id'], $val['clients'])) {
                $val['visits'] += $r['cnt'];
                $sale_channels['all']['visits'] += $r['cnt'];
            }
        }
      } */
            //Выезды
            foreach ($sale_channels["managers"] as $manager => &$d) {
                if ($d['courier_id'] > 0) {
                    $res = $db->GetValue($qq = 'SELECT
                            COUNT(*)
                        FROM
                            tt_stages as st
                        LEFT JOIN
                            tt_troubles as tt ON tt.id = st.trouble_id
                        LEFT JOIN
                            tt_doers as td ON td.stage_id = st.stage_id
                        WHERE
                            tt.id IN (SELECT
                                    DISTINCT(t.id)
                                FROM
                                    tt_doers as d
                                LEFT JOIN
                                    tt_stages as s ON d.stage_id = s.stage_id
                                LEFT JOIN
                                    tt_troubles as t ON s.trouble_id = t.id
                                WHERE
                                        d.doer_id = ' . $d['courier_id'] . '
                                    AND
                                        s.date_start >= CAST("' . $tmp_date_from . '" AS DATE)
                                    AND
                                        s.date_start <= CAST("' . $tmp_date_to . '" AS DATE)
                                )
                            AND
                                st.state_id = 4
                            AND
                                st.date_start >= CAST("' . $tmp_date_from . '" AS DATE)
                            AND
                                st.date_start <= CAST("' . $tmp_date_to . '" AS DATE)
                            AND
                                td.doer_id = ' . $d['courier_id']);


                    $sale_channels['all']['visits'] += $res;
                    $d['visits'] = $res;
                }
            }
            foreach ($sale_channels["managers"] as $mamager => &$d) {
                if ($sale_channels["all"]["nums"]['all'] > 0) {
                    $d["nums_perc"]['new'] = round($d["nums"]['new'] / $sale_channels["all"]["nums"]['all'] * 100);
                    $d["nums_perc"]['old'] = round($d["nums"]['old'] / $sale_channels["all"]["nums"]['all'] * 100);
                } else {
                    $d["nums_perc"]['new'] = 0;
                    $d["nums_perc"]['old'] = 0;
                }
                if ($sale_channels["all"]["lines"]['all'] > 0) {
                    $d["lines_perc"]['new'] = round($d["lines"]['new'] / $sale_channels["all"]["lines"]['all'] * 100);
                    $d["lines_perc"]['old'] = round($d["lines"]['old'] / $sale_channels["all"]["lines"]['all'] * 100);
                } else {
                    $d["lines_perc"]['new'] = 0;
                    $d["lines_perc"]['old'] = 0;
                }
                if ($sale_channels["all"]["vpbx"]['all'] > 0) {
                    $d["vpbx_perc"]['new'] = round($d["vpbx"]['new'] / $sale_channels["all"]["vpbx"]['all'] * 100);
                    $d["vpbx_perc"]['old'] = round($d["vpbx"]['old'] / $sale_channels["all"]["vpbx"]['all'] * 100);
                } else {
                    $d["vpbx_perc"]['new'] = 0;
                    $d["vpbx_perc"]['old'] = 0;
                }
                $d["visits_perc"] = ($sale_channels["all"]["visits"] > 0) ? round($d["visits"] / $sale_channels["all"]["visits"] * 100) : 0;
            }


            $del_nums = array('all' => 0);
            $del_8800 = array('all' => 0);
            $del_nonums = array('all' => 0);
            $del_lines = array('all' => 0);
            $del_vpbx = array('all' => 0);

            $res = $db->AllRecords("
                select
                    u.region,
                    u.E164 as phone,
                    u.no_of_lines,
                    c.id as client_id
                from usage_voip u
                left join clients c on c.client=u.client
                where
                    u.actual_to>=CAST('" . $tmp_date_from . "' AS DATE)
                and u.actual_to<=CAST('" . $tmp_date_to . "' AS DATE)
                group by u.region, u.E164, c.id  ");

            $res_vpbx = $db->AllRecords("
                select
                    c.region,
                    u.id,
                    c.id as client_id
                from usage_virtpbx u
                left join clients c on c.client=u.client
                where
                    u.actual_to>=CAST('" . $tmp_date_from . "' AS DATE)
                and u.actual_to<=CAST('" . $tmp_date_to . "' AS DATE)
                group by c.region, u.id, c.id  ");

            foreach ($res as $r) {
                if (strlen($r['phone']) > 4) {
                    if (strpos($r['phone'], '7800') === 0) {
                        if (!isset($del_8800[$r['region']]))
                            $del_8800[$r['region']] = 0;

                        $del_8800[$r['region']] += 1;
                        $del_8800['all'] += 1;
                    } else {
                        if (!isset($del_nums[$r['region']]))
                            $del_nums[$r['region']] = 0;

                        $del_nums[$r['region']] += 1;
                        $del_nums['all'] += 1;
                    }
                } else {
                    if (!isset($del_nonums[$r['region']]))
                        $del_nonums[$r['region']] = 0;

                    $del_nonums[$r['region']] += 1;
                    $del_nonums['all'] += 1;
                }

                if (!isset($del_lines[$r['region']]))
                    $del_lines[$r['region']] = 0;

                $del_lines[$r['region']] += $r['no_of_lines'];
                $del_lines['all'] += $r['no_of_lines'];
            }

            foreach ($res_vpbx as $r) {
                if (!isset($del_vpbx[$r['region']]))
                    $del_vpbx[$r['region']] = 0;

                $del_vpbx[$r['region']] += 1;
                $del_vpbx['all'] += 1;
            }


            if ($tmp_m < 1) $tmp_m = 12;

            $reports[] = array(
                'date' => $month_list[$tmp_m - 1] . " " . $tmp_y . " ",
                'month' => 0 + $tmp_m,
                'year' => $tmp_y,
                'region_sums' => $region_sums,
                'sale_nums' => $sale_nums,
                'sale_8800' => $sale_8800,
                'sale_nonums' => $sale_nonums,
                'sale_lines' => $sale_lines,
                'sale_clients' => $sale_clients,
                'sale_channels' => $sale_channels,
                'del_nums' => $del_nums,
                'del_8800' => $del_8800,
                'del_nonums' => $del_nonums,
                'del_lines' => $del_lines,
                'del_vpbx' => $del_vpbx,
                'sale_vpbx' => $sale_vpbx,
                'vpbx_clients' => $vpbx_clients
            );
            if (--$tmp_m < 1) {
                $tmp_m = 12;
                $tmp_y--;
            }

        }


        $design->assign("RManager",
            (new BaseView)->renderFile('@app/views/stats/report/_phone-sales-by-manager.php', [
                'managers' => \app\classes\stats\PhoneSales::reportByManager(
                    $from_y . '-' . $from_m . '-00',
                    $to_y . '-' . $to_m . '-' . date('t', mktime(0,0,0, $to_y, 1, $to_m)) // Делаем дату финальным днем конечного месяца
                )
            ]));

        $design->assign("RPartner",
            (new BaseView)->renderFile('@app/views/stats/report/_phone-sales-by-partner.php', [
                'partners' => \app\classes\stats\PhoneSales::reportByPartner(
                    $from_y . '-' . $from_m . '-00',
                    $to_y . '-' . $to_m . '-' . date('t', mktime(0,0,0, $to_y, 1, $to_m)) // Делаем дату финальным днем конечного месяца
                )
            ]));

        $design->assign("from_y", $from_y);
        $design->assign("from_m", $from_m);
        $design->assign("to_y", $to_y);
        $design->assign("to_m", $to_m);
        $design->assign("select_year", $selector_yy);
        $design->assign("select_month", $selector_mm);
        $design->assign("regions", $regions);
        $design->assign("region_clients_count", $region_clients_count);
        $design->assign('reports', $reports);
        $design->assign('curr_phones', $curr_phones);
        $design->assign('curr_vpbx', $curr_vpbx);
        $design->assign('curr_8800', $curr_8800);
        $design->assign('curr_no_nums', $curr_no_nums);
        $design->AddMain('stats/report_phone_sales.tpl');
    }

    function _getPhoneSellByPeriod( & $reports, $fromY, $fromM, $toY, $toM, $month_list, $sum = false )
    {
        global $db;

        $tmp_date_from = $fromY . '-' . $fromM . '-01';
        $tmp_date_to = date("Y-m-t", mktime(0,0,0,$toM,1,$toY));

        $client_ids = array();

        $region_sums = $db->AllRecordsAssoc($q = "
            SELECT
                b.region,
                ROUND(SUM(a.sum)) as sum
            FROM
                newbills as a
            LEFT JOIN
                clients as b ON a.client_id = b.id
            LEFT JOIN
                client_contract as c ON c.id = b.contract_id
            LEFT JOIN
                client_contragent as d ON c.contragent_id = d.id
            WHERE
                a.bill_date >= CAST('" . $tmp_date_from . "' AS DATE) AND
                a.bill_date <= CAST('" . $tmp_date_to . "' AS DATE) AND
                b.region > 0 AND
                b.status IN ('testing', 'conecting', 'work') AND
                d.legal_type IN ('legal', 'ip') AND
                sum > 0
                AND biller_version = " . ClientAccount::VERSION_BILLER_USAGE . "
            GROUP BY
                b.region
            ORDER BY
                b.region DESC
          ", 'region', 'sum');

        $region_sums['all'] = 0;

        foreach ($region_sums as $k => $v) {
            $region_sums['all'] += $v;
        }

        $res = $db->AllRecords("
            select
                u.region,
                u.E164 as phone,
                u.no_of_lines,
                c.id as client_id,
                ifnull(c.created >= date_add('" . $tmp_date_from . "',interval - 1 month), 0) as is_new,
                s.name as sale_channel,
                s.id as sale_channel_id,
                s.courier_id as courier_id
            from usage_voip u
            left join clients c on c.client=u.client
            left join sale_channels_old s on s.id=c.sale_channel
            where
                u.actual_from>=CAST('" . $tmp_date_from . "' AS DATE)
                and u.actual_from<=CAST('" . $tmp_date_to . "' AS DATE)
            group by
                u.region, u.E164, c.id, c.created, s.name  ");

        $res_vpbx = $db->AllRecords("
            select
                c.region,
                u.id,
                c.id as client_id,
                ifnull(c.created >= date_add('" . $tmp_date_from . "',interval - 1 month), 0) as is_new,
                s.name as sale_channel,
                s.id as sale_channel_id,
                s.courier_id as courier_id
            from usage_virtpbx u
            left join clients c on c.client=u.client
            left join sale_channels_old s on s.id=c.sale_channel
            where
                u.actual_from>=CAST('" . $tmp_date_from . "' AS DATE)
                and u.actual_from<=CAST('" . $tmp_date_to . "' AS DATE)
            group by
                c.region, c.id, c.created, s.name  ");

        $sale_nums = array('all' => array('new' => 0, 'old' => 0, 'all' => 0));
        $sale_8800 = array('all' => array('new' => 0, 'old' => 0, 'all' => 0));
        $sale_vpbx = array('all' => array('new' => 0, 'old' => 0, 'all' => 0));
        $sale_nonums = array('all' => array('new' => 0, 'old' => 0, 'all' => 0));
        $sale_lines = array('all' => array('new' => 0, 'old' => 0, 'all' => 0));
        $sale_clients = array('all' => array('new' => 0, 'old' => 0, 'all' => 0));
        $sale_channels = array('all' => array('vpbx' => array('new' => 0, 'old' => 0, 'all' => 0), 'nums' => array('new' => 0, 'old' => 0, 'all' => 0), 'lines' => array('new' => 0, 'old' => 0, 'all' => 0), 'visits' => 0), "managers" => array());
        $clients = array();
        $clients_vpbx = array();
        $vpbx_clients = array('all' => array('new' => 0, 'old' => 0, 'all' => 0));

        foreach ($res as $r) {
            $client_ids[$r['client_id']] = 0;
            if (strlen($r['phone']) > 4) //номера
            {
                if (strpos($r['phone'], '7800') === 0) {
                    if (!isset($sale_8800[$r['region']])) {
                        $sale_8800[$r['region']] = array('new' => 0, 'old' => 0, 'all' => 0);
                    }
                    if ($r['is_new'] > 0) {
                        $sale_8800[$r['region']]['new'] += 1;
                        $sale_8800['all']['new'] += 1;
                    } else {
                        $sale_8800[$r['region']]['old'] += 1;
                        $sale_8800['all']['old'] += 1;
                    }
                    $sale_8800[$r['region']]['all'] += 1;
                    $sale_8800['all']['all'] += 1;
                } else {
                    if (!isset($sale_nums[$r['region']])) {
                        $sale_nums[$r['region']] = array('new' => 0, 'old' => 0, 'all' => 0);
                    }
                    if ($r['is_new'] > 0) {
                        $sale_nums[$r['region']]['new'] += 1;
                        $sale_nums['all']['new'] += 1;
                    } else {
                        $sale_nums[$r['region']]['old'] += 1;
                        $sale_nums['all']['old'] += 1;
                    }
                    $sale_nums[$r['region']]['all'] += 1;
                    $sale_nums['all']['all'] += 1;
                }

            } else { //линия без номера

                if (!isset($sale_nonums[$r['region']]))
                    $sale_nonums[$r['region']] = array('new' => 0, 'old' => 0, 'all' => 0);

                if ($r['is_new'] > 0) {
                    $sale_nonums[$r['region']]['new'] += 1;
                    $sale_nonums['all']['new'] += 1;
                } else {
                    $sale_nonums[$r['region']]['old'] += 1;
                    $sale_nonums['all']['old'] += 1;
                }
                $sale_nonums[$r['region']]['all'] += 1;
                $sale_nonums['all']['all'] += 1;
            }

            //линии
            if (!isset($sale_lines[$r['region']]))
                $sale_lines[$r['region']] = array('new' => 0, 'old' => 0, 'all' => 0);

            $sale_lines[$r['region']][$r['is_new'] ? 'new' : 'old'] += $r["no_of_lines"];
            $sale_lines['all'][$r['is_new'] ? 'new' : 'old'] += $r["no_of_lines"];

            $sale_lines[$r['region']]['all'] += $r["no_of_lines"];
            $sale_lines['all']['all'] += $r["no_of_lines"];


            if (!isset($clients[$r['client_id']])) {
                $clients[$r['client_id']] = $r['client_id'];

                if (!isset($sale_clients[$r['region']]))
                    $sale_clients[$r['region']] = array('new' => 0, 'old' => 0, 'all' => 0);

                if ($r['is_new'] > 0) {
                    $sale_clients[$r['region']]['new'] += 1;
                    $sale_clients['all']['new'] += 1;
                } else {
                    $sale_clients[$r['region']]['old'] += 1;
                    $sale_clients['all']['old'] += 1;
                }
                $sale_clients[$r['region']]['all'] += 1;
                $sale_clients['all']['all'] += 1;
            }

            if (!isset($sale_channels['managers'][$r['sale_channel']]))
                $sale_channels['managers'][$r['sale_channel']] = array('sale_channel_id' => $r['sale_channel_id'], 'vpbx' => array('new' => 0, 'old' => 0, 'all' => 0), 'nums' => array('new' => 0, 'old' => 0, 'all' => 0), 'lines' => array('new' => 0, 'old' => 0, 'all' => 0), 'clients' => array(), 'visits' => 0, 'courier_id' => $r['courier_id']);
            $sale_channels['managers'][$r['sale_channel']]['nums']['all'] += 1;
            $sale_channels['managers'][$r['sale_channel']]['lines']['all'] += $r['no_of_lines'];
            $sale_channels['all']['lines']['all'] += $r['no_of_lines'];
            $sale_channels['managers'][$r['sale_channel']]['clients'][] = $r['client_id'];
            $sale_channels['all']['nums']['all'] += 1;
            if ($r['is_new']) {
                $sale_channels['managers'][$r['sale_channel']]['nums']['new'] += 1;
                $sale_channels['managers'][$r['sale_channel']]['lines']['new'] += $r['no_of_lines'];
                $sale_channels['all']['nums']['new'] += 1;
                $sale_channels['all']['lines']['new'] += $r['no_of_lines'];
            } else {
                $sale_channels['managers'][$r['sale_channel']]['nums']['old'] += 1;
                $sale_channels['managers'][$r['sale_channel']]['lines']['old'] += $r['no_of_lines'];
                $sale_channels['all']['nums']['old'] += 1;
                $sale_channels['all']['lines']['old'] += $r['no_of_lines'];
            }
        }
        foreach ($res_vpbx as $r) {
            $client_ids[$r['client_id']] = 0;

            if (!isset($sale_vpbx[$r['region']]))
                $sale_vpbx[$r['region']] = array('new' => 0, 'old' => 0, 'all' => 0);

            if ($r['is_new'] > 0) {
                $sale_vpbx[$r['region']]['new'] += 1;
                $sale_vpbx['all']['new'] += 1;
            } else {
                $sale_vpbx[$r['region']]['old'] += 1;
                $sale_vpbx['all']['old'] += 1;
            }
            $sale_vpbx[$r['region']]['all'] += 1;
            $sale_vpbx['all']['all'] += 1;

            if (!isset($clients_vpbx[$r['client_id']])) {
                $clients_vpbx[$r['client_id']] = $r['client_id'];

                if (!isset($vpbx_clients[$r['region']]))
                    $vpbx_clients[$r['region']] = array('new' => 0, 'old' => 0, 'all' => 0);

                if ($r['is_new'] > 0) {
                    $vpbx_clients[$r['region']]['new'] += 1;
                    $vpbx_clients['all']['new'] += 1;
                } else {
                    $vpbx_clients[$r['region']]['old'] += 1;
                    $vpbx_clients['all']['old'] += 1;
                }
                $vpbx_clients[$r['region']]['all'] += 1;
                $vpbx_clients['all']['all'] += 1;
            }


            if (!isset($sale_channels['managers'][$r['sale_channel']]))
                $sale_channels['managers'][$r['sale_channel']] = array('sale_channel_id' => $r['sale_channel_id'], 'vpbx' => array('new' => 0, 'old' => 0, 'all' => 0), 'nums' => array('new' => 0, 'old' => 0, 'all' => 0), 'lines' => array('new' => 0, 'old' => 0, 'all' => 0), 'clients' => array(), 'visits' => 0, 'courier_id' => $r['courier_id']);
            $sale_channels['managers'][$r['sale_channel']]['vpbx']['all'] += 1;
            $sale_channels['managers'][$r['sale_channel']]['clients'][] = $r['client_id'];
            $sale_channels['all']['vpbx']['all'] += 1;
            if ($r['is_new']) {
                $sale_channels['managers'][$r['sale_channel']]['vpbx']['new'] += 1;
                $sale_channels['all']['vpbx']['new'] += 1;
            } else {
                $sale_channels['managers'][$r['sale_channel']]['vpbx']['old'] += 1;
                $sale_channels['all']['vpbx']['old'] += 1;
            }
        }
        /*
  //Выезды
  $res = $db->AllRecords("
          select
            count(*) as cnt,
            c.id as client_id
          from
            tt_stages ts
          left join tt_troubles tt on tt.id=ts.trouble_id
          left join clients c on c.client=tt.client
          where
            ts.state_id=4 and
            ts.date_edit>=date_add('$date',interval -$mm month) and
            ts.date_edit<date_add('$date',interval -$mm+1 month) and
            c.id in (" . implode(',', array_keys($client_ids)) . ")
          group by c.id
          ");
  foreach ($res as $r) {
    foreach ($sale_channels['managers'] as $manager=>&$val) {
        if (in_array($r['client_id'], $val['clients'])) {
            $val['visits'] += $r['cnt'];
            $sale_channels['all']['visits'] += $r['cnt'];
        }
    }
  }
*/
        //Выезды
        foreach ($sale_channels["managers"] as $manager => &$d) {
            if ($d['courier_id'] > 0) {
                $res = $db->GetValue($qq = 'SELECT
                        COUNT(*)
                    FROM
                        tt_stages as st
                    LEFT JOIN
                        tt_troubles as tt ON tt.id = st.trouble_id
                    LEFT JOIN
                        tt_doers as td ON td.stage_id = st.stage_id
                    WHERE
                        tt.id IN (SELECT
                                DISTINCT(t.id)
                            FROM
                                tt_doers as d
                            LEFT JOIN
                                tt_stages as s ON d.stage_id = s.stage_id
                            LEFT JOIN
                                tt_troubles as t ON s.trouble_id = t.id
                            WHERE
                                    d.doer_id = ' . $d['courier_id'] . '
                                AND
                                    s.date_start >= CAST("' . $tmp_date_from . '" AS DATE)
                                AND
                                    s.date_start <= CAST("' . $tmp_date_to . '" AS DATE)
                            )
                        AND
                            st.state_id = 4
                        AND
                            st.date_start >= CAST("' . $tmp_date_from . '" AS DATE)
                        AND
                            st.date_start <= CAST("' . $tmp_date_to . '" AS DATE)
                        AND
                            td.doer_id = ' . $d['courier_id']);


                $sale_channels['all']['visits'] += $res;
                $d['visits'] = $res;
            }
        }
        foreach ($sale_channels["managers"] as $mamager => &$d) {
            if ($sale_channels["all"]["nums"]['all'] > 0) {
                $d["nums_perc"]['new'] = round($d["nums"]['new'] / $sale_channels["all"]["nums"]['all'] * 100);
                $d["nums_perc"]['old'] = round($d["nums"]['old'] / $sale_channels["all"]["nums"]['all'] * 100);
            } else {
                $d["nums_perc"]['new'] = 0;
                $d["nums_perc"]['old'] = 0;
            }
            if ($sale_channels["all"]["lines"]['all'] > 0) {
                $d["lines_perc"]['new'] = round($d["lines"]['new'] / $sale_channels["all"]["lines"]['all'] * 100);
                $d["lines_perc"]['old'] = round($d["lines"]['old'] / $sale_channels["all"]["lines"]['all'] * 100);
            } else {
                $d["lines_perc"]['new'] = 0;
                $d["lines_perc"]['old'] = 0;
            }
            if ($sale_channels["all"]["vpbx"]['all'] > 0) {
                $d["vpbx_perc"]['new'] = round($d["vpbx"]['new'] / $sale_channels["all"]["vpbx"]['all'] * 100);
                $d["vpbx_perc"]['old'] = round($d["vpbx"]['old'] / $sale_channels["all"]["vpbx"]['all'] * 100);
            } else {
                $d["vpbx_perc"]['new'] = 0;
                $d["vpbx_perc"]['old'] = 0;
            }
            $d["visits_perc"] = ($sale_channels["all"]["visits"] > 0) ? round($d["visits"] / $sale_channels["all"]["visits"] * 100) : 0;
        }


        $del_nums = array('all' => 0);
        $del_8800 = array('all' => 0);
        $del_nonums = array('all' => 0);
        $del_lines = array('all' => 0);
        $del_vpbx = array('all' => 0);

        $res = $db->AllRecords("
            select
                u.region,
                u.E164 as phone,
                u.no_of_lines,
                c.id as client_id
            from usage_voip u
            left join clients c on c.client=u.client
            where
                u.actual_to>=CAST('" . $tmp_date_from . "' AS DATE)
            and u.actual_to<=CAST('" . $tmp_date_to . "' AS DATE)
            group by u.region, u.E164, c.id  ");

        $res_vpbx = $db->AllRecords("
            select
                c.region,
                u.id,
                c.id as client_id
            from usage_virtpbx u
            left join clients c on c.client=u.client
            where
                u.actual_to>=CAST('" . $tmp_date_from . "' AS DATE)
            and u.actual_to<=CAST('" . $tmp_date_to . "' AS DATE)
            group by c.region, u.id, c.id  ");

        foreach ($res as $r) {
            if (strlen($r['phone']) > 4) {
                if (strpos($r['phone'], '7800') === 0) {
                    if (!isset($del_8800[$r['region']]))
                        $del_8800[$r['region']] = 0;

                    $del_8800[$r['region']] += 1;
                    $del_8800['all'] += 1;
                } else {
                    if (!isset($del_nums[$r['region']]))
                        $del_nums[$r['region']] = 0;

                    $del_nums[$r['region']] += 1;
                    $del_nums['all'] += 1;
                }
            } else {
                if (!isset($del_nonums[$r['region']]))
                    $del_nonums[$r['region']] = 0;

                $del_nonums[$r['region']] += 1;
                $del_nonums['all'] += 1;
            }

            if (!isset($del_lines[$r['region']]))
                $del_lines[$r['region']] = 0;

            $del_lines[$r['region']] += $r['no_of_lines'];
            $del_lines['all'] += $r['no_of_lines'];
        }

        foreach ($res_vpbx as $r) {
            if (!isset($del_vpbx[$r['region']]))
                $del_vpbx[$r['region']] = 0;

            $del_vpbx[$r['region']] += 1;
            $del_vpbx['all'] += 1;
        }


        if ($toM < 1) $toM = 12;

        $reports[] = array(
            'date' => (!$sum) ? $month_list[$toM - 1] . " " . $toY . " " : $tmp_date_from . ' - ' . $tmp_date_to,
            'month' => 0 + $toM,
            'year' => $toY,
            'region_sums' => $region_sums,
            'sale_nums' => $sale_nums,
            'sale_8800' => $sale_8800,
            'sale_nonums' => $sale_nonums,
            'sale_lines' => $sale_lines,
            'sale_clients' => $sale_clients,
            'sale_channels' => $sale_channels,
            'del_nums' => $del_nums,
            'del_8800' => $del_8800,
            'del_nonums' => $del_nonums,
            'del_lines' => $del_lines,
            'del_vpbx' => $del_vpbx,
            'sale_vpbx' => $sale_vpbx,
            'vpbx_clients' => $vpbx_clients
        );

        if (--$tmp_m < 1) {
                $tmp_m = 12;
                $tmp_y--;
            }

        return $reports;
    }

    function stats_report_by_one_manager()
    {
        $requestData = isset($_POST['data']) ? json_decode($_POST['data']) : '';
        $reportType = isset($_POST['type']) ? $requestData->report . '_' . $requestData->type : $requestData->report;
        $managerReport = \app\classes\stats\PhoneSales::reportBySingleManager($requestData->manager, '2015-11-01', $reportType);

        if (!empty($managerReport))
        {
            echo json_encode([
                'status' => 'OK',
                'data' => $managerReport
            ], JSON_UNESCAPED_UNICODE);
        }
        else
        {
            echo json_encode([
                'status' => 'ERROR',
                'data' => 'Не найдено статистики по указанным параметрам.'
            ], JSON_UNESCAPED_UNICODE);
        }
    }

	function stats_report_vpbx_stat_space($fixclient)
	{
		global $design;
		$dateFrom = new DatePickerValues('date_from', 'first');
		$dateTo = new DatePickerValues('date_to', 'last');
		$from = $dateFrom->getSqlDay();
		$to = $dateTo->getSqlDay();

        $clientAccount = ClientAccount::findOne($fixclient);

		DatePickerPeriods::assignStartEndMonth($dateFrom->day, 'prev_', '-1 month');
		DatePickerPeriods::assignPeriods(new DateTime());
		
		$vpbx_id = get_param_integer('vpbx', 0);
		$design->assign('vpbx_id', $vpbx_id);
		$design->assign('client_id', $fixclient);

		list($stats, $stat_detailed) = $this->getReportVpbxStatSpace($clientAccount, $vpbx_id, $from, $to);
		$design->assign('stats', $stats);
		$design->assign('stat_detailed', $stat_detailed);

        $vpbxs = \app\modules\uu\models\AccountTariff::find()
            ->where(['client_account_id' => $clientAccount->id, 'service_type_id' => \app\modules\uu\models\ServiceType::ID_VPBX])
            ->select(['id', 'tariff_period_id'])
            ->indexBy('id')
            ->all();
		$design->assign('vpbxs', $vpbxs);

		$design->AddMain('stats/vpbx_stat_space_form.tpl');
		$design->AddMain('stats/vpbx_stat_space.tpl');
	}
	/** 
	 *	Получение данных о использование дискового пространства и внутренних номерах
	 *	@param string $fixclient имя клиент
	 *		если задан, статистика берется только по ВАТС данного клиента
	 *	@param int $vpbx_id ВАТС ID
	 *		если задан, то функция дополнительно возвращает детализацию по текущему ВАТС
	 *	@param int $from timestamp начала периода
	 *	@param int $to timestamp конца периода
	 */
	function getReportVpbxStatSpace($clientAccount, $vpbx_id, $from, $to)
	{
		$stat_detailed = array();
		$options = array();
		$options['select'] = '
				stat.usage_id, 
				stat.client_id,

				MAX(stat.use_space) as max, 
				MIN(stat.use_space) as min, 
				AVG(stat.use_space) as avg,

				MAX(stat.numbers) as max_number, 
				MIN(stat.numbers) as min_number, 
				AVG(stat.numbers) as avg_number,

				MAX(stat.ext_did_count) as max_ext_did_count,
				MIN(stat.ext_did_count) as min_ext_did_count,
				AVG(stat.ext_did_count) as avg_ext_did_count,
				
				null as actual,
				null as tarif,
				null as tarif_id
				/*,

				UNIX_TIMESTAMP(LT.date_activation) as actual,
				T.description as tarif,
				T.id as tarif_id */';

		$options['from'] = 'virtpbx_stat as stat';

//		$options['joins'] =
//			'LEFT JOIN clients as C ON C.id = stat.client_id ' .
//            'LEFT JOIN usage_virtpbx as UV ON UV.id = stat.usage_id ' .
//			'LEFT JOIN log_tarif as LT ON UV.id = LT.id_service  ' .
//			'LEFT JOIN tarifs_virtpbx as T ON LT.id_tarif = T.id '
//			;

		$options['group'] = 'stat.usage_id';
		
		$condition_string = "
			LT.id = (
				SELECT id 
				FROM log_tarif as b
				WHERE
					date_activation = (
						SELECT MAX(date_activation)
						FROM log_tarif 
						WHERE 
							CAST(NOW() as DATE) >= date_activation AND 
							service = 'usage_virtpbx' AND 
							id_service = b.id_service
						) AND 
					id_service = LT.id_service
				ORDER BY
						ts desc
				LIMIT 0,1
			) 
                        AND stat.date >= ? 
                        AND stat.date <= ? 
                        AND LT.service = ? 
                        AND UV.actual_from <= ? 
                        AND UV.actual_to >= ?";

        $condition_string = "
                            true
                        AND stat.date >= ?
                        AND stat.date <= ?
                        ";

        $condition_values = array(
            $from,
            $to,
        );
		if ($vpbx_id)
		{
			$condition_string .=' AND stat.usage_id = ?';
			$condition_values[] = $vpbx_id;
		}
        if ($clientAccount !== null)
        {
			$condition_string .=' AND stat.client_id = ?';
			$condition_values[] = $clientAccount->id;
		} else {
//			$options['select'] .= ',UV.client';
		}
		$options['conditions'] = array($condition_string);
		foreach ($condition_values as $v) 
		{
			$options['conditions'][] = $v;
		}
		$stats = VirtpbxStat::find('all', $options);


		if ($clientAccount !== null && !empty($stats))
		{
			$stat_detailed = VirtpbxStat::getVpbxStatDetails($clientAccount->id, $vpbx_id, strtotime($from), strtotime($to));
		}
		return array($stats, $stat_detailed);
	}

	function stats_phone_sales_details($fixclient)
	{
		include_once 'PhoneSalesDetails.php';
		PhoneSalesDetails::getDetails();
	}

	function stats_report_agent_details($fixclient)
    {
        include_once 'AgentReport.php';
        AgentReport::getDetails();
    }

    public function stats_ip($fixclient)
    {
        include_once 'StatIp.php';

        new StatIp($fixclient);
    }

}


class requestPlusOper
{
    function create($client, $d)
    {
        global $db;
        include_once INCLUDE_PATH."1c_integration.php";

        $bm = new \_1c\billMaker($db);

        $zeroDescr = "00000000-0000-0000-0000-000000000000";

        $count = 0;

        //if($count++ > 0) break;

        $metro = "";
        if(preg_match_all("/м\.(.*)$/six",$d['address'], $o ))
            $metro = $o[1][0];

        if($metro == "-")
            $metro = "";


        $ai = array (
                'ФИО' => $d['fio'],
                'Адрес' => $d['address'],
                'НомерЗаявки' => isset($d["req"]) ? $d["req"] : "",
                'ЛицевойСчет' => '',
                'НомерПодключения' => '',
                'Комментарий1' => (isset($d["date_deliv"]) ? "Доставка на: ".$d["date_deliv"] : ""),
                'Комментарий2' => (isset($d["fio_oper"]) ? "Оператор: ".$d["fio_oper"] : ""),
                'ПаспортСерия' => '',
                'ПаспортНомер' => '',
                'ПаспортКемВыдан' => '',
                'ПаспортКогдаВыдан' => '',
                'ПаспортКодПодразделения' => '',
                'ПаспортДатаРождения' => '',
                'ПаспортГород' => '',
                'ПаспортУлица' => '',
                'ПаспортДом' => '',
                'ПаспортКорпус' => '',
                'ПаспортСтроение' => '',
                'ПаспортКвартира' => '',
                'Email' => '',
                'ПроисхождениеЗаказа' => '',
                'КонтактныйТелефон' => $d["phone"],
                'Метро' => $metro,
                'Логистика' => '',
                'ВладелецЛинии' => '',
                );
        $aii = array();
        foreach($ai as $k => $v)
            $aii[\_1c\tr($k)] = \_1c\tr($v);

        $res = array(
                "client_tid" => $client,//WiMaxComstar",
                "order_number" => ($d["bill_no"] ? $d["bill_no"] : false),//"201109/0094",//false,
                "items_list" => array(),
                "order_comment" => $d["comment"],
                "is_rollback" => false,
                "add_info" => $aii,
                "store_id" => "8e5c7b22-8385-11df-9af5-001517456eb1"
                );

        //
        if($client == "nbn")
        {
        	if($d["qty"])
        	{
	            $res["items_list"][] =
	                    array(
	                        "id" => "4e8cc21b-d476-11e0-9255-d485644c7711".":".$zeroDescr,
	                        "quantity" => $d["qty"],
	                        "code_1c" => 0,
	                        "price" => 1); // 13340
        	}


        	$res["items_list"][] =
				array(
				"id" => ($d["deliv_type"] == "moskow" ?
					"a449a3f7-d918-11e0-bdf8-00155d21fe06" :
					"1ccf5f37-03be-11e1-aefc-00155d881200").":".$zeroDescr,
				"quantity" => 1,
				"code_1c" => 0,
				"price" => 1);  // 13363 | 13430

        }else{
            $res["items_list"][] =
                    array(
                        "id" => "f75a5b2f-382f-11e0-9c3c-d485644c7711".":".$zeroDescr,
                        "quantity" => $d["qty"],
                        "code_1c" => 0,
                        "price" => 1); // 13623

            $res["items_list"][] =
            array(
            				"id" => ($d["deliv_type"] == "moskow" ?
            					"81d52242-4d6c-11e1-8572-00155d881200" :
            					"81d52245-4d6c-11e1-8572-00155d881200").":".$zeroDescr,
            				"quantity" => 1,
            				"code_1c" => 0,
            				"price" => 1);  // 13619 | 13621

        }


        try{
            $null = null;
            $ret = $bm->saveOrder($res, $null, false);
        }catch(Exception $e){
            print_r($res);
            var_dump($e);
            exit();
        }

        $c1error = '';
        $cl = new stdClass();
        $cl->order = $ret;
        $cl->isRollback = false;

        $bill_no = $ret->{\_1c\tr('Номер')};

        $sh = new \_1c\SoapHandler();
        $sh->statSaveOrder($cl, $bill_no, $c1error);

        return $bill_no;
    }
}
