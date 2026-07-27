<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Series\Application\Handler;

use App\Module\Series\Application\Command\AddSeason;
use App\Module\Series\Application\Handler\AddSeasonHandler;
use App\Module\Series\Domain\Entity\Season;
use App\Module\Series\Domain\Entity\Series;
use App\Module\Series\Domain\Exception\SeasonNumberAlreadyTaken;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AddSeasonHandlerTest extends TestCase
{
    public function testAddsSeasonAndReturnsId(): void
    {
        $series = new Series('series-1', 'Breaking Bad');

        $repo = $this->createMock(SeriesRepositoryInterface::class);
        $repo->method('findById')->with('series-1')->willReturn($series);
        $repo->expects(self::once())->method('save')->with($series);

        $handler = new AddSeasonHandler($repo);
        $id = $handler(new AddSeason('series-1', 1));

        self::assertArrayHasKey($id, $series->seasons());
        self::assertSame(1, $series->seasons()[$id]->number());
    }

    public function testThrowsWhenSeriesNotFound(): void
    {
        $repo = $this->createMock(SeriesRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('save');

        $handler = new AddSeasonHandler($repo);

        $this->expectException(DomainException::class);
        $handler(new AddSeason('missing', 1));
    }

    /**
     * The guard this ticket adds (HMAI-402): season-number uniqueness within a
     * series is enforced in the handler, not on Series::addSeason() itself —
     * see AddSeasonHandler's docblock for why (repository hydration reuses
     * addSeason() and must never fail on a pre-existing duplicate).
     */
    public function testThrowsWhenSeasonNumberAlreadyUsedInSeries(): void
    {
        $series = new Series('series-1', 'Breaking Bad');
        $series->addSeason(new Season('season-1', 'series-1', 1));

        $repo = $this->createMock(SeriesRepositoryInterface::class);
        $repo->method('findById')->with('series-1')->willReturn($series);
        $repo->expects(self::never())->method('save');

        $handler = new AddSeasonHandler($repo);

        $this->expectException(SeasonNumberAlreadyTaken::class);
        $handler(new AddSeason('series-1', 1));
    }

    public function testAllowsAddingASeasonWithADifferentNumber(): void
    {
        $series = new Series('series-1', 'Breaking Bad');
        $series->addSeason(new Season('season-1', 'series-1', 1));

        $repo = $this->createMock(SeriesRepositoryInterface::class);
        $repo->method('findById')->with('series-1')->willReturn($series);
        $repo->expects(self::once())->method('save')->with($series);

        $handler = new AddSeasonHandler($repo);
        $id = $handler(new AddSeason('series-1', 2));

        self::assertCount(2, $series->seasons());
        self::assertSame(2, $series->seasons()[$id]->number());
    }
}
