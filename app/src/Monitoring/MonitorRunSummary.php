<?php

declare(strict_types=1);

namespace App\Monitoring;

/**
 * What one pass of {@see SystemMonitor} did, for the console command and the
 * scheduled handler's log line. Nothing decides anything from this — it exists
 * so a human running the monitor by hand can see whether it found anything.
 */
final readonly class MonitorRunSummary
{
    /**
     * @param list<string> $announced keys an e-mail went out for this run
     * @param list<string> $standing  keys still recorded as failing afterwards
     */
    public function __construct(
        public array $announced,
        public array $standing,
    ) {
    }
}
