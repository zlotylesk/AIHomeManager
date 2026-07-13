<?php

declare(strict_types=1);

namespace App\Tests\Integration\Dashboard;

use App\Messaging\QueryBus;
use App\Module\Dashboard\Application\DTO\DashboardDTO;
use App\Module\Dashboard\Application\Query\GetDashboard;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verifies the whole HMAI-258 + HMAI-259 chain end-to-end through the container:
 * GetDashboard routed on query.bus, the port bound to the composite of DBAL
 * adapters, composed into a DashboardDTO.
 */
final class GetDashboardQueryTest extends KernelTestCase
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
        foreach ([
            'tasks', 'article_daily_picks', 'articles', 'goals', 'streaks',
            'series', 'books', 'music_listening_sessions',
        ] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testDispatchesGetDashboardThroughQueryBusAndComposesEverySection(): void
    {
        $this->connection->insert('tasks', [
            'id' => 't-1', 'title' => 'Standup', 'time_start' => '2026-07-13 09:00:00', 'time_end' => '2026-07-13 09:15:00', 'status' => 'pending',
        ]);
        $this->connection->insert('articles', [
            'id' => 'a-1', 'title' => 'Article of the day', 'url' => 'https://example.test/a', 'added_at' => '2026-07-13 06:00:00', 'is_read' => 0,
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

        $dto = $this->queryBus->ask(new GetDashboard(new DateTimeImmutable('2026-07-13 12:00:00')));

        self::assertInstanceOf(DashboardDTO::class, $dto);
        self::assertSame('2026-07-13', $dto->date);
        self::assertCount(1, $dto->tasks);
        self::assertNotNull($dto->article);
        self::assertSame('Article of the day', $dto->article->title);
        self::assertCount(1, $dto->goals);
        self::assertCount(1, $dto->recommendations);
        self::assertCount(1, $dto->recentTracks);
    }
}
