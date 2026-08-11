<?php

declare(strict_types=1);

namespace App\Monitoring;

use DateTimeImmutable;

/**
 * An alert that has been announced and is still standing, as remembered between
 * two runs of the monitor.
 *
 * The title travels with it because a recovery has to name what recovered, and
 * by then the probe no longer reports the state at all.
 */
final readonly class StoredAlert
{
    public function __construct(
        public string $key,
        public AlertSeverity $severity,
        public string $title,
        public DateTimeImmutable $since,
    ) {
    }
}
