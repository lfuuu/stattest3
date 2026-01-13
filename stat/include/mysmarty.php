<?php
use app\classes\DateTimeWithUserTimezone;
use app\helpers\DateTimeZoneHelper;
use app\models\Currency;
use app\classes\Wordifier;
use app\classes\Utils;

require PATH_TO_ROOT.'libs/Smarty.class.php';

function __count_rows_func($params,&$smarty){
	if (isset($params['start'])) $c=$params['start']; else $c=1;
	foreach ($params as $k=>$p) if ($k!='start'){	//for out parameter
		$c+=count($p);
	}
	return $c;
}
function __count_comments($params,&$smarty){
	$c=0;
	foreach ($params['v']['pays'] as $k=>&$p) {
		$c++;
		if ($p['comment']) $c++;
	}
	$c = ($c==0)?++$c:$c;
//	if ($params['v']['bill']['comment']) $c--;
	$smarty->assign('rowspan',$c);
//	if (isset($l)) $smarty->assign('last_rowspan',$l);
	return '';
}

function __implode($params,&$smarty){
	return implode($params['sep'],$params['in']);
}

function __sort_link($params, &$smarty){
	$sort=$params['sort_cur'];
	$sort_d=$params['sort'];
	$so=$params['so_cur'];
	$link=$params['link'].(isset($params['link1']) ? $params['link1'] : "");
	for ($i=2;isset($params['link'.$i]);$i++) $link.=$params['link'.$i];
	if ($sort==$sort_d){
		$v = ($so==0 ? '&#8593;' : '&#8595;');
	} else {
		$v = '';		
	}
	$v.='<a href="'.$link.'&sort='.$sort_d.'&so='.($sort==$sort_d ? (1-$so) : $so).'">'.$params['text'].'</a>';
	return $v;
}

function __fsize($params,&$smarty){
	$v=$params['value'];
	$v=round($v/(1024*10.24))/100;
	$p=explode('.',$v);
	if (!isset($p[1]) || strlen($p[1])<1) $v.='.0';
	if (!isset($p[1]) || strlen($p[1])<2) $v.='0';
	return $v;
}
function __fsizeKB($params,&$smarty){
	$v=$params['value'];
	$v=round($v/10.24)/100;
	$p=explode('.',$v);
	if (strlen($p[1])<1) $v.='.0';
	if (strlen($p[1])<2) $v.='0';
	return $v.' Kb';
}

function __ipstat($params, &$smarty){
	$v=$params['net'];
	if(!$v)
		return '';
	if(!access('monitoring','view'))
		return $v;
	if(isset($params['color']))
		$c=' style="color:'.$params['color'].'"';
	else
		$c='';

	$data = isset($params['data'])
		  ? $params['data']
		  : array();

	$imgshow = 1;
	if(isset($data['actual']) && !$data['actual'])
		$imgshow=0;

	if(!preg_match("/(\d+)\.(\d+)\.(\d+)\.(\d+)(\/(\d+))?/",$v,$m))
		return '?';
	if(!isset($m[6]) || ($m[6]==32)){
		$R = array($ip="{$m[1]}.{$m[2]}.{$m[3]}.{$m[4]}");
		if($ip=="0.0.0.0")
			return "0.0.0.0";
	}else{
		$R = array("{$m[1]}.{$m[2]}.{$m[3]}.".($m[4]+1),"{$m[1]}.{$m[2]}.{$m[3]}.".($m[4]+2));
		$ip="{$m[1]}.{$m[2]}.{$m[3]}.{$m[4]}";
	}
	return '<table cellspacing=0 cellpadding=0 border=0><tr><td valign=middle>'.\app\classes\StatModule::monitoring()->get_image($R).'</td><td valign=middle'.$c.'>'.
					'<a href="?module=monitoring&ip='.$R[0].'"'.$c.'>'.$ip.'</a>'.
					(isset($R[1])?'/<a href="?module=monitoring&ip='.$R[1].'"'.$c.'>'.$m[6].'</a>':'').' </td></tr></table>';
}

