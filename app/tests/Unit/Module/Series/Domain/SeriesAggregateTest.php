<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Series\Domain;

use App\Module\Series\Domain\Entity\Episode;
use App\Module\Series\Domain\Entity\Season;
use App\Module\Series\Domain\Entity\Series;
use App\Module\Series\Domain\Event\EpisodeRated;
use App\Module\Series\Domain\ValueObject\Rating;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SeriesAggregateTest extends TestCase
{
    private const string SERIES_ID = 'series-1';
    private const string SEASON_ID = 'season-1';
    private const string EPISODE_ID = 'episode-1';

    public function testAddSeasonAddsSeason(): void
    {
        $series = new Series(self::SERIES_ID, 'Breaking Bad');
        $season = new Season(self::SEASON_ID, self::SERIES_ID, 1);

        $series->addSeason($season);

        self::assertArrayHasKey(self::SEASON_ID, $series->seasons());
    }

    public function testAddEpisodeAddsEpisodeToSeason(): void
    {
        $series = $this->seriesWithSeason();
        $episode = new Episode(self::EPISODE_ID, self::SEASON_ID, 'Pilot');

        $series->addEpisode(self::SEASON_ID, $episode);

        $season = $series->seasons()[self::SEASON_ID];
        self::assertArrayHasKey(self::EPISODE_ID, $season->episodes());
    }

    public function testRateEpisodeSetsRatingOnEpisode(): void
    {
        $series = $this->seriesWithEpisode();

        $series->rateEpisode(self::SEASON_ID, self::EPISODE_ID, new Rating(9));

        $episode = $series->seasons()[self::SEASON_ID]->findEpisode(self::EPISODE_ID);
        self::assertNotNull($episode->rating());
        self::assertSame(9, $episode->rating()->value());
    }

    public function testRateEpisodeRecordsEpisodeRatedEvent(): void
    {
        $series = $this->seriesWithEpisode();

        $series->rateEpisode(self::SEASON_ID, self::EPISODE_ID, new Rating(9));
        $events = $series->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(EpisodeRated::class, $events[0]);

        $event = $events[0];
        self::assertSame(self::SERIES_ID, $event->seriesId);
        self::assertSame(self::SEASON_ID, $event->seasonId);
        self::assertSame(self::EPISODE_ID, $event->episodeId);
        self::assertSame(9, $event->rating);
    }

    public function testReleaseEventsClearsCollection(): void
    {
        $series = $this->seriesWithEpisode();
        $series->rateEpisode(self::SEASON_ID, self::EPISODE_ID, new Rating(9));

        $series->releaseEvents();

        self::assertEmpty($series->releaseEvents());
    }

    public function testAddEpisodeToUnknownSeasonThrows(): void
    {
        $series = new Series(self::SERIES_ID, 'Breaking Bad');

        $this->expectException(DomainException::class);
        $series->addEpisode('unknown-season', new Episode(self::EPISODE_ID, 'unknown-season', 'Pilot'));
    }

    public function testRateEpisodeOnUnknownSeasonThrows(): void
    {
        $series = new Series(self::SERIES_ID, 'Breaking Bad');

        $this->expectException(DomainException::class);
        $series->rateEpisode('unknown-season', self::EPISODE_ID, new Rating(5));
    }

    public function testRateEpisodeOnUnknownEpisodeThrows(): void
    {
        $series = $this->seriesWithSeason();

        $this->expectException(DomainException::class);
        $series->rateEpisode(self::SEASON_ID, 'unknown-episode', new Rating(5));
    }

    public function testOwnSeriesRatingStartsAsNull(): void
    {
        // The series' own (manual) score is separate from the episode-derived
        // average and unset until the user rates the whole series (HMAI-179).
        $series = new Series(self::SERIES_ID, 'Breaking Bad');

        self::assertNull($series->rating());
    }

    public function testRateSetsOwnSeriesRating(): void
    {
        $series = new Series(self::SERIES_ID, 'Breaking Bad');

        $series->rate(new Rating(10));

        self::assertNotNull($series->rating());
        self::assertSame(10, $series->rating()->value());
    }

    public function testRateSeriesRecordsNoDomainEvent(): void
    {
        // No subscriber consumes a "series rated" signal — YAGNI, so the
        // aggregate stays event-free on manual rating (HMAI-179).
        $series = new Series(self::SERIES_ID, 'Breaking Bad');

        $series->rate(new Rating(10));

        self::assertEmpty($series->releaseEvents());
    }

    public function testRateSeasonSetsOwnSeasonRating(): void
    {
        $series = $this->seriesWithSeason();

        $series->rateSeason(self::SEASON_ID, new Rating(6));

        $season = $series->seasons()[self::SEASON_ID];
        self::assertNotNull($season->rating());
        self::assertSame(6, $season->rating()->value());
    }

    public function testRateSeasonRecordsNoDomainEvent(): void
    {
        $series = $this->seriesWithSeason();

        $series->rateSeason(self::SEASON_ID, new Rating(6));

        self::assertEmpty($series->releaseEvents());
    }

    public function testRateSeasonOnUnknownSeasonThrows(): void
    {
        $series = new Series(self::SERIES_ID, 'Breaking Bad');

        $this->expectException(DomainException::class);
        $series->rateSeason('unknown-season', new Rating(5));
    }

    public function testSetEpisodeWatchedMarksEpisode(): void
    {
        $series = $this->seriesWithEpisode();

        $series->setEpisodeWatched(self::SEASON_ID, self::EPISODE_ID, true);

        $episode = $series->seasons()[self::SEASON_ID]->findEpisode(self::EPISODE_ID);
        self::assertNotNull($episode);
        self::assertTrue($episode->isWatched());
        self::assertNotNull($episode->watchedAt());
    }

    public function testSetEpisodeWatchedFalseUnmarksEpisode(): void
    {
        $series = $this->seriesWithEpisode();
        $series->setEpisodeWatched(self::SEASON_ID, self::EPISODE_ID, true);

        $series->setEpisodeWatched(self::SEASON_ID, self::EPISODE_ID, false);

        $episode = $series->seasons()[self::SEASON_ID]->findEpisode(self::EPISODE_ID);
        self::assertNotNull($episode);
        self::assertFalse($episode->isWatched());
        self::assertNull($episode->watchedAt());
    }

    public function testSetEpisodeWatchedRecordsNoDomainEvent(): void
    {
        $series = $this->seriesWithEpisode();

        $series->setEpisodeWatched(self::SEASON_ID, self::EPISODE_ID, true);

        self::assertEmpty($series->releaseEvents());
    }

    public function testSetEpisodeWatchedOnUnknownSeasonThrows(): void
    {
        $series = new Series(self::SERIES_ID, 'Breaking Bad');

        $this->expectException(DomainException::class);
        $series->setEpisodeWatched('unknown-season', self::EPISODE_ID, true);
    }

    public function testSetEpisodeWatchedOnUnknownEpisodeThrows(): void
    {
        $series = $this->seriesWithSeason();

        $this->expectException(DomainException::class);
        $series->setEpisodeWatched(self::SEASON_ID, 'unknown-episode', true);
    }

    private function seriesWithSeason(): Series
    {
        $series = new Series(self::SERIES_ID, 'Breaking Bad');
        $series->addSeason(new Season(self::SEASON_ID, self::SERIES_ID, 1));

        return $series;
    }

    private function seriesWithEpisode(): Series
    {
        $series = $this->seriesWithSeason();
        $series->addEpisode(self::SEASON_ID, new Episode(self::EPISODE_ID, self::SEASON_ID, 'Pilot'));

        return $series;
    }
}
