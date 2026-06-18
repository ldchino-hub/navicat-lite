#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Run due scheduled jobs. Example crontab (every minute):
 * * * * * * cd /path/to/navicat-php-1.0.0 && php scripts/scheduler-worker.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Navicat\Services\SchedulerService;

$result = SchedulerService::tick('cli');
fwrite(STDOUT, gmdate('c') . " scheduler tick: {$result['ran']} job(s) executed"
    . (($result['skipped'] ?? false) ? ' (skipped — lock held)' : '') . "\n");