function __my_prefilter($source, &$smarty) {
	$source=str_replace("\n"," ",$source);
	return str_replace("\r","",$source);
}

function __dbmap_prefilter($source,&$smarty) {
	$v='{if ($item.show)}
<TR><TD class=left{if ($hl==$key)} style="background-color: #EEE0B9; font-weight:bold"{/if}>{$item.translate.0}:</TD>
		<TD {if ($hl==$key)} style="background-color: #EEE0B9"{/if}>

{if ($item.show&1)==1}
	<input id=row_{$key} name="row[{$key}]" value="{$item.value}"> 
{/if}

{if ($item.show&2)==2}
	<SELECT id=row2_{$key} name="row[{$key}{if $item.show==3}_{/if}]">
		{foreach from=$item.variants item=i_item name=inner}
			<option value={$i_item.key}{if $item.value==$i_item.key} selected{/if}>{$i_item.show}</option>
		{/foreach}
		{if $item.show==3}
			<option value=nouse selected>использовать текстовое поле</option>
		{/if}
	</SELECT>
{/if}
{if ($item.show&4)==4}
	{$item.value}
	<input id=row_{$key} type=hidden name="row[{$key}]" value="{$item.value}">
{/if}
</TD><td>{$item.translate.1}</td></TR>

{else}
{if (!isset($HIDE_COMPLETELY))}
	<input id=row_{$key} type=hidden name="row[{$key}]" value="{$item.value}">
{/if}
{/if}';
	return str_replace("{dbmap_element}",$v,$source);
}

function __get_region_by_dgroups($params){
	if(isset($params['dgs'])){
		$tmp = explode(':',$params['dgs']);
		$g = $tmp[0];
		$s = $tmp[1];
		unset($tmp);
	}elseif(isset($params['dgroup'])){
		$g = $params['dgroup'];
		$s = $params['dsubgroup'];
	}

	$ret = '';
	if($g == 0){
		if($s == 0)
			$ret = 'Москва (моб)';
		elseif($s==1)
			$ret = 'Москва (стац)';
		elseif($s==96)
			$ret = 'Москва (абон)';
		else
			$ret = 'Москва (др)';
	}elseif($g == 1){
		if($s == 98)
			$ret = 'Россия Фрифон';
		else
			$ret = 'Россия';
	}else{
		if($s == 97)
			$ret = 'Международное Фрифон';
		else
			$ret = 'Международное';
	}
	return $ret;
}
function __get_minutes_by_seconds($params){
	return floor($params['sec']/60).':'.($params['sec']%60);
}

function __get_time($param)
{
    $v = $param["sec"];

    $sign = $v < 0 ? "-" :"";

    $v = abs($v);

    $sec = $v%60;
    $v -=$sec;

    $min = ($v%3600)/60;
    $v -=$min*60; 

    $hour = ($v%(3600*24))/3600;
    $v -= $hour*3600;

    $day = $v/(3600*24);

    return $sign.($day ? $day."d ":"").sprintf("%02d", $hour).":".sprintf("%02d", $min).":".sprintf("%02d", $sec);
}

function smarty_modifier_hl($string,$hl){
	if (!$hl) return $string;
	return preg_replace("/".preg_quote($hl)."/i","<span style='background-color:#D0D0FF; color:#000000'>$0</span>",$string);
}
function smarty_modifier_num_format($string, $with_zero = false, $after_dot = 0){
	if (!$string && !$with_zero)
	{
		return '';
	}
	$string = number_format($string, $after_dot, ',', ' ');
	$string = str_replace(' ', '&nbsp', $string);
	return $string;
}
function smarty_modifier_okei_name($string){
	$options = array();
	$options['select'] = 'name';
	$options['conditions'] = array('okei = ? AND name NOT LIKE ?', $string, '%del%');
	$res = GoodUnit::first($options);
	return $res->name;
}
//function smarty_modifier_round($value, $precision, $mode = ''){
//    return Utils::round($value, $precision, $mode = '');
//}
function smarty_modifier_mround($value, $precision1, $precision2){
    return Utils::mround($value, $precision1, $precision2);
}
function smarty_modifier_wordify($val,$curr) {
	return Wordifier::Make($val,$curr);
}
function smarty_modifier_mdate($value,$format) {
	return mdate($format,is_numeric($value)?$value:strtotime($value));
}

