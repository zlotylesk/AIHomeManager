<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Infrastructure\Cache;

use App\Module\Dashboard\Application\Cache\DashboardCacheInterface;
use App\Module\Dashboard\Application\DTO\DashboardDTO;
use DateTimeImmutable;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Caches the composed cockpit read model in Redis (HMAI-263), keyed by the
 * reference day with a short TTL. The cockpit aggregates several modules, so an
 * uncached `/` would run every adapter on each visit; a hit serves the whole
 * {@see DashboardDTO} from Redis instead.
 *
 * Freshness is bounded by the short TTL plus the day-scoped key (a new day starts
 * fresh). Cross-module event invalidation is deliberately NOT wired — the Dashboard
 * would have to import the source modules' event classes, breaking the deptrac
 * boundary (the Search HMAI-271 precedent: the TTL is the staleness bound). The
 * {@see invalidate()} hook stays available for a manual/ops clear.
 */
final readonly class RedisDashboardCache implements DashboardCacheInterface
{
    private const int TTL_SECONDS = 300;

    public function __construct(private CacheInterface $cache)
    {
    }

    public function remember(DateTimeImmutable $day, callable $compute): DashboardDTO
    {
        return $this->cache->get($this->cacheKey($day), static function (ItemInterface $item) use ($compute): DashboardDTO {
            $item->expiresAfter(self::TTL_SECONDS);

            return $compute();
        });
    }

    public function invalidate(DateTimeImmutable $day): void
    {
        $this->cache->delete($this->cacheKey($day));
    }

    private function cacheKey(DateTimeImmutable $day): string
    {
        return 'dashboard_'.$day->format('Ymd');
    }
}
