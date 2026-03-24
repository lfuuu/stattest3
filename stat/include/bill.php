<?php

use app\dao\BillDao;
use app\exceptions\ModelValidationException;
use app\models\BillExternal;
use app\models\BillLine;
use app\models\ClientAccount;
use app\models\Courier;
use app\models\Country;
use app\models\BillDocument;
use app\models\ClientContract;
use app\models\OperationType;

class Bill {
    public $client_id;

    /** @var $client_data ClientAccount */
    private $client_data = null;
    private $bill_no;
    private $bill_ts;
    private $bill;
    private $changed ;
    private $max_sort;
    private $_comment = null;
    private $bill_courier;
    public $negative_balance=false; //для 4й счет-фактуры - если недостаточно средств для проведения авансовых платежей

    public function SetComment($s) {
        $this->_comment=$s;
    }

    /**
     * @param string $field
     * @return ClientAccount
     */
    public function Client($field = '')
    {

        if (!$this->client_data) {
            $this->client_data = ClientAccount::findOne(['id' => $this->client_id])
                ->loadVersionOnDate($this->bill["bill_date"]);
        }

        return ($field ? ($this->client_data[$field]) : ($this->client_data));
    }

    public function SetClientDate($date)
    {
        $this->client_data = ClientAccount::findOne(['id' => $this->client_id])
            ->loadVersionOnDate($date);
    }

