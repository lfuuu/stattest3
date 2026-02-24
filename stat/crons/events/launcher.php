<?php

/**
 * Динамический запуск воркеров очереди событий.
 *
 * Проверяет размер очереди и запускает нужное количество воркеров (от 1 до MAX).
 * Использование: php launcher.php <группа>
 * Поддерживаемые группы: kafka, kafka_low, uu_sync
 */

use app\helpers\DateTimeZoneHelper;
use app\models\EventQueue;
use app\models\Param;

define('NO_WEB', 1);
define('PATH_TO_ROOT', '../../');
require PATH_TO_ROOT . 'conf_yii.php';

// конфигурация групп очереди событий
require __DIR__ . '/config.php';

const MAX_WORKERS = 10;

// базовый порог: при достижении base запускается 2-й воркер, далее экспоненциально (x2 на каждый следующий)
// workers = min(MAX, max(1, 1 + floor(log2(queueSize / base))))
$baseThresholds = [
    'with_account_tariff' => 10,   // 10, 20, 40, 80, 160, 320, 640, 1280, 2560 → 2..10
    'kafka'               => 25,   // 25, 50, 100, 200, 400, 800, 1600, 3200, 6400 → 2..10
    'kafka_low'           => 50,   // 50, 100, 200, 400, 800, 1600, 3200, 6400, 12800 → 2..10
    'uu_sync'             => 100,  // 100, 200, 400, 800, 1600, 3200, 6400, 12800, 25600 → 2..10
];

$group = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : null;

if (!$group || !isset($baseThresholds[$group]) || !isset($map[$group])) {
    echo PHP_EOL . 'Использование: php launcher.php <' . implode('|', array_keys($baseThresholds)) . '>';
    echo PHP_EOL;
    exit(1);
}

// считаем количество pending-событий для группы
$query = EventQueue::getPlannedQuery();
foreach ($map[$group] as $where) {
    $query->andWhere($where);
}
$queueSize = $query->count();

// определяем количество воркеров экспоненциально
$base = $baseThresholds[$group];
if ($queueSize < $base) {
    $workersNeeded = 1;
} else {
    $workersNeeded = min(MAX_WORKERS, 1 + (int)floor(log($queueSize / $base, 2)) + 1);
}

echo sprintf(
    '%s, launcher [%s]: очередь=%d, воркеров=%d',
    date(DateTimeZoneHelper::DATETIME_FORMAT),
    $group,
    $queueSize,
    $workersNeeded
) . PHP_EOL;

// записываем N в БД — воркеры читают его каждую итерацию
Param::setParam("handler_{$group}_total", $workersNeeded, true);

// запускаем воркеры (argv[2] = индекс воркера, N берётся из БД)
$scriptDir = __DIR__;
for ($i = 1; $i <= $workersNeeded; $i++) {
    $lockFile = "/tmp/handler_{$group}_{$i}";
    $cmd = sprintf(
        'flock --nonblock %s php %s/handler.php %s %d >> /var/log/nispd/handler_%s.log 2>&1 &',
        escapeshellarg($lockFile),
        escapeshellarg($scriptDir),
        escapeshellarg($group),
        $i,
        $group
    );
    exec($cmd);
}
