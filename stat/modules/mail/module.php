<?php

use app\models\BusinessProcessStatus;
use app\models\ClientAccount;
use app\models\mail\MailJob as MailJobModel;
use app\models\Number;
use app\models\User;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\ServiceType;

class m_mail{
	var $is_active = 0;
	var $actions=array(
		'default'		=> array('mail','r'),
		'list'			=> array('mail','w'),
		'edit'			=> array('mail','w'),
		'view'			=> array('mail','w'),
		'remove'		=> array('mail','w'),
		'preview'		=> array('mail','w'),
		'state'			=> array('mail','w'),
		'client'		=> array('mail','w'),
		'file_put'		=> array('mail','w'),
		'file_get'		=> array('mail','w'),
		'file_del'		=> array('mail','w')
	);

	var $menu=array(
		array('Почтовые задания',	'list',		''),
		array('Просмотр сообщений',	'default',	''),
	);

	function __construct(){}
	function GetPanel(){
		global $fixclient_data,$db;
		$R=array();
		foreach($this->menu as $val){
			$act=$this->actions[$val[1]];
			if (access($act[0],$act[1])) $R[]=array($val[0],'module=mail&action='.$val[1].(isset($val[2])?$val[2]:''), (isset($val[3])?$val[3]:''),(isset($val[4])?$val[4]:''));
		}
		if (!count($R)) return;
		// if (access('mail','r') && !access('mail','w') && isset($fixclient_data)) {
		// 	if ($this->is_active==0 && $db->GetRow('select * from mail_object where client_id="'.$fixclient_data['id'].'" AND object_type="PM" AND view_count=0 LIMIT 1')){
		// 		trigger_error2('<a href="?module=mail">У вас есть непросмотренные сообщения</a>');
		// 	}
		// 	return array('Просмотр сообщений',$R);
		// } else {
            return array('Письма клиентам',$R);
		// }
	}

