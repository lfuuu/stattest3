<?php

return [

    // Base biller
    'date_once' => ', {0, date, dd}',

    'date_range_month' => ' с{0, date, dd} по {1, date, dd MMMM}',
    'date_range_year' => ' с{0, date, dd MMMM} по {1, date, dd MMMM yyyy} г.',
    'date_range_full' => ' с{0, date, dd MMMM yyyy} г. по {1, date, dd MMMM yyyy} г.',


    'by_agreement' => ', согласно Договора {contract_no} от {contract_date,date,dd MMMM yyyy} г.',

    // SMS
    'sms_service' => 'СМС рассылка, {tariff}{date_range}',
    'sms_monthly_fee' => 'Абонентская плата за СМС рассылки, {tariff}',

    // E-mail
    'email_service' => 'Поддержка почтового ящика {local_part}@{domain}{date_range}{by_agreement}',

    // Extra
    'extra_service' => '{tariff}{date_range}{by_agreement}',
    'extra_service_itpark' => 'по Договору {contract} от {contract_date, date, dd MMMM yyyy} г.',

    // Welltime
    'welltime_service' => '{tariff}{date_range}',

    // VPBX
    'vpbx_service' => 'ВАТС {tariff}{date_range}',
    'vpbx_over_disk_usage' => 'Превышение дискового пространства{date_range}',
    'vpbx_over_ports_count' => 'Превышение количества портов{date_range}',
    'vpbx_over_ext_did_count' => 'Абонентская плата за услугу подключения номера стороннего оператора{date_range}',

    //Call_chat
    'call_chat_service' => '{tariff}{date_range}',

    'test' => '',

    'Replenishment of the account {account} for the amount of {sum} {currency}' => 'Пополнение лицевого счета {account} на сумму {sum} {currency}',
    'RUB' => 'руб.',
    'HUF' => 'Ft',
    'USD' => '$',

    'incomming_payment' => 'Авансовый платеж за услуги связи',
    'partner_reward' => 'Агентское вознаграждение {date_range_month}',

    'Communications services contract #{contract_number}' => 'Услуги связи по договору №{contract_number}',
    'Month' => 'Месяц',

    'nal' => 'наличные',
    'beznal' => 'банковский перевод',

    'correct_sum' => 'Расход предыдущего периода',
    'Current statement' => 'Текущая выписка',
    'invoice' => 'Счет-фактура',
    'act' => 'Aкт',
    'upd' => 'УПД',
    'upd2' => 'УПД',
];