function smarty_modifier_udate_with_timezone($value, $format = 'Y-m-d H:i:s', $isShowTimezone = true) {
	return \app\helpers\DateTimeZoneHelper::getDateTime($value, $format, $isShowTimezone);
}

/**
 * @param string $date
 * @param string $showedTimezone
 * @param string $format
 * @return string
 */
function smarty_modifier_datetime_with_timezone($value, $showedTimezone, $format = 'Y-m-d H:i:s') {
    return
        (
        new DateTimeWithUserTimezone(
            $value,
            new DateTimeZone(DateTimeZoneHelper::TIMEZONE_DEFAULT)
        )
        )
            ->setTimezone(new DateTimeZone($showedTimezone))
            ->format($format);
}

function smarty_modifier_udate($value,$format = 'Y-m-d H:i:s') {
    $user_timezone = isset(Yii::$app->user->identity) ? Yii::$app->user->identity->timezone_name : 'UTC';

    if (is_numeric($value)) {
        $date = new DateTime('now');
        if ($value > 0) {
            $date->setTimestamp($value, new DateTimeZone($user_timezone));
        }
    } else {
        $date = new DateTime($value, new DateTimeZone($user_timezone));
    }

    return dateReplaceMonth($date->format($format), $date->format('m'));
}
/**
 * Smarty bytesize modifier plugin
 *
 * Type:     modifier<br>
 * Name:     bytesize<br>
 * 
 * @param int  $number        input value in bytes
 * @param string  $esc_type      escape type
 * 
 * @return string escaped input string
 */
function smarty_modifier_bytesize($number, $esc_type = 'Mb')
{
    static $st = 0;
    $sign = '';
    if ($number < 0)
    {
	$number = -$number;
	$sign = '-';
    }
    $step = array(
	'0' => 'b',
	'1' => 'Kb',
	'2' => 'Mb',
	'3' => 'Gb',
	'4' => 'Tb',
	'5' => 'Pb',
	'6' => 'Eb',
	'7' => 'Zb',
	'8' => 'Yb'
    );
    $obr_step = array_flip($step);
    if (!isset($obr_step[$esc_type])) {
	$esc_type = 'b';
    }
    $st = $obr_step[$esc_type];
    while ($number >= 1024) {
	$st++;
	$number = $number/1024;
	if ($st > 8) {
		break;
	}
    }
    if ($number >= 1000 && $st <= 7) {
	$st++;
	$number = $number/1024;
    }
    
    return $sign . round($number, 2) . ' ' . $step[$st];
}

function smarty_modifier_find_urls($text)
{
    $text = preg_replace('#(((f|ht)tps?):\/\/([a-zA-Z0-9.\/?=&\-%_;])+)#is', "<a target='_blank' href='\\1'>\\1</a>", $text);
    $text = preg_replace('#(([a-z0-9_-]+\.)*[a-z0-9_-]+@[a-z0-9_-]+(\.[a-z0-9_-]+)*\.[a-z]{2,6})#is', "<a target='_blank' href=\"http://thiamis.mcn.ru/welltime/?module=com_agent_panel&frame=new_msg&nav=mail.none.none&message=none&trunk=5&to=\\1\">\\1</a> (<a href=\"mailto:\\1\">@</a>)", $text);
    $text = preg_replace_callback('#(^|\s|\()(7[0-9]{10})(\s|$|\)|,)#m', function ($m) {
        $s = $m[1]; $e = $m[3];
        return $s . "<a target='_blank' href=\"/account/call-direct?phone=" . $m[2] . "\">" . $m[2] . "</a>" . $e;
    }, $text);

    return $text;
}