	function GetMain($action,$fixclient){
		global $design,$db,$user;
		if (!isset($this->actions[$action])) return;
		$this->is_active = 1;
		$act=$this->actions[$action];
		if (!access($act[0],$act[1])) return;
		call_user_func(array($this,'mail_'.$action),$fixclient);
	}
	function mail_list(){
		global $db,$design;
		$L = $db->AllRecords('
			SELECT
				mail_job.*,
				COUNT(mail_letter.client) as cnt_total,
				SUM(IF(mail_letter.letter_state="sent",1,0)) as cnt_sent
			FROM
				mail_job
			LEFT JOIN
				mail_letter
			ON
				mail_letter.job_id = mail_job.job_id
			GROUP BY
				job_id
			ORDER BY
				job_id desc
            limit 150
		');
		$design->assign('mail_job',$L);
		$design->AddMain('mail/list.tpl');
	}
	function mail_edit(){
		global $design,$user;
		$id=get_param_integer('id');

        $data = [
            'template_body' => get_param_raw('body'),
            'template_subject' => get_param_raw('subject'),
            'date_edit' => new \yii\db\Expression('NOW()'),
            'user_edit' => $user->Get('user'),
            'from_email' => get_param_raw('from_email')
        ];

        $mailJob = null;

        if($id) {
            $mailJob = MailJobModel::findOne(['job_id' => $id]);
        }

		if (!$mailJob) {
			$mailJob = new MailJobModel();
		}

		$mailJob->setAttributes($data, false);
        if (!$mailJob->save()) {
            throw new \app\exceptions\ModelValidationException($mailJob);
        }
        $mailJob->refresh();

		if($design->ProcessEx('errors.tpl')) {
            header('Location: ?module=mail&action=view&id=' . $mailJob->job_id);
            exit;
        }
	}
	function mail_view($fixclient){
		global $db,$design;
		if(!($id=get_param_integer('id'))){
			$design->assign(
				'template',
				array(
					'template_body'=>'Текст письма',
					'template_subject'=>'Тема письма',
					'job_id'=>null,
					'from_email' => reset($this->_getFromEmails()),
				)
			);
		}else{
			$design->assign(
				'template',
				$r = $db->GetRow('select * from mail_job where job_id='.$id)
			);
			$L = $db->AllRecords('
				select
					L.*,
					C.id as client_id
				from
					mail_letter as L
				inner join
					clients as C
				ON
					C.client=L.client
				where
					job_id='.$id.'
				order by
					letter_state,
					L.client
			');
			foreach($L as &$l){
				if($l['letter_state']=='sent'){
					$l['objects'] = $db->AllRecords('
						select
							*
						from
							mail_object
						where
							job_id='.$id.'
						AND
							client_id='.$l['client_id']
					);
				}
			}
			unset($l);
			$design->assign('mail_letter',$L);
			$Files = new mailFiles($id);
			$files = $Files->getFiles();
			$design->assign('files', $files);
			$design->assign('count_files', count($files));
			$design->assign('job_id', $id);
		}

		$design->assign('emails', $this->_getFromEmails());

		$design->AddMain('mail/view.tpl');
	}

    private function _getFromEmails()
    {
        $from =  [
            'МСН Телеком <info@mcn.ru>',
            'MCN <bill@wellsystems.ru>',
            'MCNtelecom GmbH <info@mcntele.com>',
            'MCNtelecom <invoice@mcntele.com>',
            'MCNtelecom GmbH <invoice@mcntele.com>',
            'MCNtelecom <info@kompaas.tech>',
            'MCNtelecom GmbH <info@kompaas.tech>',
            'KOMPaaS.tech GmbH <invoice@kompaas.tech>',
        ];

        return array_combine($from, $from);
    }

	function mail_remove(){
		global $db,$design;
		$id=get_param_integer('id');
		$db->Query('delete from mail_job where job_id='.$id);
		$db->Query('delete from mail_letter where job_id='.$id);
		if ($design->ProcessEx('errors.tpl')) {
            header('Location: ?module=mail');
            exit;
        }
	}
	function mail_client(){
		global $db,$design;
		if(!($id=get_param_integer('id')))
			return;
		$clients = get_param_raw('clients',array());
		$flag = get_param_raw('flag',array());
		$flag2 = get_param_raw('flag2',array());
        $ack = get_param_raw('ack', 0);

		if(is_array($clients)){
			$V = array();
			$db->Query('select client from mail_letter where job_id='.$id);
			while($r = $db->NextRecord())
				$V[$r['client']] = 1;
			$str1 = '';
			$str2 = '';
			foreach($clients as $k=>$v){
				if(isset($V[$v]) && !isset($flag2[$k])){
					$str1.=($str1?',':'').'"'.$v.'"';
					unset($V[$v]);
				}
				if((isset($flag[$k]) || isset($flag2[$k])) && !isset($V[$v])){
					$str2.=($str2?',':'').'('.$id.',"'.$v.'")';
				}
			}
			if($str1)
				$db->Query('delete from mail_letter where job_id='.$id.' AND client IN ('.$str1.')');
			if($str2)
				$db->Query('insert into mail_letter (job_id,client) values '.$str2);
		}

		$W = array('AND');
		$J = array();
		$filter = get_param_raw('filter',array());
		$disable_filter = get_param_raw('disable_filter',false);
		$design->assign('disable_filter', $disable_filter);
		$dateFrom = new DatePickerValues('date_from', 'first');
		$dateTo = new DatePickerValues('date_to', 'last');
		$dateFrom->format = 'Y-m-d';$dateTo->format = 'Y-m-d';
		if (!empty($filter)) {
			$filter['bill'][1] = $dateFrom->getDay();
			$filter['bill'][2] = $dateTo->getDay();
		}

		$J[] = "INNER JOIN client_contract CC ON (CC.id = C.contract_id)";
		$J[] = "INNER JOIN " . BusinessProcessStatus::tableName() . " BPS ON (BPS.id = CC.business_process_status_id)";

		foreach($filter as $type=>$p)
			if($p[0]!=='')
				switch($type){
					case 'manager':
						$W[] = 'CC.manager="'.addslashes($p[0]).'"';
						break;
					case 'node':
						$J[] = 'INNER JOIN usage_ip_ports AS uipp ON C.client=uipp.client';
						$J[] = 'INNER JOIN tech_ports AS tech_ports ON uipp.port_id=tech_ports.id';
						$W[] = "tech_ports.node LIKE '".addslashes($p[0])."'";
						break;
					case 'bill':
						$W[] = 'B.bill_date>="'.addslashes($p[1]).'"';
						$W[] = 'B.bill_date<="'.addslashes($p[2]).'"';
						$W[] = 'B.`sum` > 0';
						$J[] = 'INNER JOIN newbills as B ON B.client_id=C.id';
						switch ($p[0])
						{
							case 2: 
								$W[] = 'B.is_payed = 0';
								break;
							case 3: 
								$W[] = 'B.is_payed = 2';
								break;
							case 4: 
								$W[] = '(B.is_payed = 2 OR B.is_payed = 0)';
								break;
						}
						
						break;
					case 'pay_type':
						$W[] = 'C.is_postpaid = ' . intval($p[0]);
						break;
					case 'closing_docs':
						if (!empty($p[1])) {
							$monthStart = $p[1] . '-01';
							$monthEnd = date('Y-m-t', strtotime($monthStart));
							$J[] = 'INNER JOIN newbills as BCD ON BCD.client_id=C.id';
							$J[] = 'INNER JOIN invoice as INV ON INV.bill_no=BCD.bill_no';
							$W[] = 'INV.date >= "' . addslashes($monthStart) . '"';
							$W[] = 'INV.date <= "' . addslashes($monthEnd) . '"';
							$W[] = 'INV.is_reversal = 0';
						}
						break;
					case 's8800':
						$J[] = 'LEFT JOIN usage_voip as UV8 ON UV8.client = C.client';
						$W[] = "CAST(NOW() AS DATE) BETWEEN UV8.actual_from AND UV8.actual_to";
						if ($p[0] == 'with') {
							$W[] = "UV8.E164 LIKE '7800%'";
						} else {
							$W[] = "UV8.client NOT IN (SELECT client 
											FROM usage_voip
											WHERE 
												CAST(NOW() as DATE) BETWEEN actual_from AND actual_to AND 
												E164 LIKE '7800%')";
						}
						
						break;
					case 'regions':
						if (!empty($p)) {
							if ($filter['region_for'][0] == 'client') 
							{
								$W[] = "C.region IN ('" . implode("', '", $p) . "')";
							} elseif ($filter['region_for'][0] == 'tarif') {
								if (!$filter['tarifs']) {
									$J[] = 'LEFT JOIN usage_voip as UV ON (UV.client = C.client AND CAST(NOW() AS DATE) BETWEEN UV.actual_from AND UV.actual_to)';
								}
								$J[] = 'LEFT JOIN ' . AccountTariff::tableName() . ' as uu ON (uu.client_account_id = C.id AND uu.service_type_id = ' . ServiceType::ID_VOIP. ' AND tariff_period_id IS NOT NULL)';
								$J[] = 'LEFT JOIN ' . Number::tableName() . ' num ON (num.number = uu.voip_number)';
								$W[] = "(UV.region IN ('" . implode("', '", $p) . "') OR num.region IN ('" . implode("','", $p) . "'))";
							}
						}
						break;
					case 'tarifs':
						if (!empty($p)) {
							$J[] = 'LEFT JOIN usage_voip as UV ON UV.client = C.client';
							$J[] = "LEFT JOIN log_tarif as LT ON UV.id = LT.id_service";
							$J[] = 'LEFT JOIN tarifs_voip as TV ON TV.id = LT.id_tarif';
							$W[] = "LT.id_tarif IN ('" . implode("', '", $p) . "')";
							$W[] = "LT.service = 'usage_voip'";
							$W[] = "CAST(NOW() AS DATE) BETWEEN UV.actual_from AND UV.actual_to";
							$W[] = "LT.id = (
									SELECT id 
									FROM log_tarif as b
									WHERE
										date_activation = (
											SELECT MAX(date_activation)
											FROM log_tarif 
											WHERE 
												CAST(NOW() as DATE) >= date_activation AND 
												service = 'usage_voip' AND 
												id_service = b.id_service
											) AND 
										id_service = LT.id_service
									ORDER BY
											ts desc
									LIMIT 0,1
									)";
						}
                        break;

                    case 'organization': 
                        if (!empty($p)) {
                            $W[] = "CC.organization_id = '".$p[0]."'";
                        }
                    break;
				}
        $design->assign('f_node', $db->AllRecords("SELECT DISTINCT id, node, address FROM tech_ports WHERE port_name <> 'mgts' AND LENGTH(node) > 0 GROUP BY node ORDER BY node ASC", 'id'));
		$design->assign('mail_filter',$filter);
		$design->assign('mail_id',$id);
		$design->assign('f_manager', User::dao()->getListByDepartments('manager'));
        $design->assign('f_organization', \app\models\Organization::find()->actual()->all());
		$design->assign('f_status', ClientAccount::$statuses);
		$design->assign('f_payment_types', ClientAccount::$paymentTypes);
		$f_regions = $db->AllRecords("select id, short_name, name from regions order by id desc", 'id');
		$f_tarifs = array();
		foreach ($f_regions as $v) {
			$_tarifs = $db->AllRecords("
				select 
					id, name 
				from 
					tarifs_voip 
				WHERE 
					connection_point_id = " . $v['id'] . " AND
					status != 'archive' 
				order by 
					name asc");
			if (!empty($_tarifs)) {
				$f_tarifs[$v['id']] = array_chunk($_tarifs, round(count($_tarifs)/3));
			}
			
		}
		$f_regions = array_chunk($f_regions, round(count($f_regions)/4), true);
		$design->assign('f_regions', $f_regions);
		$design->assign('f_tarifs', $f_tarifs);
		$J[] = 'LEFT JOIN client_contacts as M ON M.type="email" AND M.client_id=C.id AND M.is_official=1';

		$C = array();
		$R = $db->AllRecords('
			select
				C.*,
				letter_state,
				1 as selected,
				0 as filtered
			from
				mail_letter as L
			INNER JOIN
				clients as C
			ON
				C.client=L.client
			WHERE
				L.job_id='.$id.'
			ORDER BY
				selected desc,
				C.client asc
		');

        if ($ack) {
            $W[] = "BPS.is_bill_send";
        }
		
		foreach($R as $r)
			$C[$r['id']] = $r;
		if($ack || (count($W)>1)){
			$W[] = 'C.client!=""';
			$R = $db->AllRecords($q='
				select
					C.*,
					0 as selected,
					IF(M.data="",0,1) as filtered
				from
					clients as C
				' . implode(' ', $J) . '
				WHERE
				' . MySQLDatabase::Generate($W) . '
				GROUP BY
					C.id
				ORDER BY
					C.client
                    ');

			foreach($R as $r){
				if(!isset($C[$r['id']]))
					$C[$r['id']] = $r;
				else
					$C[$r['id']]['filtered']=$r['filtered'];
			}
		}
		$design->assign('mail_clients',$C);
		$design->AddMain('mail/filter.tpl');
	}
	function mail_file_put($fixclient) {
		global $design;
		if(!($job_id=get_param_integer('job_id')))
			return;

		$Files=new mailFiles($job_id);
		$Files->putFile();
		if ($design->ProcessEx('errors.tpl')) {
            header('Location: ?module=mail&action=view&id='.$job_id);
            exit;
        }
	}
	function mail_file_get($fixclient) {
		global $design;
		$job_id = get_param_integer('job_id');
		if (!$job_id) return;
		$Files=new mailFiles($job_id);
		if ($f = $Files->getFile(get_param_protected('file_id'))) {
			header("Content-Type: " . $f['type']);
			header("Pragma: ");
			header("Cache-Control: ");
			header('Content-Transfer-Encoding: binary');
			header('Content-Disposition: attachment; filename="'.iconv("UTF-8","CP1251",$f['name']).'"');
			header("Content-Length: " . filesize($f['path']));
			readfile($f['path']);
			$design->ProcessEx();
            exit;
		}
	}
	function mail_file_del($fixclient) {
		global $design;
		$job_id = get_param_integer('job_id');
		if (!$job_id) return;
		$Files=new mailFiles($job_id);
		$Files->deleteFile(get_param_protected('file_id'));
		if ($design->ProcessEx('errors.tpl')) {
            header('Location: ?module=mail&action=view&id='.$job_id);
            exit;
        }
	}
	function mail_state($fixclient) {
		global $db,$design;
		$id=get_param_integer('id');
		$state = get_param_raw('state');
		$db->Query('update mail_job set job_state="'.$state.'" where job_id='.$id);

        if($state == "ready") //реальная отправка писем
        {
//            $this->_publishClientBills($id);
            \app\models\Bill::dao()->publishAllBills();
        }

		if ($state=='PM') 
        {
			$job = new MailJob($id);
			$R=$db->AllRecords('select * from mail_letter where job_id='.$id);
			foreach ($R as $r) 
            {
                $job->assign_client($r['client']);
                $job->get_object_link('PM',$id);
                $db->QueryUpdate(
                        'mail_letter',
                        array('job_id', 'client'),
                        array(
                            'job_id' => $id,
                            'client' => $r['client'],
                            'letter_state' => 'sent',
                            'send_message' => '',
                            'send_date' => array('NOW()')
                            )
                        );
            }

		}
		if ($design->ProcessEx('errors.tpl')) {
            header('Location: ?module=mail&action=view&id='.$id);
            exit;
        }
	}

    function _publishClientBills($jobId)
    {
        global $db;

        // публикуем счета менеджера, за этот и предыдущий месяц.
        $db->Query($q = 
            "update
                client_contract cc, 
                clients c,
                newbills b
            set 
                is_show_in_lk = 1
            where 
                cc.manager in (
                    select 
                        distinct manager  
                    from 
                        mail_letter m ,clients c, client_contract cc
                    where
                            job_id = '".$jobId."'
                        and c.client=m.client
                        and cc.id = c.contract_id
                )
                and cc.id = c.contract_id
                and c.id=b.client_id
                and (
                        bill_no like '".date("Ym")."%'
                    or  bill_no like '".date("Ym", strtotime("-1 month"))."%'
                )
                and is_show_in_lk =0
                ");
    }

	function mail_default($fixclient,$pre = 0) {
		global $db,$design,$user,$fixclient_data;
        return $this->mail_list($fixclient);
		if (!$fixclient) return;
		$R = $db->AllRecords('select * from mail_object where client_id="'.$fixclient_data['id'].'" AND object_type="PM"'.($pre?' AND view_count=0':'').' ORDER BY object_id');
		foreach ($R as $r) {
			$job = new MailJob($r['job_id']);
			$job->assign_client_data($fixclient_data);
			$design->assign('pm_subject',$job->Template('template_subject','html'));
			$design->assign('pm_body',$job->Template('template_body','html'));
			$design->AddMain('mail/pm.tpl',1);
			unset($job);
		}
	}
	function mail_preview($fixclient){
		global $db,$design;
		$id=get_param_integer('id');
		$client = get_param_raw('client');
		$obj = new MailJob($id);
		$obj->assign_client($client);
		echo "<h2>".$obj->Template('template_subject')."</h2>";
		echo "<pre>"; echo $obj->Template('template_body');echo "</pre>";
		$design->ProcessEx('errors.tpl');
	}
}

?>
