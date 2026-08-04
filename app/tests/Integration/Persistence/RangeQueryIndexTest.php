<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Module\Goals\Infrastructure\Activity\ArticlesActivityAdapter;
use App\Module\Goals\Infrastructure\Activity\BooksActivityAdapter;
use App\Module\Goals\Infrastructure\Activity\SeriesActivityAdapter;
use App\Module\Insights\Domain\Enum\Granularity;
use App\Module\Insights\Domain\Enum\MetricType;
use App\Module\Insights\Infrastructure\Provider\BooksPagesReadAdapter;
use App\Module\Insights\Infrastructure\Provider\SeriesEpisodesWatchedAdapter;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * HMAI-413: the cross-module activity readers must not full-scan their source
 * tables.
 *
 * Goals, Insights and the cockpit all read `articles`, `series_episodes` and
 * `book_reading_sessions` by a date range on every page load plus in the nightly
 * recompute, and none of those three columns carried an index — so each read was
 * a full table scan whose cost grows with the library while the answer stays
 * correct. That is the failure mode worth pinning: nothing breaks, it just gets
 * slower forever, so no functional test would ever notice.
 *
 * These tests therefore assert on what the adapters *do*, not on what the schema
 * says. Each one runs the real adapter and reads MySQL's own
 * `Handler_read_rnd_next` counter, which counts rows read via a full scan — so a
 * predicate edited into a shape the index no longer serves fails here even
 * though the adapter still returns the right rows. Restating the SQL in the test
 * instead would have pinned a query the adapter no longer runs.
 */
final class RangeQueryIndexTest extends KernelTestCase
{
    /**
     * Enough rows that a scan is measurably distinct from an index read, and
     * few enough to seed in one statement. Measured margin at this size: 0–21
     * rows scanned with the indexes against ~500 without them.
     */
    private const int SEEDED_ROWS = 500;

