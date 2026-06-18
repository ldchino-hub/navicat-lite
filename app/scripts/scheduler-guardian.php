#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Internal 24/7 scheduler guardian (no OS cron).
 * Started by docker-entrypoint, PM2, or: php scripts/scheduler-guardian.php
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Navicat\Services\SchedulerService;

$interval = SchedulerService::tickIntervalSeconds();
fwrite(STDOUT, gmdate('c') . " scheduler-guardian started (interval {$interval}s)\n");

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, static function (): void {
        fwrite(STDOUT, gmdate('c') . " scheduler-guardian SIGTERM\n");
        exit(0);
    });
}

while (true) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
    $result = SchedulerService::tick('guardian');
    if (!($result['skipped'] ?? false)) {
        fwrite(STDOUT, gmdate('c') . " tick ran={$result['ran']} source={$result['source']}\n");
    }
    sleep($interval);
}
