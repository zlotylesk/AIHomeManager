<?php

declare(strict_types=1);

namespace App\Tests\Integration\Goals;

use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Infrastructure\Activity\ArticlesActivityAdapter;
use App\Module\Goals\Infrastructure\Activity\BooksActivityAdapter;
use App\Module\Goals\Infrastructure\Activity\SeriesActivityAdapter;
use App\Module\Goals\Infrastructure\Activity\YouTubeActivityAdapter;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ActivityAdaptersTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['book_reading_sessions', 'series_episodes', 'articles', 'videos'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function window(): array
    {
        return [new DateTimeImmutable('2026-07-01 00:00:00'), new DateTimeImmutable('2026-07-31 23:59:59')];
    }

    public function testBooksAdapterReadsPagesReadWithinWindowOnly(): void
    {
        $this->connection->insert('book_reading_sessions', [
            'id' => 'r-1', 'book_id' => 'b-1', 'date' => '2026-07-10', 'pages_read' => 42,
        ]);
        $this->connection->insert('book_reading_sessions', [
            'id' => 'r-2', 'book_id' => 'b-1', 'date' => '2026-08-10', 'pages_read' => 99,
        ]);

        [$from, $to] = $this->window();
        $events = new BooksActivityAdapter($this->connection)->activityBetween($from, $to);

        self::assertCount(1, $events);
        self::assertSame(GoalType::BOOK_PAGES, $events[0]->type);
        self::assertSame(42, $events[0]->value);
        self::assertSame('2026-07-10', $events[0]->occurredAt->format('Y-m-d'));
    }

    public function testSeriesAdapterReadsOnlyWatchedEpisodes(): void
    {
        $this->connection->insert('series_episodes', [
            'id' => 'e-1', 'season_id' => 's-1', 'title' => 'Ep 1', 'number' => 1, 'watched' => 1, 'watched_at' => '2026-07-12 20:00:00',
        ]);
        $this->connection->insert('series_episodes', [
            'id' => 'e-2', 'season_id' => 's-1', 'title' => 'Ep 2', 'number' => 2, 'watched' => 0, 'watched_at' => null,
        ]);

        [$from, $to] = $this->window();
        $events = new SeriesActivityAdapter($this->connection)->activityBetween($from, $to);

        self::assertCount(1, $events);
        self::assertSame(GoalType::SERIES_EPISODES, $events[0]->type);
        self::assertSame(1, $events[0]->value);
    }

    public function testArticlesAdapterReadsOnlyReadArticles(): void
    {
        $this->connection->insert('articles', [
            'id' => 'a-1', 'title' => 'Read one', 'url' => 'https://example.test/1', 'added_at' => '2026-07-01 00:00:00', 'is_read' => 1, 'read_at' => '2026-07-15 10:00:00',
        ]);
        $this->connection->insert('articles', [
            'id' => 'a-2', 'title' => 'Unread', 'url' => 'https://example.test/2', 'added_at' => '2026-07-01 00:00:00', 'is_read' => 0, 'read_at' => null,
        ]);

        [$from, $to] = $this->window();
        $events = new ArticlesActivityAdapter($this->connection)->activityBetween($from, $to);

        self::assertCount(1, $events);
        self::assertSame(GoalType::ARTICLES_READ, $events[0]->type);
        self::assertSame(1, $events[0]->value);
    }

    public function testYouTubeAdapterReadsOnlyWatchedVideos(): void
    {
        $this->connection->insert('videos', [
            'youtube_id' => 'v-1', 'title' => 'Watched', 'channel' => 'Chan', 'duration_seconds' => 600, 'added_to_watchlist_at' => '2026-07-01 00:00:00', 'watched_at' => '2026-07-20 18:00:00',
        ]);
        $this->connection->insert('videos', [
            'youtube_id' => 'v-2', 'title' => 'Unwatched', 'channel' => 'Chan', 'duration_seconds' => 600, 'added_to_watchlist_at' => '2026-07-01 00:00:00', 'watched_at' => null,
        ]);

        [$from, $to] = $this->window();
        $events = new YouTubeActivityAdapter($this->connection)->activityBetween($from, $to);

        self::assertCount(1, $events);
        self::assertSame(GoalType::YOUTUBE_VIDEOS, $events[0]->type);
        self::assertSame(1, $events[0]->value);
    }
}
