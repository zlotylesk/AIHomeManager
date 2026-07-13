<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Domain\ReadModel;

use DateTimeImmutable;

/**
 * A single task scheduled for the dashboard's "today" window, normalized from
 * the Tasks module. Only pending tasks reach the cockpit.
 */
final readonly class TodayTask
{
    public function __construct(
        public string $id,
        public string $title,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
    ) {
    }
}
