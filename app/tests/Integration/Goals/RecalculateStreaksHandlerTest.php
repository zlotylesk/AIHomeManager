<?php

declare(strict_types=1);

namespace App\Tests\Integration\Goals;

use App\Module\Goals\Application\Command\RecalculateStreaks;
use App\Module\Goals\Application\CommandHandler\RecalculateStreaksHandler;
use App\Module\Goals\Domain\Entity\Streak;
use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Port\ActivityProviderInterface;
use App\Module\Goals\Domain\Service\GoalProgressCalculator;
use App\Module\Goals\Infrastructure\Persistence\DoctrineStreakRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecalculateStreaksHandlerTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private DoctrineStreakRepository $streaks;
    private ActivityProviderInterface $activityProvider;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();
        $this->streaks = new DoctrineStreakRepository($this->em);
        $this->activityProvider = $container->get(ActivityProviderInterface::class);

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['goals', 'streaks', 'book_reading_sessions', 'series_episodes', 'articles', 'videos'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function handler(): RecalculateStreaksHandler
    {
        return new RecalculateStreaksHandler(
            $this->connection,
            $this->activityProvider,
            new GoalProgressCalculator(),
            $this->streaks,
        );
    }

    private function seedBookGoal(): void
    {
        $this->connection->insert('goals', [
            'id' => 'goal-1', 'type' => 'book_pages', 'target_value' => 50, 'period' => 'daily',
        ]);
    }

    private function insertReadingSession(string $id, DateTimeImmutable $date): void
    {
        $this->connection->insert('book_reading_sessions', [
            'id' => $id, 'book_id' => 'book-1', 'date' => $date->format('Y-m-d'), 'pages_read' => 10,
        ]);
    }

    public function testPersistsStreakFoldedFromActivity(): void
    {
        $this->seedBookGoal();
        $today = new DateTimeImmutable('today');
        $this->insertReadingSession('s-a', $today->modify('-1 day'));
        $this->insertReadingSession('s-b', $today);

        $this->handler()(new RecalculateStreaks());
        $this->em->clear();

        $streak = $this->streaks->findByType(GoalType::BOOK_PAGES);
        self::assertNotNull($streak);
        self::assertSame(2, $streak->currentLength());
        self::assertSame(2, $streak->longestLength());
        self::assertSame($today->format('Y-m-d'), $streak->lastActivityDate()?->format('Y-m-d'));
    }

    public function testIsIdempotentAcrossReruns(): void
    {
        $this->seedBookGoal();
        $today = new DateTimeImmutable('today');
        $this->insertReadingSession('s-a', $today);

        $this->handler()(new RecalculateStreaks());
        $this->em->clear();
        $this->handler()(new RecalculateStreaks());
        $this->em->clear();

        $rows = $this->connection->fetchAllAssociative('SELECT type, current_length, longest_length FROM streaks');
        self::assertCount(1, $rows);
        self::assertSame('book_pages', $rows[0]['type']);
        self::assertSame(1, (int) $rows[0]['current_length']);
        self::assertSame(1, (int) $rows[0]['longest_length']);
    }

    public function testPreservesAllTimeLongestBeyondTheWindow(): void
    {
        $this->seedBookGoal();
        // A previously-recorded, much longer run (now outside the observed window).
        $this->streaks->save(new Streak('streak-1', GoalType::BOOK_PAGES, 0, 10, new DateTimeImmutable('2024-01-10')));
        $this->em->clear();

        // Fresh activity giving only a short current run.
        $today = new DateTimeImmutable('today');
        $this->insertReadingSession('s-a', $today);

        $this->handler()(new RecalculateStreaks());
        $this->em->clear();

        $streak = $this->streaks->findByType(GoalType::BOOK_PAGES);
        self::assertNotNull($streak);
        self::assertSame(1, $streak->currentLength());
        self::assertSame(10, $streak->longestLength(), 'The all-time longest run must survive a recompute over a shorter window.');
    }

    public function testDoesNothingWhenNoGoalsDefined(): void
    {
        $this->handler()(new RecalculateStreaks());

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM streaks'));
    }
}
