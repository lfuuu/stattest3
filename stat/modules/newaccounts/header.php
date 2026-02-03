<?php

class m_newaccounts_head extends IModuleHead{
	public $module_name = 'newaccounts';
	public $module_title = 'Бухгалтерия';

	public $actions=array(
					'bill_list'			=> array('newaccounts_bills','read'),
					'bill_create_income'		=> array('newaccounts_bills','edit'),
					'bill_create_outcome'		=> array('newaccounts_bills','edit'),
					'bill_create_correction'		=> array('newaccounts_bills','edit'),
					'bill_view'			=> array('newaccounts_bills','read'),
					'bill_edit'			=> array('newaccounts_bills','edit'),
					'bill_add'			=> array('newaccounts_bills','edit'),	//добавление чего-нибудь в счета, типа там "всех подключений" или "абонплата" или "залог"
					'bill_apply'		=> array('newaccounts_bills','edit'),
					'bill_comment'		=> array('newaccounts_bills','edit'),
					'bill_nal'			=> array('newaccounts_bills','edit'),
					'bill_delete'		=> array('newaccounts_bills','delete'),
					'bill_print'		=> array('newaccounts_bills','read'),
					'bill_mprint'		=> array('newaccounts_bills','read'),
					'bill_email'		=> array('newaccounts_bills','read'),
					'bill_postreg'		=> array('newaccounts_bills','edit'),
					'line_delete'		=> array('newaccounts_bills','edit'),
					'bill_clear'		=> array('newaccounts_bills','edit'),
					'bill_cleared'		=> array('newaccounts_bills','edit'),
					'bill_courier_comment'		=> array('newaccounts_bills','edit'),
					'bill_calculate_rewards' => array('newaccounts_bills','read'),

					'search'			=> array('newaccounts_bills','read'),

					'bill_balance'		=> array('newaccounts_bills','read'),
					'bill_balance_minus' => array('newaccounts_bills','read'),

					'saldo'				=> array('newaccounts_bills','edit'),

					'bill_balance_mass'	=> array('newaccounts_mass','access'),

					'bill_mass'			=> array('newaccounts_mass','access'),
					'bill_publish'		=> array('newaccounts_mass','access'),

					'pi_list'			=> array('newaccounts_import_payments','read'),
					'pi_upload'			=> array('newaccounts_import_payments','write'),
					'pi_process'		=> array('newaccounts_import_payments','read'),
					'pi_apply'			=> array('newaccounts_import_payments','write'),

					'pay_rebill'		=> array('newaccounts_payments','edit'),

					'balance_client'	=> array('newaccounts_balance','read'),
					'balance_bill'		=> array('newaccounts_balance','read'),
					'balance_bill_new'		=> array('newaccounts_balance','read'),
					'balance_check'		=> array('newaccounts_balance','read'),
					'balance_sell'		=> array('newaccounts_balance','read'),
					'first_pay'			=> array('newaccounts_balance','read'),

					'pay_report'		=> array('newaccounts_payments','read'),
					'debt_report'		=> array('newaccounts_payments','read'),
					'usd'				=> array('newaccounts_usd','access'),
					'postreg_report'	=> array('newaccounts_bills','read'),
					'postreg_report_do'	=> array('newaccounts_bills','read'),

					'bill_data'			=> array('newaccounts_bills','read'),
					'make_1c_bill' 		=> array('clients', 'all4net'),
					'rpc_findProduct' 	=> array('clients', 'all4net'),

					'pay_ym'			=> array('newaccounts_bills','edit'),
					'docs'			=> array('newaccounts_bills','read'),
					'docs_unrec'			=> array('newaccounts_bills','read'),
					'doc_file'			=> array('newaccounts_bills','read'),
	                'doc_file_delete'	=> array('newaccounts_bills','del_docs'),
	                'show_income_goods'	=> array('newaccounts_bills','read'),
	                'bill_list_filter'	=> array('newaccounts_bills','read'),
	                'ext_bills'	        => array('newaccounts_bills','read'),
		            'ext_bills_ifns'    => array('newaccounts_bills','read'),
		            'bill_ext_file_get' => array('newaccounts_bills','read'),
		            'recalc_entry'      => array('newaccounts_bills','edit'),
		            'create_draft'      => array('newaccounts_bills','edit'),

				);
	public $menu;

    public function __construct()
    {
        $this->menu = array(
            array('Счета',				function() {
                global $fixclient_data;
                if (Yii::$app->user->can('newaccounts_balance.read') && $fixclient_data) {
                    return 'module=' . $this->module_name . '&action=bill_list';
                }
            }),
			array('Счета 2.0',				function() {
                global $fixclient_data;
                if (Yii::$app->user->can('newaccounts_balance.read') && $fixclient_data) {
                    return '/accounting/';
                }
            }),
            array('Акт сверки',			function() {
                global $fixclient_data;
                if (Yii::$app->user->can('newaccounts_balance.read') && $fixclient_data) {
                    return 'module=' . $this->module_name . '&action=balance_check';
                }
            }),
            array('Акт сверки (новый)',			function() {
                global $fixclient_data;
                if (Yii::$app->user->can('newaccounts_balance.read') && $fixclient_data) {
                    return '/report/accounting/pay-report/revise';
                }
            }),
            array('Внести платёж',		function() {
                global $fixclient_data;
                if (Yii::$app->user->can('newaccounts_payments.edit') && $fixclient_data) {
                    return ['payment/add', 'clientAccountId' => $fixclient_data['id']];
                }
            }),
            array('',					'balance_client'),
            array('Баланс по клиентам',	'balance_client'),
            array('Баланс по счетам',	'balance_bill'),
            array('Первые платежи',		'first_pay'),
//            array('Курс доллара (устарело)',		'usd'),
            array('Курс валюты', function() { return '/bill/currency/index/'; }),
            array('',					'pi_list'),
            array('Импорт платежей',	'pi_list'),
            array('Массовые счета', function() {
                if (Yii::$app->user->can('newaccounts_mass.access')) {
                    return ['/bill/publish/index'];
                }
            }),
            array('Книга продаж',		'balance_sell'),
            array('Книга продаж (новая)',		function() {
                if (Yii::$app->user->can('newaccounts_balance.read')) {
                    return ['/report/accounting/sale-book/'];
                }
            }),
            array('Отчёт по долгам',	'debt_report'),
            array('Почтовый реестр',	'postreg_report'),
            array('',					'pi_list'),
            array('Документы: история',	'docs'),
            array('Документы: Нераспознанное',	'docs_unrec'),
            array('Отчёт по платежам. Клиенты.', 'pay_report'),
            array('Европейская книга покупок', 'ext_bills'),
			array('Книга покупок (ИФНС)', 'ext_bills_ifns')
        );

        parent::__construct(); // TODO: Change the autogenerated stub
    }

}
?>
