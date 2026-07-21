<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insights;

use App\Module\Insights\Domain\Enum\Granularity;
use App\Module\Insights\Domain\Enum\MetricType;
use App\Module\Insights\Domain\ValueObject\TrendSeries;
use App\Module\Insights\Infrastructure\Provider\BooksPagesReadAdapter;
use App\Module\Insights\Infrastructure\Provider\CompositeTrendDataProvider;
use App\Module\Insights\Infrastructure\Provider\MusicTracksPlayedAdapter;
use App\Module\Insights\Infrastructure\Provider\SeriesEpisodesWatchedAdapter;
use App\Module\Insights\Infrastructure\Provider\TasksCompletionRateAdapter;
use App\Module\Insights\Infrastructure\Provider\YouTubeMinutesWatchedAdapter;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TrendAdaptersTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['book_reading_sessions', 'series_episodes', 'videos', 'music_listening_sessions', 'tasks'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * July 2026 starts on a Wednesday, so the first week bucket (2026-06-29)
     * reaches back into June — which is exactly the alignment an off-by-one
     * bucket expression would get wrong.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function july(): array
    {
        return [new DateTimeImmutable('2026-07-01 00:00:00'), new DateTimeImmutable('2026-07-31 23:59:59')];
    }

    public function testBooksAdapterSumsPagesIntoWeeklyBuckets(): void
    {
        $this->readingSession('r-1', '2026-07-07', 30);
        $this->readingSession('r-2', '2026-07-09', 12);
        $this->readingSession('r-3', '2026-07-14', 50);
        $this->readingSession('r-4', '2026-08-04', 999);

        [$from, $to] = $this->july();
        $series = new BooksPagesReadAdapter($this->connection)->seriesFor(MetricType::BOOKS_PAGES_READ, Granularity::WEEK, $from, $to);

        self::assertSame(MetricType::BOOKS_PAGES_READ, $series->metric);
        self::assertSame(
            ['2026-06-29' => 0.0, '2026-07-06' => 42.0, '2026-07-13' => 50.0, '2026-07-20' => 0.0, '2026-07-27' => 0.0],
            self::asMap($series),
        );
    }

    public function testMonthlyBucketsFoldTheWholeMonthIntoOnePoint(): void
    {
        $this->readingSession('r-1', '2026-07-07', 30);
        $this->readingSession('r-2', '2026-07-28', 20);

        [$from, $to] = $this->july();
        $series = new BooksPagesReadAdapter($this->connection)->seriesFor(MetricType::BOOKS_PAGES_READ, Granularity::MONTH, $from, $to);

        self::assertSame(['2026-07-01' => 50.0], self::asMap($series));
    }

    /**
     * A window with no activity at all must still produce a point per bucket —
     * a chart with holes draws a straight line across the idle stretch.
     */
    public function testIdleWindowIsFilledWithZeroesRatherThanLeftEmpty(): void
    {
        [$from, $to] = $this->july();
        $series = new BooksPagesReadAdapter($this->connection)->seriesFor(MetricType::BOOKS_PAGES_READ, Granularity::WEEK, $from, $to);

        self::assertCount(5, $series->points);
        self::assertSame(0.0, $series->total());
    }

    public function testSeriesAdapterCountsOnlyWatchedEpisodesThatCarryADate(): void
    {
        $this->episode('e-1', 1, true, '2026-07-08 20:00:00');
        $this->episode('e-2', 2, true, '2026-07-09 21:00:00');
        $this->episode('e-3', 3, false, null);
        $this->episode('e-4', 4, true, null);

        [$from, $to] = $this->july();
        $series = new SeriesEpisodesWatchedAdapter($this->connection)->seriesFor(MetricType::SERIES_EPISODES_WATCHED, Granularity::MONTH, $from, $to);

        self::assertSame(['2026-07-01' => 2.0], self::asMap($series));
    }

    public function testYouTubeAdapterReportsMinutesNotSeconds(): void
    {
        $this->video('yt-1', 900, '2026-07-08 18:00:00');
        $this->video('yt-2', 300, '2026-07-08 19:00:00');
        $this->video('yt-3', 600, null);

        [$from, $to] = $this->july();
        $series = new YouTubeMinutesWatchedAdapter($this->connection)->seriesFor(MetricType::YOUTUBE_MINUTES_WATCHED, Granularity::MONTH, $from, $to);

        self::assertSame(['2026-07-01' => 20.0], self::asMap($series));
    }

    public function testMusicAdapterCountsRowsAndIgnoresTheOptionalPlayCountColumn(): void
    {
        $this->listen('m-1', '2026-07-08 10:00:00', 4123);
        $this->listen('m-2', '2026-07-08 10:04:00', null);

        [$from, $to] = $this->july();
        $series = new MusicTracksPlayedAdapter($this->connection)->seriesFor(MetricType::MUSIC_TRACKS_PLAYED, Granularity::MONTH, $from, $to);

        self::assertSame(['2026-07-01' => 2.0], self::asMap($series));
    }

    public function testTasksAdapterReportsThePercentageCompleted(): void
    {
        $this->task('t-1', 'completed', '2026-07-08 09:00:00');
        $this->task('t-2', 'completed', '2026-07-09 09:00:00');
        $this->task('t-3', 'pending', '2026-07-10 09:00:00');
        $this->task('t-4', 'pending', '2026-07-11 09:00:00');

        [$from, $to] = $this->july();
        $series = new TasksCompletionRateAdapter($this->connection)->seriesFor(MetricType::TASKS_COMPLETION_RATE, Granularity::MONTH, $from, $to);

        self::assertSame(['2026-07-01' => 50.0], self::asMap($series));
    }

    /**
     * A task called off on purpose is not a failure — counting it would punish
     * the user for tidying up their own schedule.
     */
    public function testCancelledTasksCountForNeitherSideOfTheRatio(): void
    {
        $this->task('t-1', 'completed', '2026-07-08 09:00:00');
        $this->task('t-2', 'cancelled', '2026-07-09 09:00:00');

        [$from, $to] = $this->july();
        $series = new TasksCompletionRateAdapter($this->connection)->seriesFor(MetricType::TASKS_COMPLETION_RATE, Granularity::MONTH, $from, $to);

        self::assertSame(['2026-07-01' => 100.0], self::asMap($series));
    }

    /**
     * Averaging daily rates would let a day with a single completed task weigh
     * as much as a day with nine tasks; the bucket totals are folded first.
     */
    public function testRatioIsFoldedFromTotalsNotAveragedFromDailyRates(): void
    {
        $this->task('t-1', 'completed', '2026-07-08 09:00:00');
        foreach (range(1, 9) as $i) {
            $this->task('t-'.(10 + $i), $i <= 6 ? 'completed' : 'pending', '2026-07-09 09:00:00');
        }

        [$from, $to] = $this->july();
        $series = new TasksCompletionRateAdapter($this->connection)->seriesFor(MetricType::TASKS_COMPLETION_RATE, Granularity::MONTH, $from, $to);

        self::assertSame(['2026-07-01' => 70.0], self::asMap($series));
    }

    public function testAdapterRefusesAMetricItDoesNotRead(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reads books_pages_read, not music_tracks_played');

        [$from, $to] = $this->july();
        new BooksPagesReadAdapter($this->connection)->seriesFor(MetricType::MUSIC_TRACKS_PLAYED, Granularity::WEEK, $from, $to);
    }

    public function testCompositeRoutesEveryMetricToItsOwnAdapter(): void
    {
        $this->readingSession('r-1', '2026-07-07', 30);
        $this->episode('e-1', 1, true, '2026-07-08 20:00:00');
        $this->video('yt-1', 600, '2026-07-08 18:00:00');
        $this->listen('m-1', '2026-07-08 10:00:00', null);
        $this->task('t-1', 'completed', '2026-07-08 09:00:00');

        $composite = new CompositeTrendDataProvider([
            new BooksPagesReadAdapter($this->connection),
            new SeriesEpisodesWatchedAdapter($this->connection),
            new YouTubeMinutesWatchedAdapter($this->connection),
            new MusicTracksPlayedAdapter($this->connection),
            new TasksCompletionRateAdapter($this->connection),
        ]);

        [$from, $to] = $this->july();
        $expected = [
            MetricType::BOOKS_PAGES_READ->value => 30.0,
            MetricType::SERIES_EPISODES_WATCHED->value => 1.0,
            MetricType::YOUTUBE_MINUTES_WATCHED->value => 10.0,
            MetricType::MUSIC_TRACKS_PLAYED->value => 1.0,
            MetricType::TASKS_COMPLETION_RATE->value => 100.0,
        ];

        foreach (MetricType::cases() as $metric) {
            self::assertTrue($composite->supports($metric), $metric->value);
            $series = $composite->seriesFor($metric, Granularity::MONTH, $from, $to);

            self::assertSame($metric, $series->metric);
            self::assertSame($expected[$metric->value], $series->points[0]->value, $metric->value);
        }
    }

    /**
     * @return array<string, float> value per bucket start
     */
    private static function asMap(TrendSeries $series): array
    {
        $map = [];
        foreach ($series->points as $point) {
            $map[$point->bucketStart->format('Y-m-d')] = $point->value;
        }

        return $map;
    }

    private function readingSession(string $id, string $date, int $pages): void
    {
        $this->connection->insert('book_reading_sessions', [
            'id' => $id, 'book_id' => 'b-1', 'date' => $date, 'pages_read' => $pages,
        ]);
    }

    private function episode(string $id, int $number, bool $watched, ?string $watchedAt): void
    {
        $this->connection->insert('series_episodes', [
            'id' => $id, 'season_id' => 's-1', 'title' => 'Ep '.$number, 'number' => $number,
            'watched' => $watched ? 1 : 0, 'watched_at' => $watchedAt,
        ]);
    }

    private function video(string $id, int $durationSeconds, ?string $watchedAt): void
    {
        $this->connection->insert('videos', [
            'youtube_id' => $id, 'title' => 'Video '.$id, 'channel' => 'Channel',
            'duration_seconds' => $durationSeconds, 'added_to_watchlist_at' => '2026-07-01 00:00:00',
            'started_at' => null, 'watched_at' => $watchedAt,
        ]);
    }

    private function listen(string $id, string $playedAt, ?int $playCount): void
    {
        $this->connection->insert('music_listening_sessions', [
            'id' => $id, 'artist' => 'Artist', 'title' => 'Track '.$id, 'played_at' => $playedAt,
            'source' => 'lastfm', 'play_count' => $playCount, 'dedup_hash' => hash('sha256', $id),
            'created_at' => '2026-07-01 00:00:00',
        ]);
    }

    private function task(string $id, string $status, string $timeStart): void
    {
        $this->connection->insert('tasks', [
            'id' => $id, 'title' => 'Task '.$id, 'status' => $status,
            'time_start' => $timeStart, 'time_end' => $timeStart,
        ]);
    }
}
