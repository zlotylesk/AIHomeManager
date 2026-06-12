<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Series\Domain;

use App\Module\Series\Domain\Entity\Episode;
use App\Module\Series\Domain\Entity\Season;
use App\Module\Series\Domain\ValueObject\Rating;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SeasonTest extends TestCase
{
    public function testExposesConstructorArguments(): void
    {
        $season = new Season('season-1', 'series-7', 3);

        self::assertSame('season-1', $season->id());
        self::assertSame('series-7', $season->seriesId());
        self::assertSame(3, $season->number());
    }

    public function testStartsWithNoEpisodes(): void
    {
        $season = new Season('season-1', 'series-7', 1);

        self::assertSame([], $season->episodes());
    }

    public function testAddEpisodeKeysItById(): void
    {
        $season = new Season('season-1', 'series-7', 1);
        $episode = new Episode('ep-1', 'season-1', 'Pilot', 1);

        $season->addEpisode($episode);

        self::assertSame(['ep-1' => $episode], $season->episodes());
    }

    public function testAddEpisodeWithSameIdOverwritesPrevious(): void
    {
        // Episodes are keyed by id, so re-adding under the same id replaces
        // the existing reference rather than producing a duplicate slot.
        $season = new Season('season-1', 'series-7', 1);
        $original = new Episode('ep-1', 'season-1', 'Pilot', 1);
        $replacement = new Episode('ep-1', 'season-1', 'Pilot (re-cut)', 1);

        $season->addEpisode($original);
        $season->addEpisode($replacement);

        self::assertCount(1, $season->episodes());
        self::assertSame($replacement, $season->findEpisode('ep-1'));
    }

    public function testAddEpisodeRejectsADuplicateNumberInTheSameSeason(): void
    {
        // Episode numbers are unique within a season (HMAI-187).
        $season = new Season('season-1', 'series-7', 1);
        $season->addEpisode(new Episode('ep-1', 'season-1', 'Pilot', 1));

        $this->expectException(InvalidArgumentException::class);
        $season->addEpisode(new Episode('ep-2', 'season-1', 'Cat in the Bag', 1));
    }

    public function testFindEpisodeReturnsNullForUnknownId(): void
    {
        $season = new Season('season-1', 'series-7', 1);
        $season->addEpisode(new Episode('ep-1', 'season-1', 'Pilot', 1));

        self::assertNull($season->findEpisode('ep-missing'));
    }

    public function testOwnRatingStartsAsNull(): void
    {
        // A season's own (manual) score is independent of episode ratings and
        // unset until the user explicitly rates the season (HMAI-179).
        $season = new Season('season-1', 'series-7', 1);

        self::assertNull($season->rating());
    }

    public function testRateStoresOwnRating(): void
    {
        $season = new Season('season-1', 'series-7', 1);
        $rating = new Rating(7);

        $season->rate($rating);

        self::assertSame($rating, $season->rating());
    }

    public function testRateOverwritesPreviousOwnRating(): void
    {
        $season = new Season('season-1', 'series-7', 1);
        $season->rate(new Rating(4));

        $season->rate(new Rating(9));

        self::assertNotNull($season->rating());
        self::assertSame(9, $season->rating()->value());
    }
}
