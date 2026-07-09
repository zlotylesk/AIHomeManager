<?php

declare(strict_types=1);

namespace App\Module\Goals\Domain\Port;

use App\Module\Goals\Domain\ReadModel\ActivityEvent;
use DateTimeImmutable;

/**
 * Reads the product-wide activity stream that goals and streaks are computed
 * from, without the Goals module coupling to any source module's Domain or
 * Persistence. Infrastructure adapters back it per source module.
 */
interface ActivityProviderInterface
{
    /**
     * Normalized activity events recorded within the inclusive [$from, $to]
     * window across every source module.
     *
     * @return ActivityEvent[]
     */
    public function activityBetween(DateTimeImmutable $from, DateTimeImmutable $to): array;
}
