<?php
class m_stats_head extends IModuleHead{
	public $module_name = 'stats';
	public $module_title = 'Статистика';
	var $actions=array(
		'default'			=> array('stats','r'),
		'internet'			=> array('stats','r'),
		'ppp'				=> array('stats','r'),
		'voip'				=> array('stats','r'),
		'voip_profit'       => array('stats','r'),
		'voip_profit_new'   => array('stats','r'),
		'callback'			=> array('stats','r'),
		'send_view'			=> array('stats','report'),
		'send_process'		=> array('stats','report'),
		'send_add'			=> array('stats','report'),
		'report_sms_gate'	=> array('stats','report'),
		'report_voip_e164_free' => array('stats','report'),
		'report_services'	=> array('stats','report'),
		'report_wimax'		=> array('stats','report'),
		//'report_netbynet'	=> array('stats','report'),
		//'report_onlime'	    => array('stats','onlime_read'),
		//'report_onlime2'	    => array('stats','onlime_create'),
		//'report_onlime_all'	    => array('stats','onlime_full'),
		'report_inn'	    => array('stats','report'),
		'courier_sms'		=> array('stats','report'),
		'report_voip_operators_traf' => array('stats','vip_report'),
		'support_efficiency'	=>  array('stats','report'),
		'report_phone_sales'  =>  array('stats','report'),
		//'report_agent' => array('stats','report'),
		'report_sale_channel' => array('stats','sale_channel_report'),
		'report_vpbx_stat_space' => array('stats', 'report'),
		'phone_sales_details' => array('stats','report'),
        'agent_settings' => array('stats', 'vip_report'),
        'save_agent_settings' => array('stats', 'vip_report'),
        'report_agent_details' => array('stats','vip_report'),
		'onlime_details'	    => array('stats','report'),
		'ip' => array('stats','report'),
	);

    var $menu=[];

    public function __construct()
    {
        $this->menu = array(
            array('Телефония',              'voip'),
            array('Телефония (Маржа)',      'voip_profit'),
            array('Телефония (Маржа), новая',      'voip_profit_new'),
            array('Телефония Пакеты',       function() { return '/report/voip-package/use-report'; }),
            array('ВАТС', 'report_vpbx_stat_space'),
            array('Интернет',		'internet'),
            array('Collocation',	'internet','&is_coll=1'),
            array('PPP',			'ppp'),
            array('Callback',		'callback'),
            array('VPN',			'vpn'),
            array('Рассылка',		'send_view'),
            array('Свободные номера', 'report_voip_e164_free'),
            array('SMS Gate',		'report_sms_gate'),
            array('Отчет по услугам', 'report_services'),
            //array('Отчёт по файлам',  function() {return '/file/report';}),
            array('Отчет по WiMax', 'report_wimax'),
            array('Отчет по Курьерам(SMS)', 'courier_sms'),
            array('Отчет по ТехПоддержке', 'support_efficiency'),
            //array('Отчет по NetByNet', 'report_netbynet'),
            //array('Отчет по OnLime', 'report_onlime'),
            //array('Отчет по OnLime2', 'report_onlime2'),
            //array('Отчет по OnLime 1+2', 'report_onlime_all'),
            array('Отчет: Продажи номеров', 'report_phone_sales'),

            array('Отчет: ИНН', 'report_inn'),
            # TODO: удалить отчеты в мае 2018 года
            //array('Отчет по Агентам (старый)', 'report_agent'),
            //array('Отчет по партнерам', function(){return '/stats/agent/report';}),
            //array('Вознаграждения партнеров', function(){ return '/stats/partner-rewards'; }),
            array('Вознаграждения партнеров', function(){ return '/stats/partner-rewards?isExtends=1'; }),
            array('Вознаграждения партнеров v2', function(){ return '/stats/partner-rewards-new'; }),
            array('МАВ', function(){ return '/stats/mav'; }),
            array('Настройка агента', 'agent_settings'),
            array('Региональные представители', 'report_sale_channel'),
            array('Статистика: звонки-IP', 'ip'),
        );
    }
}


