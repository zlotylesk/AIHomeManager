<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Insights\Infrastructure;

use App\Module\Insights\Application\DTO\TrendsDTO;
use App\Module\Insights\Application\Query\GetTrends;
use App\Module\Insights\Domain\Enum\Granularity;
use App\Module\Insights\Infrastructure\Cache\RedisTrendsCache;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class RedisTrendsCacheTest extends TestCase
{
    private function cache(): RedisTrendsCache
    {
        return new RedisTrendsCache(new ArrayAdapter(), new NullLogger());
    }

    private function query(
        Granularity $granularity = Granularity::WEEK,
        string $from = '2026-07-01',
        string $to = '2026-07-31',
    ): GetTrends {
        return new GetTrends($granularity, new DateTimeImmutable($from), new DateTimeImmutable($to));
    }

    private function trends(string $marker): TrendsDTO
    {
        return new TrendsDTO($marker, '2026-07-31', 'week', []);
    }

    public function testComputesOnMissAndServesTheStoredValueAfterwards(): void
    {
        $cache = $this->cache();
        $query = $this->query();
        $calls = 0;

        $compute = function () use (&$calls): TrendsDTO {
            ++$calls;

            return $this->trends('computed-'.$calls);
        };

        self::assertSame('computed-1', $cache->remember($query, $compute)->from);
        self::assertSame('computed-1', $cache->remember($query, $compute)->from);
        self::assertSame(1, $calls, 'the second read must not recompute');
    }

    /**
     * Two windows are two different questions; sharing an entry would serve one
     * range's numbers under another range's label.
     */
    public function testDifferentWindowsDoNotShareAnEntry(): void
    {
        $cache = $this->cache();

        $july = $cache->remember($this->query(from: '2026-07-01'), fn (): TrendsDTO => $this->trends('july'));
        $june = $cache->remember($this->query(from: '2026-06-01'), fn (): TrendsDTO => $this->trends('june'));

        self::assertSame('july', $july->from);
        self::assertSame('june', $june->from);
    }

    public function testDifferentGranularitiesDoNotShareAnEntry(): void
    {
        $cache = $this->cache();

        $weekly = $cache->remember($this->query(Granularity::WEEK), fn (): TrendsDTO => $this->trends('weekly'));
        $monthly = $cache->remember($this->query(Granularity::MONTH), fn (): TrendsDTO => $this->trends('monthly'));

        self::assertSame('weekly', $weekly->from);
        self::assertSame('monthly', $monthly->from);
    }

    public function testInvalidationForcesTheNextReadToRecompute(): void
    {
        $cache = $this->cache();
        $query = $this->query();

        $cache->remember($query, fn (): TrendsDTO => $this->trends('first'));
        $cache->invalidate($query);

        self::assertSame('second', $cache->remember($query, fn (): TrendsDTO => $this->trends('second'))->from);
    }

    public function testInvalidatingOneWindowLeavesTheOtherCached(): void
    {
        $cache = $this->cache();
        $july = $this->query(from: '2026-07-01');
        $june = $this->query(from: '2026-06-01');

        $cache->remember($july, fn (): TrendsDTO => $this->trends('july'));
        $cache->remember($june, fn (): TrendsDTO => $this->trends('june'));
        $cache->invalidate($july);

        self::assertSame('july-recomputed', $cache->remember($july, fn (): TrendsDTO => $this->trends('july-recomputed'))->from);
        self::assertSame('june', $cache->remember($june, fn (): TrendsDTO => $this->trends('june-recomputed'))->from);
    }
}
