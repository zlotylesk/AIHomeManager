<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Series\Domain;

use App\Module\Series\Domain\Entity\Episode;
use App\Module\Series\Domain\ValueObject\Rating;
use PHPUnit\Framework\TestCase;

final class EpisodeTest extends TestCase
{
    public function testExposesConstructorArguments(): void
    {
        $episode = new Episode('ep-1', 'season-1', 'Pilot');

        self::assertSame('ep-1', $episode->id());
        self::assertSame('season-1', $episode->seasonId());
        self::assertSame('Pilot', $episode->title());
    }

    public function testRatingStartsAsNull(): void
    {
        // A freshly-loaded episode is unrated — queries depend on this to
        // distinguish "not yet watched" from "rated low".
        $episode = new Episode('ep-1', 'season-1', 'Pilot');

        self::assertNull($episode->rating());
    }

    public function testRateStoresTheRating(): void
    {
        $episode = new Episode('ep-1', 'season-1', 'Pilot');
        $rating = new Rating(8);

        $episode->rate($rating);

        self::assertSame($rating, $episode->rating());
    }

    public function testRateOverwritesPreviousRating(): void
    {
        // Re-rating an episode replaces the prior value — the aggregate is
        // responsible for emitting the EpisodeRated event on each change.
        $episode = new Episode('ep-1', 'season-1', 'Pilot');
        $episode->rate(new Rating(5));

        $episode->rate(new Rating(9));

        self::assertNotNull($episode->rating());
        self::assertSame(9, $episode->rating()->value());
    }
}
