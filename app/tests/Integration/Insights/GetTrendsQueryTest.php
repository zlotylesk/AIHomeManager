<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insights;

use App\Messaging\QueryBus;
use App\Module\Insights\Application\DTO\TrendsDTO;
use App\Module\Insights\Application\DTO\TrendSeriesDTO;
use App\Module\Insights\Application\Query\GetTrends;
use App\Module\Insights\Domain\Enum\Granularity;
use App\Module\Insights\Domain\Enum\MetricType;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Drives the real `query.bus` into the real handler, which reads through the
 * real DI-wired composite and the five real DBAL adapters against MySQL —
 * nothing is stubbed. That is what proves the `services.yaml` tagging: a
 * forgotten tag shows up here as a metric with no points.
 */
final class GetTrendsQueryTest extends KernelTestCase
{
    private Connection $connection;
    private QueryBus $queryBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->connection = $container->get(EntityManagerInterface::class)->getConnection();
        $this->queryBus = $container->get(QueryBus::class);

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['book_reading_sessions', 'series_episodes', 'videos', 'music_listening_sessions', 'tasks'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testEveryMetricIsWiredAndReadsItsOwnSource(): void
    {
        $this->connection->insert('book_reading_sessions', ['id' => 'r-1', 'book_id' => 'b-1', 'date' => '2026-07-08', 'pages_read' => 40]);
        $this->connection->insert('series_episodes', ['id' => 'e-1', 'season_id' => 's-1', 'title' => 'Ep', 'number' => 1, 'watched' => 1, 'watched_at' => '2026-07-08 20:00:00']);
        $this->connection->insert('videos', ['youtube_id' => 'v-1', 'title' => 'V', 'channel' => 'C', 'duration_seconds' => 1800, 'added_to_watchlist_at' => '2026-07-01 00:00:00', 'started_at' => null, 'watched_at' => '2026-07-08 18:00:00']);
        $this->connection->insert('music_listening_sessions', ['id' => 'm-1', 'artist' => 'A', 'title' => 'T', 'played_at' => '2026-07-08 10:00:00', 'source' => 'lastfm', 'play_count' => null, 'dedup_hash' => hash('sha256', 'm-1'), 'created_at' => '2026-07-01 00:00:00']);
        $this->connection->insert('tasks', ['id' => 't-1', 'title' => 'T', 'status' => 'completed', 'time_start' => '2026-07-08 09:00:00', 'time_end' => '2026-07-08 10:00:00']);

        $trends = $this->ask(Granularity::MONTH, '2026-07-01', '2026-07-31');

        self::assertSame('2026-07-01', $trends->from);
        self::assertSame('2026-07-31', $trends->to);
        self::assertSame('month', $trends->granularity);
        self::assertCount(count(MetricType::cases()), $trends->series);

        $expected = [
            MetricType::BOOKS_PAGES_READ->value => 40.0,
            MetricType::SERIES_EPISODES_WATCHED->value => 1.0,
            MetricType::YOUTUBE_MINUTES_WATCHED->value => 30.0,
            MetricType::MUSIC_TRACKS_PLAYED->value => 1.0,
            MetricType::TASKS_COMPLETION_RATE->value => 100.0,
        ];

        foreach ($trends->series as $series) {
            self::assertNotSame([], $series->points, $series->metric.' has no wired adapter.');
            self::assertSame($expected[$series->metric], $series->points[0]->value, $series->metric);
        }
    }

    public function testWeeklyGranularityFillsEveryBucketAcrossTheWindow(): void
    {
        $this->connection->insert('book_reading_sessions', ['id' => 'r-1', 'book_id' => 'b-1', 'date' => '2026-07-08', 'pages_read' => 40]);

        $trends = $this->ask(Granularity::WEEK, '2026-07-01', '2026-07-31');
        $pages = $this->seriesOf($trends, MetricType::BOOKS_PAGES_READ);

        self::assertSame(
            ['2026-06-29', '2026-07-06', '2026-07-13', '2026-07-20', '2026-07-27'],
            array_column($pages->points, 'bucketStart'),
        );
        self::assertSame([0.0, 40.0, 0.0, 0.0, 0.0], array_column($pages->points, 'value'));
        self::assertSame(40.0, $pages->total);
        self::assertSame(8.0, $pages->average);
        self::assertSame(40.0, $pages->headline);
    }

    /**
     * No activity anywhere still yields a full, honest dashboard: five series,
     * every bucket present, every value zero.
     */
    public function testAnEmptyDatabaseStillProducesAFullDashboard(): void
    {
        $trends = $this->ask(Granularity::WEEK, '2026-07-01', '2026-07-31');

        self::assertCount(count(MetricType::cases()), $trends->series);
        foreach ($trends->series as $series) {
            self::assertCount(5, $series->points, $series->metric);
            self::assertSame(0.0, $series->total, $series->metric);
        }
    }

    public function testTheCompletionRateIsReportedAsAPercentageNotAFraction(): void
    {
        $this->connection->insert('tasks', ['id' => 't-1', 'title' => 'A', 'status' => 'completed', 'time_start' => '2026-07-08 09:00:00', 'time_end' => '2026-07-08 10:00:00']);
        $this->connection->insert('tasks', ['id' => 't-2', 'title' => 'B', 'status' => 'pending', 'time_start' => '2026-07-08 11:00:00', 'time_end' => '2026-07-08 12:00:00']);

        $trends = $this->ask(Granularity::MONTH, '2026-07-01', '2026-07-31');
        $rate = $this->seriesOf($trends, MetricType::TASKS_COMPLETION_RATE);

        self::assertSame('percent', $rate->unit);
        self::assertSame(50.0, $rate->points[0]->value);
        self::assertSame(50.0, $rate->headline, 'a rate headlines its average, never its sum');
    }

    private function ask(Granularity $granularity, string $from, string $to): TrendsDTO
    {
        $trends = $this->queryBus->ask(new GetTrends(
            $granularity,
            new DateTimeImmutable($from),
            new DateTimeImmutable($to),
        ));

        self::assertInstanceOf(TrendsDTO::class, $trends);

        return $trends;
    }

    private function seriesOf(TrendsDTO $trends, MetricType $metric): TrendSeriesDTO
    {
        foreach ($trends->series as $series) {
            if ($series->metric === $metric->value) {
                return $series;
            }
        }

        self::fail($metric->value.' is missing from the composed trends.');
    }
}
