<?php

use app\classes\BillContract;

class MailJob {
	public $data = array();
	public $client = array();
	public $encoding = 'utf-8';
	public $emails = array();
	public $files = [];

	public $lang = 'ru-RU';
	public $countryId = \app\models\Country::RUSSIA;

    private $_isInvoice = null;
    public $errorMsg = '';

	private static $prepared = 0;

	public function __construct($id = null) {
		global $db;
		if($id!==null)
			$this->data = $db->GetRow('select * from mail_job where job_id='.$id);
	}
	public static function GetObjectP() {
		return self::GetObject(
			get_param_raw('o'),
			get_param_raw('k')
		);
	}
	public static function GetObject($object_id,$key){
		global $db;
		if (!$object_id || !$key) {
		    return null;
        }
		$v = $db->GetRow('select * from mail_object where object_id='.$db->escape($object_id));
		$key1 = self::get_object_key($v);
		if($key1==$key)
			return $v;
		else
			return null;
	}
	public function assign_client($client){
		global $db;
		$this->client = $db->GetRow('select * from clients where client="'.$client.'"');
		$fullName = '';
		if ($this->client && ($accountClient = \app\models\ClientAccount::findOne(['id' => $this->client['id']]))) {
			$fullName = $accountClient->contragent->name_full;
			$this->lang = $accountClient->organization->lang_code;
			$this->countryId = $accountClient->organization->country_id;
		}
		$this->client['company_full'] = $fullName;
		$this->emails = array();
		$this->files = [];

		if(!$this->client)
			return;
		$R = $db->AllRecords('
			select
				*
			from
				client_contacts
			where
				client_id='.$this->client['id'].'
			AND
				is_official=1
			AND
				type="email"
		');
		foreach($R as $r){
			$r=str_replace(
				array(',',' '),
				array(';',';'),
				$r['data']
			);
			$r = explode(';',$r);
			foreach($r as $v)
				if(trim($v)!=""){
					$this->emails[] = trim($v);
				}
		}
	}
	public function assign_client_data($cdata){
		global $db;
		$this->client = $cdata;
	}
	private static function get_object_key($v){
		$k = md5(
			md5(
				md5($v['job_id']).$v['client_id']
			).$v['object_id']
		);
		return substr($k,0,8);
	}
	public function get_object_link($object_type,$object_param, $source = 2, $isPDF = false){
		global $db;

        if ($object_type == 'act') {
            $object_type = 'akt'; // :facepalm:
        }

		$v = array();
		$v['job_id'] = $this->data['job_id'];
		$v['client_id'] = $this->client['id'];
		$v['object_type'] = $object_type;
		$v['object_param'] = $object_param;
        $v["source"] = $source;
        $v['is_pdf'] = (int)$isPDF;

		$ins = false;
		while(!($r = $db->QuerySelectRow('mail_object',$v))){
			if($ins)
				throw new Exception("Can't create object ".print_r($v,true));
			$ins = true;
			$v['object_id'] = $db->QueryInsert('mail_object',$v);
		}
        return Yii::$app->params['LK_PATH'].'docs/?o='.$r['object_id'].'&k='.self::get_object_key($r);
	}

    public function _get_assignments($match)
    {
        global $db;

        switch ($match[1]) {
            case 'ORDER': {
                return
                    'Приказ о назначении: ' .
                    $this->get_object_link(
                        'order',
                        $db->GetValue("SELECT bill_no FROM newbills WHERE client_id = '" . $this->client['id'] . "' ORDER BY bill_date DESC LIMIT 1")
                    );
            }
            case 'NOTICE': {
                return
                    'Уведомление о назначении: ' .
                    $this->get_object_link(
                        'notice',
                        $db->GetValue("SELECT bill_no FROM newbills WHERE client_id = '" . $this->client['id'] . "' ORDER BY bill_date DESC LIMIT 1")
                    );
            }
            case 'DIRECTOR': {
                return
                    'Информационное письмо о смене генерального директора: ' .
                    $this->get_object_link(
                        'new_director_info',
                        $db->GetValue("SELECT bill_no FROM newbills WHERE client_id = '" . $this->client['id'] . "' ORDER BY bill_date DESC LIMIT 1")
                    );
            }
            case 'DOGOVOR': {
                return
                    BillContract::getString($this->client['id'], time());
            }
            case 'NOTICE_MCM': {
                return
                    'Уведомление о передаче прав и обязанностей по договору №' .
                    BillContract::getString($this->client['contract_id'], time()) . ': ' .
                    $this->get_object_link('notice_mcm_telekom', $this->client['id']);
            }
            case 'SOGL_MCM': {
                return
                    'Соглашение о передаче прав и обязанностей по договору №' .
                    BillContract::getString($this->client['contract_id'], time()) . ': ' .
                    $this->get_object_link('sogl_mcm_telekom', $this->client['id']);
            }
            case 'SOGL_MCN': {
                return
                    'Соглашение о передаче прав и обязанностей по договору №' .
                    BillContract::getString($this->client['contract_id'], time()) . ': ' .
                    $this->get_object_link('sogl_mcn_telekom', $this->client['id']);
            }

            case 'SOGL_MCNSERVICE': {
                return
                    'Соглашение о передаче прав и обязанностей по договору №' .
                    BillContract::getString($this->client['contract_id'], time()) . ': ' .
                    $this->get_object_link('sogl_mcn_service', $this->client['id'], 2, true);
            }

            case 'SOGL_MCNTELEKOMTOSERVICE': {
                return
                    'Соглашение о передаче прав и обязанностей по договору №' .
                    BillContract::getString($this->client['contract_id'], time()) . ': ' .
                    $this->get_object_link('sogl_mcn_telekom_to_service', $this->client['id'], 2, true);
            }

            case 'SOGL_MCNSERVICETOABONSERV':
            case 'SOGL_ABONSERVTOMCNTELEKOM':
            {
                return
                    'Соглашение о передаче прав и обязанностей по договору №' .
                    BillContract::getString($this->client['contract_id'], time()) . ': ' .
                    $this->get_object_link('sogl_mcn_service_to_abonservice', $this->client['id'], 2, true);
            }
        }
        return '';
    }

	public function _get_bills($match)
    {
		global $db;

        require_once(INCLUDE_PATH."bill.php");
        require_once(MODULES_PATH."newaccounts/module.php");

		/*$W = array('AND');
		if($match[1]=='U')
			$W[] = 'is_payed!=1';
		$W[] = 'bill_date LIKE "'.$match[2].'-%"';
		$W[] = 'client_id = '.$this->client['id'];*/

		$T = '';
		if($this->encoding=='utf-8'){
			$s1 = ' от ';
			$s2 = ' г.: ';
		}else{
			$s1 = ' НР ';
			$s2 = ' Ц.: ';
		}

		if($match[1]=='U')
		{
			$pay_flag = 'AND `is_payed`= 0';
		} elseif($match[1]=='P') {
			$pay_flag = 'AND `is_payed`= 2';
		}elseif($match[1]=='N') {
			$pay_flag = 'AND (`is_payed`= 2 OR `is_payed`= 0)';
		} else {
            $pay_flag = '';
        }

        $isPDF = (bool)$match[2];

		$query = "
			SELECT
				*
			FROM
				`newbills`
			WHERE
				`client_id` = ".$this->client['id']."
			AND 
				`sum` > 0 
			AND 
					`bill_date` BETWEEN '" . $match[3] . "-1' AND DATE_ADD(DATE_ADD('" . $match[3] . "-1', INTERVAL 1 MONTH), INTERVAL -1 DAY)
			".$pay_flag;

		$rows = $db->AllRecords($query,null,MYSQLI_ASSOC);

		foreach($rows as $r) {//while($r = $db->NextRecord()){
            if (strlen($T) > 0) {
                $T .= "\n";
            }
            $T .=
                "Счет " . $r['bill_no'] . // номер счета
                $s1 . // от
                date('d.m.Y', strtotime($r['bill_date'])) . // дата счета
                $s2 . // г.
                $this->get_object_link('bill',
                    $r['bill_no'],2, $isPDF); // вот тут косяк. Здешняя библиотека sql не готова к таким зигзагам. Если счетов больше чем 1 - будет выход из цикла.

            // $bill = new Bill($r["bill_no"]);
            // $modelBill = \app\models\Bill::findOne(['bill_no' => $r['bill_no']]);

            // list($b_akt, $b_sf, $b_upd) = m_newaccounts::get_bill_docs_static($bill);
            /*
            if($b_sf[1]) $T .="\nСчет-фактура ".$r['bill_no']."-1: ".$this->get_object_link('invoice',$r['bill_no'],1);
            if($b_sf[2]) $T .="\nСчет-фактура ".$r['bill_no']."-2: ".$this->get_object_link('invoice',$r['bill_no'],2);
            if($b_sf[3]) $T .="\nСчет-фактура ".$r['bill_no']."-3: ".$this->get_object_link('invoice',$r['bill_no'],3);
            if($b_sf[5]) $T .="\nСчет-фактура ".$r['bill_no']."-4: ".$this->get_object_link('invoice',$r['bill_no'],5);
            if($b_sf[6]) $T .="\nСчет-фактура ".$r['bill_no']."-5: ".$this->get_object_link('invoice',$r['bill_no'],6);
            */


            /** Переход на книгу продаж с 1авг 2018 */
            /*
            $b_sf[1] && $T .= "\nСчет-фактура " . $r['bill_no'] . "-1: " . $this->get_object_link('invoice', $r['bill_no'], 1, $isPDF);
            $b_sf[2] &&  $T .= "\nСчет-фактура " . $r['bill_no'] . "-2: " . $this->get_object_link('invoice', $r['bill_no'], 2, $isPDF);
            $b_akt[1] &&  $T .= "\nАкт " . $r['bill_no'] . "-1: " . $this->get_object_link('akt', $r['bill_no'], 1, $isPDF);
            $b_akt[2] && $T .= "\nАкт " . $r['bill_no'] . "-2: " . $this->get_object_link('akt', $r['bill_no'], 2, $isPDF);
            //if($b_akt[3]) $T .="\nАкт ".$r['bill_no']."-3: ".$this->get_object_link('akt',$r['bill_no'],3);
            $b_upd[1] && $T .= "\nУПД " . $r['bill_no'] . "-1: " . $this->get_object_link('upd', $r['bill_no'], 1, $isPDF);
            $b_upd[2] && $T .= "\nУПД " . $r['bill_no'] . "-2: " . $this->get_object_link('upd', $r['bill_no'], 2, $isPDF);
            $b_sf[4] && $T .="\nТоварная накладная ".$r['bill_no'].": ".$this->get_object_link('lading',$r['bill_no'], $isPDF);
            */

            $T .="\n";
		}
		return $T;
	}

    public function _get_invoices($mathes)
    {
        $this->_isInvoice = false;
        $this->errorMsg = '';

        $isPdf = (bool)$mathes[1];

        $dateStart = (new DateTimeImmutable($mathes[3].'-01'));
        $dateEnd = $dateStart->modify('+1 month')->modify('-1 day');

        $billQuery = \app\models\Bill::find()
            ->where(['client_id' => $this->client['id']])
            ->andWhere([
                'between',
                'bill_date',
                $dateStart->format(\app\helpers\DateTimeZoneHelper::DATE_FORMAT),
                $dateEnd->format(\app\helpers\DateTimeZoneHelper::DATE_FORMAT),
            ]);


        $msg = '';
        $this->files = [];
        $count = 0;

        /** @var \app\models\Bill $bill */
        foreach ($billQuery->each() as $bill) {
            $invoice1 = $invoice2 = null;
            $invoices = $bill->invoices;

            if (!$invoices) {
                continue;
            }

            isset($invoices[1]) && $invoice1 = $invoices[1];
            isset($invoices[2]) && $invoice2 = $invoices[2];
            if ($this->countryId == \app\models\Country::RUSSIA) {
                [$b_akt, $b_sf, $b_upd, $b_upd2] = m_newaccounts::get_bill_docs_static($bill->bill_no);
            } else {
                $b_akt = $b_sf = $b_upd = $b_upd2 = [null, false, false];
                $b_sf[1] = true;
                $b_sf[2] = true;
            }

            if (isset($_GET) && isset($_GET['action']) && $_GET['action'] == 'preview') {
                $msg .= "******************\nБудут прикреплены следующие документы: ";
                $b_sf[1] && $invoice1 && $msg .= $this->_getMsgline($invoice1, 'invoice', 1, $isPdf);
                $b_sf[2] && $invoice2 && $msg .= $this->_getMsgline($invoice2, 'invoice', 2, $isPdf);
                $b_akt[1] && $invoice1 && $msg .= $this->_getMsgline($invoice1, 'act', 1, $isPdf);
                $b_akt[2] && $invoice2 && $msg .= $this->_getMsgline($invoice2, 'act', 2, $isPdf);
                $b_upd[1] && $invoice1 && $msg .= $this->_getMsgline($invoice1, 'upd', 1, $isPdf);
                $b_upd[2] && $invoice2 && $msg .= $this->_getMsgline($invoice2, 'upd', 2, $isPdf);
                $b_upd2[1] && $invoice1 && $msg .= $this->_getMsgline($invoice1, 'upd2', 1, $isPdf);
                $b_upd2[2] && $invoice2 && $msg .= $this->_getMsgline($invoice2, 'upd2', 2, $isPdf);
                $msg .= "\n******************\n";
            }

            $b_sf[1] && $invoice1 && ++$count && $this->_get_file_by_invoice($invoice1, 'invoice') && $this->_isInvoice = true;
            $b_sf[2] && $invoice2 && ++$count && $this->_get_file_by_invoice($invoice2, 'invoice') && $this->_isInvoice = true;
            $b_akt[1] && $invoice1 && ++$count && $this->_get_file_by_invoice($invoice1, 'act') && $this->_isInvoice = true;
            $b_akt[2] && $invoice2 && ++$count && $this->_get_file_by_invoice($invoice2, 'act') && $this->_isInvoice = true;
            $b_upd2[1] && $invoice1 && ++$count && $this->_get_file_by_invoice($invoice1, 'upd2') && $this->_isInvoice = true;
            $b_upd2[2] && $invoice2 && ++$count && $this->_get_file_by_invoice($invoice2, 'upd2') && $this->_isInvoice = true;
        }

        if (!$this->_isInvoice) {
            $this->errorMsg = 'Нет документов для отправки';
        } elseif ($count != count($this->files)) {
            $this->errorMsg = sprintf('Не все документы сформированы (%s/%s)', count($this->files), $count);
            $this->_isInvoice = false;
        }

        $msg && $this->_isInvoice = true;

        return $msg;
	}

    /**
     * @param \app\models\Invoice $invoice
     * @param string $type
     * @param integer $typeId
     * @param boolean $isPdf
     * @return string
     */
    private function _getMsgline($invoice, $type, $typeId, $isPdf)
    {
        return "\n" . Yii::t('biller', $type, [], $this->lang) . " " . $invoice->number . ": " . $this->get_object_link($type, $invoice->bill_no, $typeId, $isPdf) .
            ($this->_get_file_by_invoice($invoice, $type) ? ' - OK' : ' - нет печатной версии документа');
	}


    public function _get_file_by_invoice($invoice, $document)
    {
        if (!$invoice) {
            return false;
        }

        $path = $invoice->getFilePath($document);
        $info = pathinfo($path);
        if (!file_exists($path)) {
            return false;
        }
        $this->files[] = ['name' => $info['basename'], 'type' => 'application/pdf', 'path' => $path];
        return true;
	}


	public function Template($str,$format = 'text')
    {
        $this->_isInvoice = null;

		$text = $this->data[$str];
		if($this->encoding!='utf-8')
			$text = convert_cyr_string($text,'k','w');
		$text = str_replace(
			array('%CLIENT%','%CLIENT_NAME%'),
			array($this->client['client'],$this->client['company_full']),
			$text
		);
		$text = preg_replace_callback('/%(A)(PDF)?BILL(\d{4}-\d{2}(?:-\d+)?)%/',array($this,'_get_bills'),$text);
		$text = preg_replace_callback('/%(U)(PDF)?BILL(\d{4}-\d{2}(?:-\d+)?)%/',array($this,'_get_bills'),$text);
		$text = preg_replace_callback('/%(P)(PDF)?BILL(\d{4}-\d{2}(?:-\d+)?)%/',array($this,'_get_bills'),$text);
		$text = preg_replace_callback('/%(N)(PDF)?BILL(\d{4}-\d{2}(?:-\d+)?)%/',array($this,'_get_bills'),$text);
		$text = preg_replace_callback('/%(PDF)?(INVOICE)(\d{4}-\d{2}(?:-\d+)?)%/',array($this,'_get_invoices'),$text);
		$text = preg_replace_callback('/%(NOTICE)_TELEKOM%/',array($this,'_get_assignments'),$text);
		$text = preg_replace_callback('/%(ORDER)_TELEKOM%/',array($this,'_get_assignments'),$text);
		$text = preg_replace_callback('/%(DIRECTOR)_TELEKOM%/',array($this,'_get_assignments'),$text);
		$text = preg_replace_callback('/%(DOGOVOR)_TELEKOM%/',array($this,'_get_assignments'),$text);
		$text = preg_replace_callback('/%(SOGL_MCM)_TELEKOM%/',array($this,'_get_assignments'),$text);
		$text = preg_replace_callback('/%(NOTICE_MCM)_TELEKOM%/',array($this,'_get_assignments'),$text);
        $text = preg_replace_callback('/%(SOGL_MCM)_TELEKOM%/',array($this,'_get_assignments'),$text);
        $text = preg_replace_callback('/%(SOGL_MCN)_TELEKOM%/',array($this,'_get_assignments'),$text);
        $text = preg_replace_callback('/%(SOGL_MCNSERVICE)%/',array($this,'_get_assignments'),$text);
        $text = preg_replace_callback('/%(SOGL_MCNTELEKOMTOSERVICE)%/',array($this,'_get_assignments'),$text);
        $text = preg_replace_callback('/%(SOGL_MCNSERVICETOABONSERV)%/',array($this,'_get_assignments'),$text);
        $text = preg_replace_callback('/%(SOGL_ABONSERVTOMCNTELEKOM)%/',array($this,'_get_assignments'),$text);
		if($format=='html'){
			$text = nl2br(htmlspecialchars_($text));
		}
		return $text;
	}
	private static function SendPrepare(){
		include INCLUDE_PATH."class.phpmailer.php";
		include INCLUDE_PATH."class.smtp.php";
		self::$prepared = 1;
	}
	public function Send($emails = null){
		global $db;
		if(!self::$prepared)
			self::SendPrepare();

		if($emails===null){
			$emails = $this->emails;
		}elseif(!is_array($emails))
			$emails = array($emails);

		$Mail = new PHPMailer();
		$Mail->SetLanguage("ru","include/");
		$Mail->CharSet = $this->encoding;

        if(preg_match("/\s*([^<]+)\s*<\s*([^>]+)\s*>\s*/", $this->data['from_email'], $match)) {
            $fromEmail = trim($match[2]);
            $fromName = trim($match[1]);
        } else {
            $fromEmail = 'info@mcn.ru';
            $fromName = 'MCN';
        }

        $Mail->FromName = $fromName;
        $Mail->From = $fromEmail;
		$Mail->Mailer='smtp';
		$Mail->Host=SMTP_SERVER;
		foreach($emails as $adr) {
            if ($adr) {
                $Mail->AddAddress($adr);
            }
        }
		$Mail->ContentType='text/plain';

		$Mail->Subject = $this->Template('template_subject');
		$Mail->Body = $this->Template('template_body');

		// run before parsing template
        $Files = new mailFiles($this->data['job_id']);
        $files = array_merge($Files->getFiles(true), $this->files);
        if (!empty($files)) {
            foreach ($files as $v) {
                $Mail->AddAttachment($v['path'], "=?utf-8?B?" . base64_encode($v['name']) . "?=", "base64", $v['type']);
            }
        }


        $r = ['job_id' => $this->data['job_id'], 'client' => $this->client['client']];

        if ($this->isRejectedByInvoice()) {
            $ret = $r['send_message'] = $this->errorMsg;
            $r['letter_state'] = 'error';
        } elseif (!(@$Mail->Send())) {
            $ret = $r['send_message'] = $Mail->ErrorInfo;
            $r['letter_state'] = 'error';
        } else {
            $ret = true;
            $r['send_message'] = '';
            $r['letter_state'] = 'sent';
        }
        $r['send_date'] = ['NOW()'];
        $db->QueryUpdate('mail_letter', ['job_id', 'client'], $r);
        return $ret;
	}

	function get_cur_state()
	{
	    global $db;
	    $res = $db->GetValue('select job_state from mail_job where job_id='.$this->data['job_id']);

	    return $res;
	}

    /**
     * У счета нет документов для отправки
     *
     * @return bool
     */
    public function isRejectedByInvoice()
    {
        return $this->_isInvoice === false;
	}
}
