<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Series\Domain;

use App\Module\Series\Domain\Entity\Episode;
use App\Module\Series\Domain\ValueObject\Rating;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EpisodeTest extends TestCase
{
    public function testExposesConstructorArguments(): void
    {
        $episode = new Episode('ep-1', 'season-1', 'Pilot', 4);

        self::assertSame('ep-1', $episode->id());
        self::assertSame('season-1', $episode->seasonId());
        self::assertSame('Pilot', $episode->title());
        self::assertSame(4, $episode->number());
    }

    public function testRatingStartsAsNull(): void
    {
        $episode = new Episode('ep-1', 'season-1', 'Pilot', 4);

        self::assertNull($episode->rating());
    }

    public function testRateStoresTheRating(): void
    {
        $episode = new Episode('ep-1', 'season-1', 'Pilot', 4);
        $rating = new Rating(8);

        $episode->rate($rating);

        self::assertSame($rating, $episode->rating());
    }

    public function testRateOverwritesPreviousRating(): void
    {
        $episode = new Episode('ep-1', 'season-1', 'Pilot', 4);
        $episode->rate(new Rating(5));

        $episode->rate(new Rating(9));

        self::assertNotNull($episode->rating());
        self::assertSame(9, $episode->rating()->value());
    }

    public function testStartsUnwatched(): void
    {
        $episode = new Episode('ep-1', 'season-1', 'Pilot', 1);

        self::assertFalse($episode->isWatched());
        self::assertNull($episode->watchedAt());
    }

    public function testMarkWatchedDefaultsTimestampToNow(): void
    {
        $episode = new Episode('ep-1', 'season-1', 'Pilot', 1);

        $episode->markWatched();

        self::assertTrue($episode->isWatched());
        self::assertNotNull($episode->watchedAt());
    }

    public function testMarkWatchedAcceptsExplicitTimestamp(): void
    {
        $episode = new Episode('ep-1', 'season-1', 'Pilot', 1);
        $when = new DateTimeImmutable('2024-01-15 20:00:00');

        $episode->markWatched($when);

        self::assertTrue($episode->isWatched());
        self::assertSame($when, $episode->watchedAt());
    }

    public function testUnmarkWatchedClearsFlagAndTimestamp(): void
    {
        $episode = new Episode('ep-1', 'season-1', 'Pilot', 1);
        $episode->markWatched();

        $episode->unmarkWatched();

        self::assertFalse($episode->isWatched());
        self::assertNull($episode->watchedAt());
    }
}