function smarty_function_objCurrency($params,&$smarty) {
	$op = &$params['op'];
	$obj = $params['obj'];

	if ($obj=='delta') {
		$curr = (isset($op['bill']) ? $op['bill']['currency'] : $params['currency']);
		$sum = $op['delta'];
		return sprintf("%0.2f", $sum) . ' ' . Currency::symbol($curr);
	} elseif ($obj=='delta2') {
		$curr = (isset($op['bill']) ? $op['bill']['currency'] : $params['currency']);
		$sum = $op['delta2'];
		return sprintf("%0.2f", $sum) . ' ' . Currency::symbol($curr);
	} elseif ($obj=='pay_full') {
		$sum = $params['pay']['sum'];
		$curr = isset($params['pay']['currency']) ? $params['pay']['currency'] : $params['currency'];
		return sprintf("%0.2f", $sum) . ' ' . Currency::symbol($curr);
	} elseif ($obj=='pay2') {
		$sum = $params['pay']['sum_pay'];
		$curr = isset($params['pay']['currency']) ? $params['pay']['currency'] : $params['currency'];
		return sprintf("%0.2f", $sum) . ' ' . Currency::symbol($curr);
	} elseif ($obj=='pay') {
		$sum = $params['pay']['sum'];
		$curr = $params['pay']['currency'];
		return sprintf("%0.2f", $sum) . ' ' . Currency::symbol($curr);
	}
}

function smarty_modifier_money($value, $currency, $round = 2) {
    return Utils::money($value, $currency, $round);
}

function smarty_modifier_rus_plural($value, $s1, $s2, $s3) {
    return Utils::rus_plural($value, $s1, $s2, $s3);
}

function smarty_modifier_moneyAndCurrency($value, $currency = 'RUB', $round = 2)
{
	return Utils::moneyAndCurrency($value, $currency, $round);
}

function smarty_modifier_usage_link($usageType, $usageId)
{
    switch ($usageType) {
        case 'emails':
            return \app\models\UsageEmails::findOne($usageId)->helper->editLink;
        case 'tech_cpe':
            return \app\models\UsageTechCpe::findOne($usageId)->helper->editLink;
        case 'usage_extra':
            return \app\models\UsageExtra::findOne($usageId)->helper->editLink;
        case 'usage_ip_ports':
            return \app\models\UsageIpPorts::findOne($usageId)->helper->editLink;
        case 'usage_sms':
            return \app\models\UsageSms::findOne($usageId)->helper->editLink;
        case 'usage_trunk':
            return \app\models\UsageTrunk::findOne($usageId)->helper->editLink;
        case 'usage_virtpbx':
            return \app\models\UsageVirtpbx::findOne($usageId)->helper->editLink;
        case 'usage_voip':
            return \app\models\UsageVoip::findOne($usageId)->helper->editLink;
        case 'usage_welltime':
            return \app\models\UsageWelltime::findOne($usageId)->helper->editLink;
        case 'usage_voip_package':
            return \app\models\UsageVoipPackage::findOne($usageId)->usageVoip->helper->editLink;
		case 'uu_account_tariff':
			return \yii\helpers\Url::to(['/uu/account-tariff/edit', 'id' => $usageId]);

        default:
            return 'javascript:void(0)';
    }
}

function smarty_modifier_client_options(\app\models\ClientAccount $client, $optionName)
{
    return (array) $client->getOption($optionName);
}

function smarty_modifier_currencySymbol($currency)
{
	return Currency::symbol($currency);
}

function smarty_date_full($date)
{
	if (!$date || $date == "0000-00-00 00:00:00") {
		return "";
	}

	if ($date instanceof DateTime) {
		$date = $date->getTimestamp();
	}

	return Yii::t('tariff', '{0,date,dd MMMM yyyy HH:mm:ss}', [(is_numeric($date) ? $date : strtotime($date))]);
}

