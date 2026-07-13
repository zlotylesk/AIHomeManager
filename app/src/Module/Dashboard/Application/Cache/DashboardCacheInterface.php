<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Application\Cache;

use App\Module\Dashboard\Application\DTO\DashboardDTO;
use DateTimeImmutable;

/**
 * Application-side port for caching the composed cockpit read model (HMAI-263).
 * Lives in Application (not Domain) because it deals in the {@see DashboardDTO}
 * read model; the Redis implementation sits in Infrastructure. Keeping it a port
 * lets the query handler stay Infrastructure-agnostic (Application must not depend
 * on Infrastructure).
 */
interface DashboardCacheInterface
{
    /**
     * Returns the cached cockpit for the given day, computing and storing it on a
     * miss. The reference day scopes the key so a new day starts fresh.
     *
     * @param callable(): DashboardDTO $compute
     */
    public function remember(DateTimeImmutable $day, callable $compute): DashboardDTO;

    /**
     * Drops the cached cockpit for the given day (freshness beyond the TTL bound).
     */
    public function invalidate(DateTimeImmutable $day): void;
}
