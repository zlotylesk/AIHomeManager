<?php

declare(strict_types=1);

namespace App\Tests\Integration\Dashboard;

use App\Module\Dashboard\Infrastructure\Provider\CompositeDashboardDataProvider;
use App\Module\Dashboard\Infrastructure\Provider\DailyArticleAdapter;
use App\Module\Dashboard\Infrastructure\Provider\GoalsSnapshotAdapter;
use App\Module\Dashboard\Infrastructure\Provider\RecentMusicAdapter;
use App\Module\Dashboard\Infrastructure\Provider\RecommendationsAdapter;
use App\Module\Dashboard\Infrastructure\Provider\TasksTodayAdapter;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DashboardAdaptersTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'tasks', 'article_daily_picks', 'articles', 'goals', 'streaks',
            'series', 'books', 'music_listening_sessions',
        ] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function day(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-13 12:00:00');
    }

    public function testTasksAdapterReadsPendingTasksWithinDayOrderedByStart(): void
    {
        $this->connection->insert('tasks', [
            'id' => 't-1', 'title' => 'Later', 'time_start' => '2026-07-13 09:00:00', 'time_end' => '2026-07-13 10:00:00', 'status' => 'pending',
        ]);
        $this->connection->insert('tasks', [
            'id' => 't-2', 'title' => 'Earlier', 'time_start' => '2026-07-13 08:00:00', 'time_end' => '2026-07-13 08:30:00', 'status' => 'pending',
        ]);
        $this->connection->insert('tasks', [
            'id' => 't-3', 'title' => 'Done', 'time_start' => '2026-07-13 07:00:00', 'time_end' => '2026-07-13 07:30:00', 'status' => 'completed',
        ]);
        $this->connection->insert('tasks', [
            'id' => 't-4', 'title' => 'Tomorrow', 'time_start' => '2026-07-14 09:00:00', 'time_end' => '2026-07-14 10:00:00', 'status' => 'pending',
        ]);

        $tasks = new TasksTodayAdapter($this->connection)->todaysTasks($this->day());

        self::assertCount(2, $tasks);
        self::assertSame('Earlier', $tasks[0]->title);
        self::assertSame('t-2', $tasks[0]->id);
        self::assertSame('08:00', $tasks[0]->startsAt->format('H:i'));
        self::assertSame('Later', $tasks[1]->title);
    }

    public function testDailyArticleAdapterReadsTodaysPickJoiningArticle(): void
    {
        $this->connection->insert('articles', [
            'id' => 'a-1', 'title' => 'Today read', 'url' => 'https://example.test/today', 'category' => 'tech', 'estimated_read_time' => 7, 'added_at' => '2026-07-13 06:00:00', 'is_read' => 0,
        ]);
        $this->connection->insert('articles', [
            'id' => 'a-2', 'title' => 'Yesterday', 'url' => 'https://example.test/yesterday', 'added_at' => '2026-07-12 06:00:00', 'is_read' => 1,
        ]);
        $this->connection->insert('article_daily_picks', [
            'id' => 'p-1', 'article_id' => 'a-1', 'picked_at' => '2026-07-13 05:00:00',
        ]);
        $this->connection->insert('article_daily_picks', [
            'id' => 'p-2', 'article_id' => 'a-2', 'picked_at' => '2026-07-12 05:00:00',
        ]);

        $article = new DailyArticleAdapter($this->connection)->dailyArticle($this->day());

        self::assertNotNull($article);
        self::assertSame('Today read', $article->title);
        self::assertSame('https://example.test/today', $article->url);
        self::assertSame('tech', $article->category);
        self::assertSame(7, $article->estimatedReadTime);
        self::assertFalse($article->isRead);
    }

    public function testDailyArticleAdapterReturnsNullWhenNoPickForDay(): void
    {
        self::assertNull(new DailyArticleAdapter($this->connection)->dailyArticle($this->day()));
    }

    public function testGoalsSnapshotAdapterJoinsPersistedStreaks(): void
    {
        $this->connection->insert('goals', [
            'id' => 'g-1', 'type' => 'book_pages', 'target_value' => 50, 'period' => 'daily',
        ]);
        $this->connection->insert('goals', [
            'id' => 'g-2', 'type' => 'articles_read', 'target_value' => 3, 'period' => 'weekly',
        ]);
        $this->connection->insert('streaks', [
            'id' => 's-1', 'type' => 'book_pages', 'current_length' => 5, 'longest_length' => 9, 'last_activity_date' => '2026-07-12 00:00:00',
        ]);

        $snapshots = new GoalsSnapshotAdapter($this->connection)->goalSnapshots();

        self::assertCount(2, $snapshots);
        // Ordered by type ASC: articles_read (no streak) then book_pages.
        self::assertSame('articles_read', $snapshots[0]->type);
        self::assertSame(3, $snapshots[0]->target);
        self::assertSame('weekly', $snapshots[0]->period);
        self::assertSame(0, $snapshots[0]->currentStreak);
        self::assertSame(0, $snapshots[0]->longestStreak);
        self::assertNull($snapshots[0]->lastActivityDate);

        self::assertSame('book_pages', $snapshots[1]->type);
        self::assertSame(5, $snapshots[1]->currentStreak);
        self::assertSame(9, $snapshots[1]->longestStreak);
        self::assertNotNull($snapshots[1]->lastActivityDate);
        self::assertSame('2026-07-12', $snapshots[1]->lastActivityDate->format('Y-m-d'));
    }

    public function testRecommendationsAdapterReadsOngoingSeriesAndReadingBooks(): void
    {
        $this->connection->insert('series', [
            'id' => 'se-1', 'title' => 'Ongoing Show', 'created_at' => '2026-07-01 00:00:00', 'status' => 'ongoing', 'year' => 2020, 'cover_url' => 'https://img.test/s.jpg',
        ]);
        $this->connection->insert('series', [
            'id' => 'se-2', 'title' => 'Ended Show', 'created_at' => '2026-07-02 00:00:00', 'status' => 'ended',
        ]);
        $this->connection->insert('books', [
            'id' => 'bo-1', 'isbn' => '9780000000001', 'title' => 'Reading Book', 'author' => 'Jane Doe', 'publisher' => 'Pub', 'year' => 2019, 'current_page' => 40, 'total_pages' => 200, 'status' => 'reading', 'cover_url' => 'https://img.test/b.jpg',
        ]);
        $this->connection->insert('books', [
            'id' => 'bo-2', 'isbn' => '9780000000002', 'title' => 'Backlog Book', 'author' => 'John Roe', 'publisher' => 'Pub', 'year' => 2018, 'current_page' => 0, 'total_pages' => 100, 'status' => 'to_read',
        ]);

        $recommendations = new RecommendationsAdapter($this->connection)->recommendations(5);

        self::assertCount(2, $recommendations);
        self::assertSame('series', $recommendations[0]->kind);
        self::assertSame('Ongoing Show', $recommendations[0]->title);
        self::assertSame('2020', $recommendations[0]->detail);
        self::assertSame('https://img.test/s.jpg', $recommendations[0]->coverUrl);
        self::assertSame('book', $recommendations[1]->kind);
        self::assertSame('Reading Book', $recommendations[1]->title);
        self::assertSame('Jane Doe', $recommendations[1]->detail);
    }

    public function testRecentMusicAdapterReadsLatestFirstLimited(): void
    {
        $this->connection->insert('music_listening_sessions', [
            'id' => 'm-1', 'artist' => 'Artist A', 'title' => 'Older', 'played_at' => '2026-07-13 10:00:00', 'source' => 'lastfm_scrobble', 'dedup_hash' => 'h1', 'created_at' => '2026-07-13 10:00:00',
        ]);
        $this->connection->insert('music_listening_sessions', [
            'id' => 'm-2', 'artist' => 'Artist B', 'title' => 'Newest', 'played_at' => '2026-07-13 12:00:00', 'source' => 'manual', 'dedup_hash' => 'h2', 'created_at' => '2026-07-13 12:00:00',
        ]);
        $this->connection->insert('music_listening_sessions', [
            'id' => 'm-3', 'artist' => 'Artist C', 'title' => 'Middle', 'played_at' => '2026-07-13 11:00:00', 'source' => 'lastfm_scrobble', 'dedup_hash' => 'h3', 'created_at' => '2026-07-13 11:00:00',
        ]);

        $tracks = new RecentMusicAdapter($this->connection)->recentTracks(2);

        self::assertCount(2, $tracks);
        self::assertSame('Newest', $tracks[0]->title);
        self::assertSame('Artist B', $tracks[0]->artist);
        self::assertSame('manual', $tracks[0]->source);
        self::assertSame('Middle', $tracks[1]->title);
    }

    public function testCompositeExposesEveryWidgetFragment(): void
    {
        $this->connection->insert('tasks', [
            'id' => 't-1', 'title' => 'Standup', 'time_start' => '2026-07-13 09:00:00', 'time_end' => '2026-07-13 09:15:00', 'status' => 'pending',
        ]);
        $this->connection->insert('articles', [
            'id' => 'a-1', 'title' => 'Article', 'url' => 'https://example.test/x', 'added_at' => '2026-07-13 06:00:00', 'is_read' => 0,
        ]);
        $this->connection->insert('article_daily_picks', [
            'id' => 'p-1', 'article_id' => 'a-1', 'picked_at' => '2026-07-13 05:00:00',
        ]);
        $this->connection->insert('goals', [
            'id' => 'g-1', 'type' => 'book_pages', 'target_value' => 50, 'period' => 'daily',
        ]);
        $this->connection->insert('series', [
            'id' => 'se-1', 'title' => 'Ongoing Show', 'created_at' => '2026-07-01 00:00:00', 'status' => 'ongoing',
        ]);
        $this->connection->insert('music_listening_sessions', [
            'id' => 'm-1', 'artist' => 'Artist', 'title' => 'Track', 'played_at' => '2026-07-13 12:00:00', 'source' => 'manual', 'dedup_hash' => 'h1', 'created_at' => '2026-07-13 12:00:00',
        ]);

        $provider = new CompositeDashboardDataProvider(
            new TasksTodayAdapter($this->connection),
            new DailyArticleAdapter($this->connection),
            new GoalsSnapshotAdapter($this->connection),
            new RecommendationsAdapter($this->connection),
            new RecentMusicAdapter($this->connection),
        );

        $day = $this->day();
        self::assertCount(1, $provider->todaysTasks($day));
        self::assertNotNull($provider->dailyArticle($day));
        self::assertCount(1, $provider->goalSnapshots());
        self::assertCount(1, $provider->recommendations(5));
        self::assertCount(1, $provider->recentTracks(5));
    }
}
