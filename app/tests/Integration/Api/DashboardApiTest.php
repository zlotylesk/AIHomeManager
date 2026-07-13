<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Support\AuthenticatedApiTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP contract for the cockpit endpoint (HMAI-260): GET /api/dashboard returns
 * the full composed read-model (every widget section) for "today", requires the
 * API key, and resolves under both the versioned and alias prefixes.
 */
final class DashboardApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
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

    /**
     * The cockpit reads "today" from a real clock, so the fixtures are dated to
     * the current day.
     */
    private function seedToday(): string
    {
        $day = new DateTimeImmutable('today')->format('Y-m-d');

        $this->connection->insert('tasks', [
            'id' => 't-1', 'title' => 'Standup', 'time_start' => $day.' 09:00:00', 'time_end' => $day.' 09:15:00', 'status' => 'pending',
        ]);
        $this->connection->insert('articles', [
            'id' => 'a-1', 'title' => 'Article of the day', 'url' => 'https://example.test/a', 'added_at' => $day.' 06:00:00', 'is_read' => 0,
        ]);
        $this->connection->insert('article_daily_picks', [
            'id' => 'p-1', 'article_id' => 'a-1', 'picked_at' => $day.' 05:00:00',
        ]);
        $this->connection->insert('goals', [
            'id' => 'g-1', 'type' => 'book_pages', 'target_value' => 50, 'period' => 'daily',
        ]);
        $this->connection->insert('series', [
            'id' => 'se-1', 'title' => 'Ongoing Show', 'created_at' => $day.' 00:00:00', 'status' => 'ongoing',
        ]);
        $this->connection->insert('music_listening_sessions', [
            'id' => 'm-1', 'artist' => 'Artist', 'title' => 'Track', 'played_at' => $day.' 08:00:00', 'source' => 'manual', 'dedup_hash' => 'h1', 'created_at' => $day.' 08:00:00',
        ]);

        return $day;
    }

    public function testReturnsFullCockpitReadModel(): void
    {
        $day = $this->seedToday();

        $this->client->request('GET', '/api/dashboard');
        self::assertResponseIsSuccessful();

        $data = $this->jsonResponse($this->client);

        self::assertSame($day, $data['date']);

        self::assertCount(1, $data['tasks']);
        self::assertSame('Standup', $data['tasks'][0]['title']);
        self::assertArrayHasKey('startsAt', $data['tasks'][0]);

        self::assertNotNull($data['article']);
        self::assertSame('Article of the day', $data['article']['title']);
        self::assertFalse($data['article']['isRead']);

        self::assertCount(1, $data['goals']);
        self::assertSame('book_pages', $data['goals'][0]['type']);

        self::assertCount(1, $data['recommendations']);
        self::assertSame('series', $data['recommendations'][0]['kind']);
        self::assertSame('Ongoing Show', $data['recommendations'][0]['title']);

        self::assertCount(1, $data['recentTracks']);
        self::assertSame('Track', $data['recentTracks'][0]['title']);
    }

    public function testEmptyStateReturnsEmptySections(): void
    {
        $this->client->request('GET', '/api/dashboard');
        self::assertResponseIsSuccessful();

        $data = $this->jsonResponse($this->client);

        self::assertSame(new DateTimeImmutable('today')->format('Y-m-d'), $data['date']);
        self::assertSame([], $data['tasks']);
        self::assertNull($data['article']);
        self::assertSame([], $data['goals']);
        self::assertSame([], $data['recommendations']);
        self::assertSame([], $data['recentTracks']);
    }

    public function testVersionedAndAliasRoutesBothResolve(): void
    {
        $this->seedToday();

        $this->client->request('GET', '/api/v1/dashboard');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->jsonResponse($this->client)['tasks']);
    }

    public function testRejectsInvalidApiKey(): void
    {
        $this->client->setServerParameter('HTTP_X_API_KEY', 'wrong-key');
        $this->client->request('GET', '/api/dashboard');

        self::assertResponseStatusCodeSame(401);
    }
}
