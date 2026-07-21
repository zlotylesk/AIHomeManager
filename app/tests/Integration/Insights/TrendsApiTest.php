<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insights;

use App\Module\Insights\Domain\Enum\MetricType;
use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class TrendsApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['book_reading_sessions', 'series_episodes', 'videos', 'music_listening_sessions', 'tasks'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testReturnsOneSeriesPerMetricWithTheFullWindow(): void
    {
        $this->connection->insert('book_reading_sessions', ['id' => 'r-1', 'book_id' => 'b-1', 'date' => '2026-07-08', 'pages_read' => 40]);

        $this->get('/api/v1/trends?granularity=week&from=2026-07-01&to=2026-07-31');
        self::assertResponseIsSuccessful();

        $body = $this->jsonResponse($this->client);
        self::assertSame('2026-07-01', $body['from']);
        self::assertSame('2026-07-31', $body['to']);
        self::assertSame('week', $body['granularity']);
        self::assertCount(count(MetricType::cases()), $body['series']);

        // JSON has one number type, so a whole-valued float encodes as `50`, not
        // `50.0` — hence assertEquals rather than assertSame on the numbers.
        $pages = $this->seriesOf($body, MetricType::BOOKS_PAGES_READ);
        self::assertEquals([
            'metric' => 'books_pages_read',
            'unit' => 'count',
            'total' => 40.0,
            'average' => 8.0,
            'headline' => 40.0,
            'points' => [
                ['bucketStart' => '2026-06-29', 'value' => 0.0],
                ['bucketStart' => '2026-07-06', 'value' => 40.0],
                ['bucketStart' => '2026-07-13', 'value' => 0.0],
                ['bucketStart' => '2026-07-20', 'value' => 0.0],
                ['bucketStart' => '2026-07-27', 'value' => 0.0],
            ],
        ], $pages);
    }

    public function testMonthlyGranularityCollapsesTheWindowIntoOnePoint(): void
    {
        $this->connection->insert('book_reading_sessions', ['id' => 'r-1', 'book_id' => 'b-1', 'date' => '2026-07-08', 'pages_read' => 40]);
        $this->connection->insert('book_reading_sessions', ['id' => 'r-2', 'book_id' => 'b-1', 'date' => '2026-07-28', 'pages_read' => 10]);

        $this->get('/api/v1/trends?granularity=month&from=2026-07-01&to=2026-07-31');
        $body = $this->jsonResponse($this->client);

        self::assertSame('month', $body['granularity']);
        self::assertEquals([['bucketStart' => '2026-07-01', 'value' => 50.0]], $this->seriesOf($body, MetricType::BOOKS_PAGES_READ)['points']);
    }

    /**
     * A bare call is useful on its own: 12 weekly buckets ending today.
     */
    public function testDefaultsToTheLastTwelveWeeklyBuckets(): void
    {
        $this->get('/api/v1/trends');
        self::assertResponseIsSuccessful();

        $body = $this->jsonResponse($this->client);
        self::assertSame('week', $body['granularity']);
        self::assertCount(12, $this->seriesOf($body, MetricType::BOOKS_PAGES_READ)['points']);
    }

    public function testTheCompletionRateIsAPercentageAndKeepsItsUnit(): void
    {
        $this->connection->insert('tasks', ['id' => 't-1', 'title' => 'A', 'status' => 'completed', 'time_start' => '2026-07-08 09:00:00', 'time_end' => '2026-07-08 10:00:00']);
        $this->connection->insert('tasks', ['id' => 't-2', 'title' => 'B', 'status' => 'pending', 'time_start' => '2026-07-08 11:00:00', 'time_end' => '2026-07-08 12:00:00']);

        $this->get('/api/v1/trends?granularity=month&from=2026-07-01&to=2026-07-31');
        $rate = $this->seriesOf($this->jsonResponse($this->client), MetricType::TASKS_COMPLETION_RATE);

        self::assertSame('percent', $rate['unit']);
        self::assertEquals(50.0, $rate['points'][0]['value']);
        self::assertEquals(50.0, $rate['headline']);
    }

    public function testEmptyDatabaseStillReturnsEveryMetric(): void
    {
        $this->get('/api/v1/trends?granularity=month&from=2026-07-01&to=2026-07-31');
        self::assertResponseIsSuccessful();

        $body = $this->jsonResponse($this->client);
        foreach ($body['series'] as $series) {
            self::assertEquals(0.0, $series['total'], $series['metric']);
            self::assertEquals([['bucketStart' => '2026-07-01', 'value' => 0.0]], $series['points'], $series['metric']);
        }
    }

    /**
     * The acceptance criterion "pusta metryka", proven against the real wiring
     * rather than a stubbed provider: one source table is made unreadable, so its
     * adapter genuinely throws. That metric must come back with an empty point
     * list — the agreed "unavailable" signal — while the other four still carry
     * their data and the response stays a 200.
     */
    public function testAnUnreadableSourceEmptiesOnlyItsOwnSeries(): void
    {
        $this->connection->insert('book_reading_sessions', ['id' => 'r-1', 'book_id' => 'b-1', 'date' => '2026-07-08', 'pages_read' => 40]);
        $this->connection->executeStatement('RENAME TABLE videos TO videos_unavailable_probe');

        try {
            $this->get('/api/v1/trends?granularity=month&from=2026-07-01&to=2026-07-31');
            self::assertResponseIsSuccessful();

            $body = $this->jsonResponse($this->client);
            self::assertCount(count(MetricType::cases()), $body['series']);

            $youtube = $this->seriesOf($body, MetricType::YOUTUBE_MINUTES_WATCHED);
            self::assertSame([], $youtube['points'], 'an unreadable metric reports no points');
            self::assertEquals(0.0, $youtube['total']);
            self::assertSame('minutes', $youtube['unit'], 'the unit stays known even when the read failed');

            $pages = $this->seriesOf($body, MetricType::BOOKS_PAGES_READ);
            self::assertEquals(40.0, $pages['total'], 'the healthy metrics are unaffected');
            self::assertNotSame([], $pages['points']);
        } finally {
            $this->connection->executeStatement('RENAME TABLE videos_unavailable_probe TO videos');
        }
    }

    public function testUnknownGranularityIs422(): void
    {
        $this->get('/api/v1/trends?granularity=fortnight');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('Unknown granularity', (string) $this->jsonResponse($this->client)['error']);
    }

    public function testUnparsableDateIs422(): void
    {
        $this->get('/api/v1/trends?from=not-a-date');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('must be a valid date', (string) $this->jsonResponse($this->client)['error']);
    }

    public function testInvertedWindowIs422(): void
    {
        $this->get('/api/v1/trends?from=2026-07-31&to=2026-07-01');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('starts after it ends', (string) $this->jsonResponse($this->client)['error']);
    }

    public function testAnOverlongWindowIs422RatherThanServedSlowly(): void
    {
        $this->get('/api/v1/trends?granularity=week&from=2000-01-01&to=2026-01-01');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('more than 120 week buckets', (string) $this->jsonResponse($this->client)['error']);
    }

    public function testAliasAndVersionedPathsAgree(): void
    {
        $this->connection->insert('book_reading_sessions', ['id' => 'r-1', 'book_id' => 'b-1', 'date' => '2026-07-08', 'pages_read' => 40]);

        $this->get('/api/v1/trends?granularity=month&from=2026-07-01&to=2026-07-31');
        $versioned = $this->jsonResponse($this->client);

        $this->get('/api/trends?granularity=month&from=2026-07-01&to=2026-07-31');
        $alias = $this->jsonResponse($this->client);

        self::assertSame($versioned, $alias);
    }

    public function testRequiresTheApiKey(): void
    {
        $this->client->request('GET', '/api/v1/trends');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    private function get(string $uri): void
    {
        $this->authenticate($this->client);
        $this->client->request('GET', $uri);
    }

    /**
     * @param array<mixed> $body
     *
     * @return array<string, mixed>
     */
    private function seriesOf(array $body, MetricType $metric): array
    {
        self::assertIsArray($body['series']);
        foreach ($body['series'] as $series) {
            self::assertIsArray($series);
            if ($series['metric'] === $metric->value) {
                return $series;
            }
        }

        self::fail($metric->value.' is missing from the trends payload.');
    }
}
