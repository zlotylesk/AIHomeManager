<?php

declare(strict_types=1);

namespace App\Module\Insights\Application\Cache;

use App\Module\Insights\Application\DTO\TrendsDTO;
use App\Module\Insights\Application\Query\GetTrends;

/**
 * Application-side port for caching the composed trends read model (HMAI-334).
 * Lives in Application (not Domain) because it deals in the {@see TrendsDTO}
 * read model; the Redis implementation sits in Infrastructure. Keeping it a port
 * lets the query handler stay Infrastructure-agnostic — Application must not
 * depend on Infrastructure (the Dashboard HMAI-263 precedent).
 */
interface TrendsCacheInterface
{
    /**
     * Returns the cached trends for the query's window, computing and storing
     * them on a miss. The granularity and both window ends scope the key, so two
     * different ranges never share an entry.
     *
     * @param callable(): TrendsDTO $compute
     */
    public function remember(GetTrends $query, callable $compute): TrendsDTO;

    /**
     * Drops the cached entry for one window (freshness beyond the TTL bound).
     */
    public function invalidate(GetTrends $query): void;
}
