<?php

declare(strict_types=1);

namespace App\Monitoring;

/**
 * How bad a monitored state is.
 *
 * Two levels, deliberately, and they mirror what the health probe already
 * distinguishes: `degraded` means the instance still serves requests and
 * somebody should look today, `down` means something the system needs is gone.
 * A third level would only invite arguing about which one a case belongs to.
 *
 * The ordering exists for one reason — a state that gets worse while it is
 * already being announced is a new thing to say. See
 * {@see SystemMonitor} for where that is applied.
 */
enum AlertSeverity: string
{
    case WARNING = 'warning';
    case CRITICAL = 'critical';

    public function outranks(self $other): bool
    {
        return self::CRITICAL === $this && self::WARNING === $other;
    }
}
