<?php

declare(strict_types=1);

namespace App\Monitoring;

use DateTimeImmutable;

/**
 * What a channel is asked to deliver: an alert plus the reason it is being
 * mentioned at this moment.
 *
 * Channels receive this rather than an {@see Alert} because a recovery has no
 * live alert behind it — the state it describes is precisely the one that
 * stopped being true.
 */
final readonly class AlertNotice
{
    public function __construct(
        public string $key,
        public AlertTransition $transition,
        public AlertSeverity $severity,
        public string $title,
        public string $detail,
        public DateTimeImmutable $at,
        public DateTimeImmutable $since,
    ) {
    }
}