class MySmarty extends SmartyStat {
	var $cid=0;
	var $LINK_START;
	var $ignore=0;
	function __construct(){
		global $G;
        $this->Smarty();
		$this->template_dir = DESIGN_PATH;
	   	$this->compile_dir  = DESIGNC_PATH;
		$this->compile_check = true;
		$this->debugging = (DEBUG_LEVEL>=3 ? true : false);
		$this->assign('_smarty_debug_output','html');
		$this->register_prefilter("__dbmap_prefilter");
//		if (DEBUG_LEVEL==0) $this->register_prefilter("__my_prefilter");
		$this->register_function('implode','__implode');
		$this->register_function('access','access');
		$this->register_function('access_action','access_action');
		$this->register_function('count_rows_func','__count_rows_func');
		$this->register_function('count_comments','__count_comments');
		$this->register_function('sort_link','__sort_link');
		$this->register_function('ipstat','__ipstat');
		$this->register_function('fsize','__fsize');
		$this->register_function('fsizeKB','__fsizeKB');
		$this->register_function('objCurrency','smarty_function_objCurrency');
		$this->register_function('get_region_by_dgroups','__get_region_by_dgroups');
		$this->register_function('get_minutes_by_seconds','__get_minutes_by_seconds');
		$this->register_function('get_time','__get_time');
		$this->register_modifier('money','smarty_modifier_money');
		$this->register_modifier('time_period','time_period');
		$this->register_modifier('hl','smarty_modifier_hl');
		$this->register_modifier('wordify', 'smarty_modifier_wordify');
//		$this->register_modifier('round','smarty_modifier_round');
		$this->register_modifier('mround','smarty_modifier_mround');
		$this->register_modifier('mdate','smarty_modifier_mdate');
        $this->register_modifier('udate','smarty_modifier_udate');
        $this->register_modifier('udate_with_timezone','smarty_modifier_udate_with_timezone');
        $this->register_modifier('datetime_with_timezone','smarty_modifier_datetime_with_timezone');
		$this->register_modifier('num_format','smarty_modifier_num_format');
		$this->register_modifier('okei_name','smarty_modifier_okei_name');
		$this->register_modifier('bytesize','smarty_modifier_bytesize');
        $this->register_modifier('find_urls','smarty_modifier_find_urls');
		$this->register_modifier('rus_fin','smarty_modifier_rus_plural');
		$this->register_modifier('usage_link','smarty_modifier_usage_link');
		$this->register_modifier('client_options', 'smarty_modifier_client_options');
		$this->register_modifier('money_currency', 'smarty_modifier_moneyAndCurrency');
		$this->register_modifier('currency_symbol', 'smarty_modifier_currencySymbol');
		$this->register_modifier('date_full', 'smarty_date_full');
		$this->assign('premain',array());
		$this->assign('WEB_PATH', WEB_ADDRESS . WEB_PATH);
		$this->assign('IMAGES_PATH',WEB_IMAGES_PATH);
		$this->assign('PATH_TO_ROOT',WEB_PATH);
		$this->assign('SUM_ADVANCE',SUM_ADVANCE);
		$this->LINK_START='index.php?';
		$this->assign_by_ref('LINK_START',$this->LINK_START);
	}
		
	function _add($item, $page, $parse_now){
		if ($this->ignore) return;
		if ($parse_now) {
			$this->append($item,array(0,$this->fetch($page)));
		} else {
			$this->append($item,array(1,$page));
		}
	}
	function AddMain($page, $parse_now = 0){
		$this->_add('main', $page, $parse_now);
	}
	function AddTop($page, $parse_now = 0){
		$this->_add('top', $page, $parse_now);
	}
	function AddPreMain($page, $parse_now = 0){
		$this->_add('premain', $page, $parse_now);
	}
	function Process(){
		if ($this->ignore) return;
		$this->display('index.tpl');
	}
	function ProcessEx($template=''){
		global $G;
		$this->ignore=1;
		if ($template) {
			$this->display($template);
            return (count(isset($G['errors']) ? $G['errors'] : []) + count(isset($G['notices']) ? $G['notices'] : []) ? 0 : 1);
		}
	}

    function var_is_array($name) {
	    if(isset($this->_tpl_vars[$name])) {
			return is_array($this->_tpl_vars[$name]);
	    } else return false;
    }

}
