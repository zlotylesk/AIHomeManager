<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Dashboard\Infrastructure;

use App\Module\Dashboard\Application\DTO\DashboardDTO;
use App\Module\Dashboard\Infrastructure\Cache\RedisDashboardCache;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class RedisDashboardCacheTest extends TestCase
{
    private function dto(string $date): DashboardDTO
    {
        return new DashboardDTO($date, [], null, [], [], []);
    }

    public function testServesRepeatedDayFromCacheWithoutRecomputing(): void
    {
        $cache = new RedisDashboardCache(new ArrayAdapter());
        $day = new DateTimeImmutable('2026-07-13 09:00:00');
        $calls = 0;

        $compute = function () use (&$calls, $day): DashboardDTO {
            ++$calls;

            return $this->dto($day->format('Y-m-d'));
        };

        $first = $cache->remember($day, $compute);
        $second = $cache->remember($day, $compute);

        self::assertSame(1, $calls, 'A cache hit must not recompute the cockpit.');
        self::assertEquals($first, $second);
    }

    public function testDifferentDaysAreComputedSeparately(): void
    {
        $cache = new RedisDashboardCache(new ArrayAdapter());
        $calls = 0;
        $compute = function () use (&$calls): DashboardDTO {
            ++$calls;

            return $this->dto('2026-07-13');
        };

        $cache->remember(new DateTimeImmutable('2026-07-13 09:00:00'), $compute);
        $cache->remember(new DateTimeImmutable('2026-07-14 09:00:00'), $compute);

        self::assertSame(2, $calls, 'A new reference day is a distinct key and must recompute.');
    }

    public function testInvalidationForcesRecompute(): void
    {
        $cache = new RedisDashboardCache(new ArrayAdapter());
        $day = new DateTimeImmutable('2026-07-13 09:00:00');
        $calls = 0;
        $compute = function () use (&$calls): DashboardDTO {
            ++$calls;

            return $this->dto('2026-07-13');
        };

        $cache->remember($day, $compute);
        $cache->invalidate($day);
        $cache->remember($day, $compute);

        self::assertSame(2, $calls, 'After invalidation the next read must recompute.');
    }
}
