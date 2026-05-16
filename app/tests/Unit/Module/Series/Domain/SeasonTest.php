<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Series\Domain;

use App\Module\Series\Domain\Entity\Episode;
use App\Module\Series\Domain\Entity\Season;
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
        $episode = new Episode('ep-1', 'season-1', 'Pilot');

        $season->addEpisode($episode);

        self::assertSame(['ep-1' => $episode], $season->episodes());
    }

    public function testAddEpisodeWithSameIdOverwritesPrevious(): void
    {
        // Episodes are keyed by id, so re-adding under the same id replaces
        // the existing reference rather than producing a duplicate slot.
        $season = new Season('season-1', 'series-7', 1);
        $original = new Episode('ep-1', 'season-1', 'Pilot');
        $replacement = new Episode('ep-1', 'season-1', 'Pilot (re-cut)');

        $season->addEpisode($original);
        $season->addEpisode($replacement);

        self::assertCount(1, $season->episodes());
        self::assertSame($replacement, $season->findEpisode('ep-1'));
    }

    public function testFindEpisodeReturnsNullForUnknownId(): void
    {
        $season = new Season('season-1', 'series-7', 1);
        $season->addEpisode(new Episode('ep-1', 'season-1', 'Pilot'));

        self::assertNull($season->findEpisode('ep-missing'));
    }
}
