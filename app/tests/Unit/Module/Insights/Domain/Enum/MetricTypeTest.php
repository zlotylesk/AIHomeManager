<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Insights\Domain\Enum;

use App\Module\Insights\Domain\Enum\MetricType;
use App\Module\Insights\Domain\Enum\MetricUnit;
use PHPUnit\Framework\TestCase;

final class MetricTypeTest extends TestCase
{
    /**
     * A metric added without a unit is a metric whose range and meaningful fold
     * are undefined — every case must resolve, so a future one fails here rather
     * than at render time.
     */
    public function testEveryMetricResolvesToAUnit(): void
    {
        foreach (MetricType::cases() as $type) {
            self::assertContains($type->unit(), MetricUnit::cases(), $type->value.' has no unit.');
        }
    }

    public function testCountsMinutesAndRatesAreToldApart(): void
    {
        self::assertSame(MetricUnit::COUNT, MetricType::BOOKS_PAGES_READ->unit());
        self::assertSame(MetricUnit::COUNT, MetricType::SERIES_EPISODES_WATCHED->unit());
        self::assertSame(MetricUnit::COUNT, MetricType::MUSIC_TRACKS_PLAYED->unit());
        self::assertSame(MetricUnit::MINUTES, MetricType::YOUTUBE_MINUTES_WATCHED->unit());
        self::assertSame(MetricUnit::PERCENT, MetricType::TASKS_COMPLETION_RATE->unit());
    }

    public function testOnlyTheRateIsBoundedAndOnlyTheRateIsNotCumulative(): void
    {
        foreach (MetricType::cases() as $type) {
            $unit = $type->unit();
            $isRate = MetricUnit::PERCENT === $unit;

            self::assertSame(!$isRate, $unit->isCumulative(), $type->value);
            self::assertSame($isRate ? 100.0 : null, $unit->maxValue(), $type->value);
        }
    }
}
