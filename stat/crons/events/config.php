<?php

/**
 * Конфигурация групп очереди событий.
 * Используется в handler.php и launcher.php.
 */

use app\models\EventQueue;
use app\modules\nnp\Module as NnpModule;
use app\modules\uu\Module as UuModule;

// настраиваем запрос выборки событий
$nnpEvents = ['event' => [
    NnpModule::EVENT_FILTER_TO_PREFIX,
    NnpModule::EVENT_LINKER,
    NnpModule::EVENT_EXAMPLES,
    NnpModule::EVENT_IMPORT,
    NnpModule::EVENT_IMPORT_PREVIEW,
    EventQueue::INVOICE_MASS_CREATE,
//    EventQueue::INVOICE_GENERATE_PDF,
    EventQueue::INVOICE_ALL_PDF_CREATED,
    EventQueue::ADD_RESOURCE_ON_ACCOUNT_TARIFFS,
    EventQueue::UPDATE_BALANCE_MASS,
    EventQueue::KSIM_GET_STATISTIC,
]];

$syncEvents = ['event' => [
    EventQueue::ATS3__SYNC,
    EventQueue::MAKE_CALL,
    EventQueue::SYNC_1C_CLIENT,
    UuModule::EVENT_SIPTRUNK_SYNC,
    UuModule::EVENT_ROBOCALL_INTERNAL_CREATE,
    UuModule::EVENT_ROBOCALL_INTERNAL_REMOVE,
    EventQueue::DADATA_BIK,
    EventQueue::SYNC_TELE2_SET_GET_STATUS,
]];

$syncT2Events = ['event' => [
    EventQueue::SYNC_TELE2_GET_IMSI,
    EventQueue::SYNC_TELE2_LINK_IMSI,
    EventQueue::SYNC_TELE2_UNSET_IMSI,
    EventQueue::SYNC_TELE2_UNLINK_IMSI,
    EventQueue::SYNC_TELE2_GET_STATUS,
    EventQueue::SYNC_TELE2_SET_CFNRC,
    EventQueue::SYNC_TELE2_UNSET_CFNRC,
]];

$uuSyncEvents = ['event' => [
    UuModule::EVENT_ADD_LIGHT,
    UuModule::EVENT_CLOSE_LIGHT,
]];

$kafkaEvents = ['event' => [
    UuModule::EVENT_UU_ANONCE,
    UuModule::EVENT_UU_ANONCE_TARIFF,
    EventQueue::EVENT_LK_CONTRAGENT_CHANGED,
]];

$kafkaLowEvents = ['event' => [
    UuModule::EVENT_UU_ANONCE2,
    EventQueue::INVOICE_GENERATE_PDF,
]];


//$syncEvents['event'] = array_merge($syncEvents['event'], $uuSyncEvents['event']/*, $kafkaEvents*/);

$map = [
    'with_account_tariff' => [['NOT', ['account_tariff_id' => null]], ['NOT', $uuSyncEvents], ['NOT', $kafkaEvents], ['NOT', $kafkaLowEvents]], // account_tariff_id => not null =>> already ['NOT', $syncEvents] && ['NOT', $nnpEvents]
    'without_account_tariff' => [['account_tariff_id' => null], ['NOT', $nnpEvents], ['NOT', $syncEvents], ['NOT', $syncT2Events], ['NOT', $uuSyncEvents], ['NOT', $kafkaEvents], ['NOT', $kafkaLowEvents]],

    'kafka' => [$kafkaEvents], // kafka events
    'kafka_low' => [$kafkaLowEvents], // low priority kafka events (mass upload, mass generate)
    'uu_sync' => [$uuSyncEvents],
    'ats3_sync' => [$syncEvents], // all sync events
    'sync_t2' => [$syncT2Events], // all sync events
    'nnp' => [$nnpEvents],

    'no_nnp' => [['NOT', $nnpEvents]], //для служебного пользования
];

// базовый порог для launcher: при достижении base запускается 2-й воркер, далее экспоненциально (x2 на каждый следующий)
// для групп без явной настройки — порог 10
$baseThresholds = [
    'kafka'     => 25,
    'kafka_low' => 50,
    'uu_sync'   => 100,
];
