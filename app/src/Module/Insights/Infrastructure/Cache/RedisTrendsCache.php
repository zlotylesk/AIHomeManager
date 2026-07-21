<?php

declare(strict_types=1);

namespace App\Module\Insights\Infrastructure\Cache;

use App\Module\Insights\Application\Cache\TrendsCacheInterface;
use App\Module\Insights\Application\DTO\TrendsDTO;
use App\Module\Insights\Application\DTO\TrendSeriesDTO;
use App\Module\Insights\Application\Query\GetTrends;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Caches the composed trends read model in Redis, keyed by granularity + window.
 *
 * **Strategy: cache-on-read, not scheduler prewarming** (the ticket left the
 * choice open). Prewarming works when the read is one fixed shape — the cockpit's
 * "today", the Discogs collection. Here the window is a caller-chosen
 * `[from, to]` pair, so a scheduler would have to guess which of effectively
 * unbounded ranges to precompute, and would still miss on anything else. What is
 * actually hit repeatedly is the frontend's default window, and cache-on-read
 * covers exactly that without predicting anything: the first visit pays, every
 * visit inside the TTL is free.
 *
 * Freshness is bounded by the TTL. Cross-module event invalidation is
 * deliberately NOT wired — Insights would have to import the source modules'
 * event classes, breaking deptrac (the Dashboard HMAI-263 / Search HMAI-271
 * precedent). The TTL is longer than the cockpit's 300 s because a trend bucket
 * is a whole week or month: fifteen minutes of staleness is invisible on a chart
 * that answers "how has this been going".
 *
 * One composition is deliberately **not** stored: one where a metric came back
 * unreadable. Per-metric fault isolation (HMAI-331) is there so a broken source
 * costs one card rather than the dashboard — but caching that degraded answer
 * would outlive the fault, leaving a metric reported as unavailable for the full
 * TTL after its source recovered. Skipping the write costs one recomputation and
 * lets the very next read find the source healthy again.
 *
 * Hit/miss is logged at debug level so the effect is observable without
 * instrumenting the handler.
 */
final readonly class RedisTrendsCache implements TrendsCacheInterface
{
    private const int TTL_SECONDS = 900;

    public function __construct(
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    public function remember(GetTrends $query, callable $compute): TrendsDTO
    {
        $key = $this->cacheKey($query);
        $miss = false;

        $degraded = false;

        $trends = $this->cache->get($key, static function (ItemInterface $item, bool &$save) use ($compute, &$miss, &$degraded): TrendsDTO {
            $miss = true;
            $item->expiresAfter(self::TTL_SECONDS);

            $trends = $compute();
            $degraded = self::isDegraded($trends);
            $save = !$degraded;

            return $trends;
        });

        $this->logger->debug('Insights trends cache lookup.', [
            'key' => $key,
            'cache_hit' => !$miss,
            'stored' => $miss && !$degraded,
        ]);

        return $trends;
    }

    public function invalidate(GetTrends $query): void
    {
        $this->cache->delete($this->cacheKey($query));
    }

    /**
     * A healthy metric always fills its window — zero buckets included — so a
     * series with no points is the module's agreed "could not be read" signal
     * (see {@see TrendSeriesDTO}).
     */
    private static function isDegraded(TrendsDTO $trends): bool
    {
        return array_any($trends->series, static fn (TrendSeriesDTO $series): bool => [] === $series->points);
    }

    private function cacheKey(GetTrends $query): string
    {
        return sprintf(
            'trends_%s_%s_%s',
            $query->granularity->value,
            $query->from->format('Ymd'),
            $query->to->format('Ymd'),
        );
    }
}