    /** Spread the seeded rows across five years, so the read window is a real slice. */
    private const int SPREAD_DAYS = 1825;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['articles', 'series_episodes', 'book_reading_sessions'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $this->seed();
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function indexedColumnsProvider(): iterable
    {
        // The column ORDER is the point, not just the presence: two of the three
        // predicates filter an equality column and then a range, and only an
        // index leading with the equality column can serve both halves.
        yield 'articles: is_read = 1 AND read_at BETWEEN' => [
            'articles', 'idx_article_is_read_read_at', ['is_read', 'read_at'],
        ];
        yield 'series_episodes: watched = 1 AND watched_at BETWEEN' => [
            'series_episodes', 'idx_episode_watched_watched_at', ['watched', 'watched_at'],
        ];
        // No equality half here, so `date` leads. `pages_read` is payload rather
        // than a filter: it is the only other column these readers select, so
        // carrying it makes the index covering for 4 bytes a row.
        yield 'book_reading_sessions: date BETWEEN' => [
            'book_reading_sessions', 'idx_reading_sessions_date_pages_read', ['date', 'pages_read'],
        ];
    }

    /**
     * @param list<string> $expectedColumns
     */
    #[DataProvider('indexedColumnsProvider')]
    public function testTheRangeFilteredColumnsAreIndexedInOrder(string $table, string $index, array $expectedColumns): void
    {
        $columns = $this->connection->fetchFirstColumn(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index
             ORDER BY SEQ_IN_INDEX',
            ['table' => $table, 'index' => $index],
        );

        self::assertSame($expectedColumns, $columns, sprintf('%s.%s must index exactly %s, in that order.', $table, $index, implode(', ', $expectedColumns)));
    }

    public function testGoalsSeriesActivityDoesNotScanTheEpisodesTable(): void
    {
        $adapter = static::getContainer()->get(SeriesActivityAdapter::class);

        $scanned = $this->rowsScannedDuring(fn () => $adapter->activityBetween(...$this->streakWindow()));

        $this->assertDidNotScanTheTable($scanned, 'series_episodes');
    }

    public function testGoalsBooksActivityDoesNotScanTheReadingSessionsTable(): void
    {
        $adapter = static::getContainer()->get(BooksActivityAdapter::class);

        $scanned = $this->rowsScannedDuring(fn () => $adapter->activityBetween(...$this->streakWindow()));

        $this->assertDidNotScanTheTable($scanned, 'book_reading_sessions');
    }

    public function testGoalsArticlesActivityDoesNotScanTheArticlesTable(): void
    {
        $adapter = static::getContainer()->get(ArticlesActivityAdapter::class);

        $scanned = $this->rowsScannedDuring(fn () => $adapter->activityBetween(...$this->streakWindow()));

        $this->assertDidNotScanTheTable($scanned, 'articles');
    }

    public function testInsightsEpisodesTrendDoesNotScanTheEpisodesTable(): void
    {
        $adapter = static::getContainer()->get(SeriesEpisodesWatchedAdapter::class);

        $scanned = $this->rowsScannedDuring(fn () => $adapter->seriesFor(
            MetricType::SERIES_EPISODES_WATCHED,
            Granularity::WEEK,
            ...$this->trendWindow(),
        ));

        $this->assertDidNotScanTheTable($scanned, 'series_episodes');
    }

    public function testInsightsReadingPaceDoesNotScanTheReadingSessionsTable(): void
    {
        $adapter = static::getContainer()->get(BooksPagesReadAdapter::class);

        $scanned = $this->rowsScannedDuring(fn () => $adapter->seriesFor(
            MetricType::BOOKS_PAGES_READ,
            Granularity::WEEK,
            ...$this->trendWindow(),
        ));

        $this->assertDidNotScanTheTable($scanned, 'book_reading_sessions');
    }

    /**
     * The 365-day lookback the streak read uses.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function streakWindow(): array
    {
        return [new DateTimeImmutable('-365 days'), new DateTimeImmutable('now')];
    }

    /**
     * The Insights default: 12 weekly buckets.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function trendWindow(): array
    {
        return [new DateTimeImmutable('-84 days'), new DateTimeImmutable('now')];
    }

    private function assertDidNotScanTheTable(int $scanned, string $table): void
    {
        // The claim is "it did not read every row", not "it read N rows" — an
        // exact count would break on any optimizer or data change while telling
        // us nothing more. A grouped read scans its own small temporary table
        // (~one row per bucket), which is why the bound is the table size rather
        // than zero.
        self::assertLessThan(
            self::SEEDED_ROWS,
            $scanned,
            sprintf('Reading %s scanned %d rows out of %d — that is a full table scan, so the query is no longer served by its index.', $table, $scanned, self::SEEDED_ROWS),
        );
    }

    /**
     * Rows MySQL read by walking a table end to end while $work ran.
     *
     * `Handler_read_rnd_next` is incremented once per row fetched from a full
     * scan and left alone by an index range read, so the delta across a call is
     * a direct measurement of the thing this ticket removed. It is session
     * scoped, which is why it must be read on the same connection the adapter
     * used — the one the container injected.
     */
    private function rowsScannedDuring(callable $work): int
    {
        $before = $this->fullScanCounter();
        $work();

        return $this->fullScanCounter() - $before;
    }

    private function fullScanCounter(): int
    {
        $row = $this->connection->fetchAssociative("SHOW SESSION STATUS LIKE 'Handler_read_rnd_next'");
        self::assertIsArray($row, 'MySQL did not report Handler_read_rnd_next.');

        return (int) $row['Value'];
    }

    private function seed(): void
    {
        $rows = self::SEEDED_ROWS;
        $spread = self::SPREAD_DAYS;
        // A recursive CTE keeps the seed to one round trip per table. Every third
        // row is left unread/unwatched, so the equality half of the predicate has
        // something to exclude rather than matching the whole table.
        $numbers = "(WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < $rows) SELECT n FROM s) g";

        $this->connection->executeStatement(
            "INSERT INTO articles (id, title, url, category, estimated_read_time, added_at, read_at, is_read)
             SELECT CONCAT('idx-a-', n), CONCAT('Article ', n), CONCAT('https://index.test/', n), 'tech', 5,
                    DATE_SUB(NOW(), INTERVAL (n % $spread) DAY),
                    IF(n % 3 = 0, NULL, DATE_SUB(NOW(), INTERVAL (n % $spread) DAY)),
                    IF(n % 3 = 0, 0, 1)
             FROM $numbers"
        );

        $this->connection->executeStatement(
            "INSERT INTO series_episodes (id, season_id, title, number, watched, watched_at, rating_value)
             SELECT CONCAT('idx-e-', n), 'idx-season', CONCAT('Episode ', n), n,
                    IF(n % 3 = 0, 0, 1),
                    IF(n % 3 = 0, NULL, DATE_SUB(NOW(), INTERVAL (n % $spread) DAY)),
                    NULL
             FROM $numbers"
        );

        $this->connection->executeStatement(
            "INSERT INTO book_reading_sessions (id, book_id, date, pages_read, notes)
             SELECT CONCAT('idx-r-', n), 'idx-book', DATE_SUB(CURDATE(), INTERVAL (n % $spread) DAY), 10, NULL
             FROM $numbers"
        );

        // Without fresh statistics the optimizer may still be working from the
        // empty table it saw a moment ago and pick a scan for reasons that have
        // nothing to do with the index. ANALYZE returns a result set, so it has
        // to be fetched rather than merely executed.
        $this->connection->fetchAllAssociative('ANALYZE TABLE articles, series_episodes, book_reading_sessions');
    }
}