    /**
     * Bill constructor.
     * @param string $bill_no
     * @param string $client_id
     * @param string $bill_date
     * @param int $is_auto
     * @param string $currency
     * @param int $operation_type_id
     * @param bool $isShowInLk
     * @param bool $isUserPrepay
     * @param int $isForcePriceIncludeVat
     * @throws ModelValidationException
     */
    public function __construct($bill_no, $client_id = '', $bill_date = '', $is_auto = 1, $currency = null, $operation_type_id = null, $isShowInLk = true, $isUserPrepay = false, $isForcePriceIncludeVat = null)
    {
        global $db;
        if ($bill_no){
            $this->bill_no=$bill_no;
            $r=$db->GetRow("
				select
					max(sort) as v
				from
					newbill_lines
				where
					bill_no='".$bill_no."'
            ");
            $this->max_sort=($r?$r['v']:0);
        } else {
            is_object($client_id) && $client_id = $client_id->toArray();

            $this->client_id = is_array($client_id) ? $client_id['id'] : $client_id;

            !$currency && $currency = $this->client_data['currency'];

            $this->bill_date = date('Y-m-' . ($is_auto ? '01' : 'd'), $bill_date);

            $this->bill_no = BillDao::me()->spawnBillNumber($bill_date, $this->Client()->contract->organization_id);

           
            $bill = new \app\models\Bill();
            $bill->operation_type_id = $operation_type_id;
            $bill->client_id = $this->client_id;
            $bill->currency = $this->Client()->currency;
            $bill->bill_no = $this->bill_no;
            $bill->bill_date = $this->bill_date;
            $bill->nal = $this->client_data["nal"];
            $bill->is_show_in_lk = $isShowInLk ? 1 : 0;
            $bill->is_user_prepay = $isUserPrepay ? 1 : 0;
            $bill->is_approved = 1;
            $bill->price_include_vat = $isForcePriceIncludeVat === null ? $this->client_data['price_include_vat'] : (int)(bool)$isForcePriceIncludeVat;
            $bill->biller_version = $this->client_data['account_version'];
            if (!$bill->save()) {
                throw new ModelValidationException($bill);
            }
        }

        $this->bill = $db->GetRow("
			select
				*,
				UNIX_TIMESTAMP(bill_date) as ts,
				UNIX_TIMESTAMP(doc_date) as doc_ts
			from
				newbills
			where
				bill_no='".$this->bill_no."'
        ");

        // rename if rollback
        if($this->bill["is_rollback"]){
            switch($this->bill["state_1c"]) {
                case 'КОтгрузке': $this->bill["state_1c"] = "К поступлению"; break;
                case 'Отгружен': $this->bill["state_1c"] = "Принят"; break;
            }
        }
        $this->bill_ts=$this->bill['ts'];
        unset($this->bill['ts']);
        $this->client_id=$this->bill['client_id'];
        
        if ($this->bill && isset($this->bill['client_id'])) {
            $this->bill['tax_rate'] = $this->Client()->getTaxRate();
        }
        $this->bill_courier = Courier::dao()->getNameById($this->bill["courier_id"]);
        $this->changed=0;
    }

    /**
     * Добавление проводки счета
     *
     * @param string $title
     * @param float $amount
     * @param float $price
     * @param string $type
     * @param string $service
     * @param string $id_service
     * @param string $date_from
     * @param string $date_to
     * @param float $costPrice
     * @return bool
     */
    public function AddLine($title, $amount, $price, $type, $service='', $id_service='', $date_from='', $date_to='', $costPrice = 0.0, $tax_rate = null)
    {
        $this->changed = 1;
        $this->max_sort++;

        if (!$date_from && !$date_to){
            if ($price < 0) {
                $date_from = \app\classes\Utils::dateBeginOfPreviousMonth($this->bill['bill_date']);
                $date_to = \app\classes\Utils::dateBeginOfPreviousMonth($this->bill['bill_date']);
            } else {
                $date_from = \app\classes\Utils::dateBeginOfMonth($this->bill['bill_date']);
                $date_to = \app\classes\Utils::dateEndOfMonth($this->bill['bill_date']);
            }
        }

        /** @var ClientAccount $clientAccount */
        $clientAccount = ClientAccount::findOne($this->client_id);
        $clientAccount->loadVersionOnDate($this->bill['bill_date']);

        if ($type == 'zadatok') {
            $bill =  \app\models\Bill::findOne($this->bill['id']);
            $bill->price_include_vat = $this->bill['price_include_vat'] = 1;
            $bill->save();
        }

        $line = new BillLine();
        $line->bill_no = $this->bill_no;
        $line->setParentId($this->Get('id'));
        $line->sort = $this->max_sort;
        $line->item = $title;
        $line->amount = $amount;
        $line->type = $type;
        $line->service = $service;
        $line->id_service = $id_service;
        $line->date_from = $date_from;
        $line->date_to = $date_to;
        $line->tax_rate = is_numeric($tax_rate) ? $tax_rate : $clientAccount->getTaxRateOnDate($line->date_from);
        $line->price = $price;
        $line->cost_price = $costPrice;
        if ($this->bill['operation_type_id'] == OperationType::ID_COST) {
            $line->sum = $price;
        } else {
            $line->calculateSum($this->bill['price_include_vat']);
        }
        $line->save();

        return true;
    }
	public function AddLines($R){
		$b=true;
		foreach($R as $r){
			$b1=$this->AddLine($r[1],$r[2],$r[3],$r[4],$r[5],$r[6],$r[7],$r[8]);
			$b=$b && $b1;
			if(!$b1)
				trigger_error2('Невозможно добавить '.$r[1].'-'.$r[3].$r[0].'x'.$r[2]);
		}
		return $b;
	}
    public function SetNal($nal)
    {
        if ($this->bill["nal"] != $nal && in_array($nal, array("nal", "beznal","prov"))) {
            $this->Set("nal", $nal);
        }
    }

    public function SetExtNo($bill_no_ext)
    {
        BillExternal::saveValue($this->bill_no, 'ext_bill_no', $bill_no_ext);
    }

    public function SetInvoiceNoExt($no)
    {
        BillExternal::saveValue($this->bill_no, 'ext_invoice_no', $no);
    }

    public function SetInvoiceDateExt($date)
    {
        BillExternal::saveValue($this->bill_no, 'ext_invoice_date', $date);
    }

    public function SetExtDate($bill_no_ext_date='')
    {
        BillExternal::saveValue($this->bill_no, 'ext_bill_date', $bill_no_ext_date);
    }

    public function SetRegistrationDateExt($date)
    {
        BillExternal::saveValue($this->bill_no, 'ext_registration_date', $date);
    }

    public function SetAktNoExt($no)
    {
        BillExternal::saveValue($this->bill_no, 'ext_akt_no', $no);
    }

    public function SetAktDateExt($no)
    {
        BillExternal::saveValue($this->bill_no, 'ext_akt_date', $no);
    }

    public function SetVatExt($vat)
    {
        $vat = str_replace(',', '.', $vat);
        $vat = floatval($vat);
        BillExternal::saveValue($this->bill_no, 'ext_vat', !$vat ? null : $vat);
    }

    public function SetSumWithoutVatExt($vat)
    {
        $vat = str_replace(',', '.', $vat);
        $vat = floatval($vat);
        BillExternal::saveValue($this->bill_no, 'ext_sum_without_vat', !$vat ? null : $vat);
    }

    public function SetPriceIncludeVat($price_include_vat)
    {
        if ($this->bill["price_include_vat"] != $price_include_vat) {
            $this->Set("price_include_vat", $price_include_vat);
        }
    }

    public function SetIsShowInLk($is_show_in_lk)
    {
        if ($this->bill["is_show_in_lk"] != $is_show_in_lk) {
            $this->Set("is_show_in_lk", $is_show_in_lk);
        }
    }
    
    public function SetCourier($courierId){
        if ((int)$courierId != $courierId) return;
        if ($this->bill["courier_id"] != $courierId) {
            global $db;
            $this->Set("courier_id", $courierId);
            $db->QueryUpdate("courier", array("id"), array("id" => $courierId, "is_used" => "1"));
        }
    }

    public function SetIsToUuInvoice($isToUuInvoice = null)
    {
        if ($isToUuInvoice === null) {
            return;
        }

        $this->Set('is_to_uu_invoice', (int)(bool)$isToUuInvoice);
    }

    public function EditLine($sort, $title, $amount, $price, $type, $tax_rate = null, $accountEntryId = null, $dateFrom = null, $dateTo = null)
    {
        $this->changed = 1;

        /** @var ClientAccount $clientAccount */
        $clientAccount = ClientAccount::findOne($this->client_id);
        $clientAccount->loadVersionOnDate($this->bill['bill_date']);

        /** @var BillLine $line */
        $line = BillLine::find()->where(['bill_no' => $this->bill_no, 'sort' => $sort])->limit(1)->one();
        if (!$line) {
            return;
        }

        $line->isFromFrontEdit = true;
        $line->item = $title;
        $line->amount = $amount;
        $line->price = $price;
        $line->type = $type;

        if ($tax_rate !== null) {
            $line->tax_rate = $tax_rate;
        }

        if ($dateFrom !== null && $dateTo !== null) {
            $line->date_from = $dateFrom;
            $line->date_to = $dateTo;
        }

        if ($this->bill['operation_type_id'] == OperationType::ID_COST) {
            $line->sum = $price;
        }
        $line->calculateSum($this->bill['price_include_vat']);
        $line->save();

        if ($line->uu_account_entry_id && $accountEntryId && $line->uu_account_entry_id == $accountEntryId) {
            $entry = $line->accountEntry;

            if (!$entry || $entry->vat_rate == $line->tax_rate) {
                return;
            }

            $entry->vat_rate = $line->tax_rate;
            $entry->vat = $line->sum_tax;
            $entry->price_without_vat = $line->sum_without_tax;
            $entry->price_with_vat = $line->sum;

            if (!$entry->save()) {
                throw new ModelValidationException($entry);
            }
        }
    }

    public function RemoveLine($sort) {
        $this->changed = 1;

        $line = BillLine::find()->where(['bill_no' => $this->bill_no, 'sort' => $sort])->limit(1)->one();
        $line->isFromFrontEdit = true;
        if ($line) {
            $line->delete();
        }
    }
    public static function RemoveBill($bill_no) {
        $bill = \app\models\Bill::findOne(['bill_no' => $bill_no]);
        if ($bill) {
            $bill->delete();
        }
    }
    public function Save($remove_empty = 0,$check_change=1) {
        global $db;
        if ($check_change && !$this->changed && !$remove_empty) return 0;
        $this->changed=0;

        $hasLines = BillLine::findOne(['bill_no' => $this->bill_no]) !== null;
        if ($hasLines || !$remove_empty) {
            $bSave = $this->bill;
            unset($bSave["doc_ts"]);

            $bill = \app\models\Bill::findOne(['bill_no' => $this->bill_no]);
            $bill->setAttributes($bSave, false);
            $bill->detachBehavior('BillChangeLog');
            $bill->save();
            $bill->attachBehavior('BillChangeLog', \app\classes\behaviors\BillChangeLog::class);
            $bill->dao()->recalcBill($bill);
            $this->bill_ts = unix_timestamp($this->Get('bill_date'));
            BillDocument::dao()->updateByBillNo($this->bill_no);
            return 1;
        } else {
            $bill = \app\models\Bill::find()->andWhere(['bill_no' => $this->bill_no])->one();
            $bill->delete();
            return 2;
        }
    }

    public static function cleanOldPrePayedBills()
    {
        global $db;

        foreach($db->AllRecords(
            "
            SELECT
                b.bill_no, bill_date
            FROM newbills b
            LEFT JOIN newpayments p ON (b.client_id = p.client_id and (b.bill_no = p.bill_no OR b.bill_no = p.bill_vis_no))
            WHERE
                    is_user_prepay=1
                AND bill_date < SUBDATE(NOW(), INTERVAL 1 month)
                AND p.payment_no IS NULL
            ") as $b)
        {
            self::RemoveBill($b["bill_no"]);
        }
    }

	public function GetNo() {
		return $this->bill_no;
	}
	public function GetBill() {
		return $this->bill;
	}

    public function GetExt()
    {
        return BillExternal::find()->where(['bill_no' => $this->bill_no])->asArray()->one() ?: (new BillExternal())->getAttributes();
	}

    public function GetExtFile()
    {
        return \app\models\media\BillExtFiles::findOne(['bill_no' => $this->bill_no]);;
    }

    public function isClosed()
    {
        global $db;

        $t = $db->GetRow(
                "SELECT state_id
                FROM tt_troubles t, tt_stages s
                WHERE bill_no = '".$this->bill_no."' and  t.cur_stage_id = s.stage_id");
        return $t ? $t["state_id"] == 20 : false;
    }

    public function GetStaticComment()
    {
        global $db;
        $v = $db->GetRow("select ts as date, user, comment from `log_newbills_static`  l
                left join user_users u on (user_id = u.id)
                where bill_no = '".$this->bill_no."'");
        if($v)
        {
            $v["comment"] = nl2br($v["comment"]);
        }
        return $v;
    }
	public function GetTs() {
		return $this->bill_ts;
	}
    public function GetCourier() {
        return $this->bill_courier;
    }
	public function Set($p,$v) {
		if ($this->bill[$p]!=$v) {
			$this->bill[$p]=$v;
			$this->changed = 1;
		}
	}
	public function Get($p) {
		return isset($this->bill[$p])?$this->bill[$p]:null;
	}
	public function refactLinesWithFourOrderFacure(&$ret){
		global $db;
		$ret_x = array(
            'is_four_order'=>true,

            'bill_no' => '',
            'sort' => 1,
            'item' => '',
            'service' => 'usage_ip_ports',
            'id_service' => 2945,
            'date_from' => '',
            'date_to' => '',
            'type' => 'service',
            'id' => '',
            'ts_from' => '',
            'ts_to' => '',
            'tax'=>0,
            'country_id' => 0,
            'okvd_code' => 0,
            'okvd' => "",
			'amount' => 1,
			'price' => '',
			'outprice' => '',
            'tax_type_id' => null,
			'sum' => '',
            'sum_without_tax' => 0,
            'sum_tax' => 0,
        );

		$query = "
			SELECT
				sum(`subq`.`sum`) `sum`,
				`subq`.`type`
			FROM
				(
					SELECT
						IFNULL(`np`.`sum`,`nb`.`sum`) `sum`,
						IF(IFNULL(`np`.`sum`,false),'PAY','BILL') `type`
					FROM
						`newbills` `nb`
					LEFT JOIN
						`newpayments` `np`
					ON
						`np`.`bill_no` = `nb`.`bill_no`
					WHERE
						`nb`.`bill_no` = '".$this->bill_no."'
				) subq
			GROUP BY
				`subq`.`type`
		";

		$db->Query($query);
		$pay = $db->NextRecord(MYSQLI_ASSOC);
		if($pay && $pay['type'] == 'PAY'){

            $tax_rate = $this->Client()->getTaxRate();
            $ret_x['tax_rate'] = $tax_rate;

			$ret_x['sum'] = $pay['sum'];
			$ret_x['sum_tax'] = $pay['sum'] * $tax_rate / (100 + $tax_rate);
		}

		foreach($ret as $key=>&$item){
			if($item['outprice']>0 && preg_match('/^\s*Абонентская\s+плата|^\s*Поддержка\s+почтового\s+ящика|^\s*Виртуальная\s+АТС|^\s*Перенос|^\s*Выезд|^\s*Сервисное\s+обслуживание|^\s*Хостинг|^\s*Подключение|^\s*Внутренняя\s+линия|^\s*Абонентское\s+обслуживание|^\s*Услуга\s+доставки|^\s*Виртуальный\s+почтовый|^\s*Размещение\s+сервера|^\s*Настройка[0-9a-zA-Zа-яА-Я]+АТС|^Дополнительный\sIP[\s\-]адрес|^Поддержка\sпервичного\sDNS|^Поддержка\sвторичного\sDNS|^Аванс\sза\sподключение\sинтернет-канала|^Администрирование\sсервер|^Обслуживание\sрабочей\sстанции|^Оптимизация\sсайта|^Неснижаемый\sостаток/',$item['item'])){
				//$item['item']=&$item['2'];
				$item['item'] = str_replace('Абонентская','абонентскую',str_replace('плата','плату',$item['item']));
				$item['item'] = str_replace('Поддержка','поддержку',$item['item']);
				$item['item'] = str_replace('Виртуальная','виртуальную',$item['item']);
				$item['item'] = str_replace('Перенос','перенос',$item['item']);
				$item['item'] = str_replace('Выезд','выезд',$item['item']);
				$item['item'] = str_replace('Сервисное','сервисное',$item['item']);
				$item['item'] = str_replace('Хостинг','хостинг',$item['item']);
				$item['item'] = str_replace('Подключение','подключение',$item['item']);
				$item['item'] = str_replace('Внутренняя линия','внутреннюю линию',$item['item']);
				$item['item'] = str_replace('Услуга','услугу',$item['item']);
				$item['item'] = str_replace('Виртуальный','виртуальный',$item['item']);
				$item['item'] = str_replace('Размещение','размещение',$item['item']);
				$item['item'] = str_replace('Аванс за','',$item['item']);
				$item['item'] = str_replace('Оптимизация','оптимизацию',$item['item']);
				$item['item'] = str_replace('Обслуживание','обслуживание',$item['item']);
				$item['item'] = str_replace('Администрирование','администрирование',$item['item']);

				$item['item'] = 'Авансовый платеж за '.$item['item'];

				$ret_x['item'] .= $item['item'].";<br />";
				$ret_x['bill_no'] = $item['bill_no'];
				$ret_x['date_from'] = $item['date_from'];
				$ret_x['date_to'] = $item['date_to'];
				$ret_x['id'] = $item['id'];
				$ret_x['ts_from'] = $item['ts_from'];
				$ret_x['ts_to'] = $item['ts_to'];

				if($pay['type'] == 'BILL'){
					$ret_x['sum'] += $item['sum'];
					$ret_x['sum_tax']  += $item['sum_tax'];
                }
            }else{
				if($pay['type'] == 'PAY' && $item['type']<>'zalog'){
					$ret_x['sum']-=$item['sum'];
					$ret_x['sum_tax'] -=$item['sum_tax'];
				}
				if($item['type']=='zadatok'){
					$ret_x['sum']+=$item['sum'];
					$ret_x['sum_tax'] +=$item['sum_tax'];
				}
				unset($ret[$key]);
			}
		}

		if($ret_x['sum']<0)
			$this->negative_balance = true;

		$ret = array($ret_x);
	}
	public function refactLinesWithOrder(&$ret){
		foreach($ret as &$item){
			$date = explode("-",$item['date_from']);
			$now = (int)date('Ym');
			$bda = (int)date('Ym',mktime(0, 0, 0, $date[1], $date[2], $date[0]));
			if($now <= $bda && preg_match('/^\s*Абонентская\s+плата|^\s*Поддержка\s+почтового\s+ящика|^\s*Виртуальная\s+АТС/',$item['item'])){
				//$item['item']=&$item['2'];
				$item['item'] = str_replace('Абонентская','абонентскую',str_replace('плата','плату',$item['item']));
				$item['item'] = str_replace('Поддержка','поддержку',$item['item']);
				$item['item'] = str_replace('Виртуальная','виртуальную',$item['item']);
				$item['item'] = 'Авансовый платеж за '.$item['item'];
			}
		}
	}

	public function GetLines($mode=false){
		global $db;

		$ret =
			$db->AllRecords($q='
				select
					nl.*,
					art,
					sort as id,
					UNIX_TIMESTAMP(date_from) as ts_from,
					UNIX_TIMESTAMP(date_to) as ts_to,
					g.num_id,
					store,
					service, 
					if(nl.service="usage_extra", 
						(select 
							okvd_code 
						from 
							usage_extra u, tarifs_extra t 
						where 
							u.id = nl.id_service and 
							t.id = tarif_id 
						), 
						if (nl.type = "good", 
							(select 
								okei 
							FROM
								g_unit
							WHERE 
								id = g.unit_id
							), "")
					) okvd_code
				from
					newbill_lines nl
				left join 
					g_goods g on (nl.item_id = g.id) 
				LEFT JOIN 
					g_unit as gu ON g.unit_id = gu.id
				where
					bill_no="'.$this->bill_no.'"
				order by
					sort
				','id');

        foreach($ret as &$r)
        {
            $r['amount'] = (float)$r['amount'];
            $r['price'] = (float)$r['price'];

            $r['outprice'] =
                !$this->bill['tax_rate']
                    ? round($r['sum'] / $r['amount'], 4)
                    : round($r['sum_without_tax'] / $r['amount'], 4);


            $r["country_name"] = Country::dao()->getNameByCode($r["country_id"]);

            // если услуга, прописанная через 1с, услуга с датой. Для выписки документов (акт1)
            if($r["service"] == "1C" && $r["type"] == "service")
            {
                $billDate = $this->getShipmentDate();
                if(!$billDate)
                    $billDate = strtotime($this->bill["bill_date"]);

                $r["ts_from"] = strtotime("- ".(date("d", $billDate)-1)." days", $billDate);
                $r["ts_to"] = strtotime("+1 month -1 day", $r["ts_from"]);
            }
        }

		if($mode !== false){
			switch($mode){
				case 'order':{//каст для счета
					$this->refactLinesWithOrder($ret);
					break;
				}
			}
		}

		return $ret;
	}
	public function __destruct() {
		$this->Save(0,1);
	}
	public function GetMaxSort() {
		return $this->max_sort;
	}

	public function CheckForAdmin($doTrigger = true) {
		global $db,$user;
		$A = date('Ym');
		$B = substr($this->bill_no.'000000',0,6);
		if ($B>=$A) return true;
		if (!access('newaccounts_bills','admin')) return false;
		return true;
	}

    public function SetDocDate($utDate)
    {
        global $db;

        if($utDate)
        {
            $wDate = date("Y-m-d", $utDate);
        }else{
            $wDate = "0000-00-00";
        }

        \app\models\EventQueue::go(\app\models\EventQueue::SET_GOOD_BILL_DATE, ['bill_no' => $this->bill_no]);

        $db->QueryUpdate("newbills", "bill_no", array(
                    "bill_no" => $this->bill_no,
                    "doc_date" => $wDate));

        $this->bill["doc_date"] = $wDate;
        $this->bill["doc_ts"] = $utDate;
    }

    public function is1CBill()
    {
        return (bool)preg_match("/20\d{4}\/\d{4,6}/", $this->bill_no);
    }

    public function getShipmentDate()
    {
        global $db;

        if(!$this->is1CBill() && !$this->isOneTimeService()) return false;

        if($this->bill["doc_ts"]) return $this->bill["doc_ts"];

        return $db->GetValue("
                     SELECT 
                        unix_timestamp(min(cast(date_start as date)))
                     FROM 
                        tt_troubles t , `tt_stages` s  
                     WHERE 
                            t.bill_no = '".$this->bill_no."'
                        and t.id = s.trouble_id 
                        and state_id in (select id from tt_states where state_1c = 'Отгружен')
                     ");
    }

    public function isOneTimeService()
    {
        global $db;

        $ls = $db->AllRecords("select type, service, id_service from newbill_lines where bill_no='".$this->bill_no."'");

        return array_reduce($ls, fn($carry, $l) => $carry && $l["type"] == "service" && $l["id_service"] == 0 && $l["service"] == "", count($ls) > 0);
    }

    public function isOneZadatok()
    {
        global $db;

        $ls = $db->AllRecords("select type, service, id_service from newbill_lines where bill_no='".$this->bill_no."'");

        return array_reduce($ls, fn($carry, $l) => $carry && $l["type"] == "zadatok", count($ls) > 0);
    }

    public static function getPreBillAmount($client_id)
    {
        global $db;
        if (!$client_id) 
        {
            return 0;
        }
        $client_data = $db->GetRow('
            SELECT * 
            FROM clients 
            WHERE 
            id = ' . $client_id
        );
        if (empty($client_data)) 
        {
            return 0;
        }
        $services = get_all_services($client_data['client'],$client_id);

        $time_from = strtotime('first day of next month 00:00:00');
        $time_to = strtotime('last day of next month 23:59:59');
        $tax_rate = ClientAccount::findOne($client_id)->getTaxRate();
        $nds = 1 + $tax_rate/100;
        $R = 0;
        foreach ($services as $service){
            if((unix_timestamp($service['actual_from']) > $time_to || unix_timestamp($service['actual_to']) < $time_from))
            {
                continue;
            }
            $s=ServiceFactory::Get($service,$client_data);

            $s->SetMonth($time_from);

            $R+=round($s->getServicePreBillAmount()*$nds, 2);
        }

        return $R;
    }
    //------------------------------------------------------------------------------------

    /**
     * Функция подготовки линий для добавления в счет. Складывает однотипные позиций.
     *
     * @param array входйщий спосок позиций счета
     * @return array сгруппированный список
     */
    public function CombineRows($inR)
    {
        $tmpR = [];
        foreach($inR as $rs)
        {
            foreach($rs as $r)
            {
                $key = $r[1]."|".$r[3]."|".$r[7]."|".$r[8]; //складываем только одинаковые услуги в рамках периода
                $tmpR[$key][] = $r;
            }
        }

        $outR = [];
        foreach($tmpR as $item => $rs)
        {
            if (count($rs) > 1)
            {
                $line = null;
                foreach($rs as $r)
                {
                    if (!$line)
                    {
                        $line = $r;
                    } else {
                        $line[2] += $r[2];
                    }
                }
                $outR[] = [$line];
            } else {
                $outR[] = $rs;
            }
        }

        return $outR;
    }

    /**
     * Платежи для отображения в счете-фактуре
     *
     * @return array
     */
    public function getInvoicePayments()
    {
        return \app\models\Bill::dao()->getInvoicePayments($this->bill_no);
    }
}
?>
