<?php

declare(strict_types=1);

namespace App\Tests\Integration\Goals;

use App\Module\Goals\Application\Query\GetGoalsProgress;
use App\Module\Goals\Application\Query\GetStreaks;
use App\Module\Goals\Application\QueryHandler\GetGoalsProgressHandler;
use App\Module\Goals\Application\QueryHandler\GetStreaksHandler;
use App\Module\Goals\Domain\Port\ActivityProviderInterface;
use App\Module\Goals\Domain\Service\GoalProgressCalculator;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GoalsProgressQueryTest extends KernelTestCase
{
    private Connection $connection;
    private ActivityProviderInterface $activityProvider;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->connection = $container->get(EntityManagerInterface::class)->getConnection();
        $this->activityProvider = $container->get(ActivityProviderInterface::class);

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['goals', 'book_reading_sessions', 'series_episodes', 'articles', 'videos'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testProgressReflectsTodaysActivityThroughTheProvider(): void
    {
        $this->connection->insert('goals', [
            'id' => 'goal-progress-1', 'type' => 'book_pages', 'target_value' => 50, 'period' => 'daily',
        ]);
        $this->connection->insert('book_reading_sessions', [
            'id' => 'sess-1', 'book_id' => 'book-1',
            'date' => new DateTimeImmutable('today')->format('Y-m-d'), 'pages_read' => 30,
        ]);

        $handler = new GetGoalsProgressHandler($this->connection, $this->activityProvider, new GoalProgressCalculator());
        $result = $handler(new GetGoalsProgress());

        self::assertCount(1, $result);
        self::assertSame('goal-progress-1', $result[0]->goalId);
        self::assertSame('book_pages', $result[0]->type);
        self::assertSame('daily', $result[0]->period);
        self::assertSame(30, $result[0]->achieved);
        self::assertSame(60, $result[0]->percent);
        self::assertFalse($result[0]->met);
    }

    public function testStreakReflectsConsecutiveActivityDaysThroughTheProvider(): void
    {
        $this->connection->insert('goals', [
            'id' => 'goal-streak-1', 'type' => 'book_pages', 'target_value' => 10, 'period' => 'daily',
        ]);
        $today = new DateTimeImmutable('today');
        foreach (['sess-a' => $today->modify('-1 day'), 'sess-b' => $today] as $id => $date) {
            $this->connection->insert('book_reading_sessions', [
                'id' => $id, 'book_id' => 'book-1', 'date' => $date->format('Y-m-d'), 'pages_read' => 5,
            ]);
        }

        $handler = new GetStreaksHandler($this->connection, $this->activityProvider, new GoalProgressCalculator());
        $result = $handler(new GetStreaks());

        self::assertCount(1, $result);
        self::assertSame('book_pages', $result[0]->type);
        self::assertSame(2, $result[0]->currentLength);
        self::assertSame(2, $result[0]->longestLength);
        self::assertSame($today->format('Y-m-d'), $result[0]->lastActivityDate);
    }

    public function testNoGoalsYieldsEmptyResult(): void
    {
        $progress = new GetGoalsProgressHandler($this->connection, $this->activityProvider, new GoalProgressCalculator());
        $streaks = new GetStreaksHandler($this->connection, $this->activityProvider, new GoalProgressCalculator());

        self::assertSame([], $progress(new GetGoalsProgress()));
        self::assertSame([], $streaks(new GetStreaks()));
    }
}
