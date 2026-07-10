<?php

declare(strict_types=1);

namespace App\Tests\Integration\Goals;

use App\Module\Goals\Domain\Entity\Goal;
use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Enum\Period;
use App\Module\Goals\Domain\ValueObject\GoalTarget;
use App\Module\Goals\Infrastructure\Persistence\DoctrineGoalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GoalRepositoryTest extends KernelTestCase
{
    private DoctrineGoalRepository $repository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = new DoctrineGoalRepository($this->em);

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE goals');
    }

    public function testSaveAndFindByIdRoundTripsEnumsAndEmbeddedTarget(): void
    {
        $this->repository->save(new Goal(
            'g0000001-0000-0000-0000-000000000001',
            GoalType::BOOK_PAGES,
            new GoalTarget(50),
            Period::WEEKLY,
        ));
        $this->em->clear();

        $found = $this->repository->findById('g0000001-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertSame(GoalType::BOOK_PAGES, $found->type());
        self::assertSame(50, $found->target()->value());
        self::assertSame(Period::WEEKLY, $found->period());
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        self::assertNull($this->repository->findById('00000000-0000-0000-0000-000000000000'));
    }

    public function testSavePersistsUpdatedTargetAndPeriod(): void
    {
        $goal = new Goal(
            'g0000002-0000-0000-0000-000000000001',
            GoalType::SERIES_EPISODES,
            new GoalTarget(3),
            Period::DAILY,
        );
        $this->repository->save($goal);
        $this->em->clear();

        $loaded = $this->repository->findById('g0000002-0000-0000-0000-000000000001');
        self::assertNotNull($loaded);
        $loaded->changeTarget(new GoalTarget(10));
        $loaded->reschedule(Period::MONTHLY);
        $this->repository->save($loaded);
        $this->em->clear();

        $reloaded = $this->repository->findById('g0000002-0000-0000-0000-000000000001');
        self::assertNotNull($reloaded);
        self::assertSame(10, $reloaded->target()->value());
        self::assertSame(Period::MONTHLY, $reloaded->period());
    }

    public function testFindAllReturnsAllSavedGoals(): void
    {
        $this->repository->save(new Goal('g0000003-0000-0000-0000-000000000001', GoalType::ARTICLES_READ, new GoalTarget(5), Period::WEEKLY));
        $this->repository->save(new Goal('g0000003-0000-0000-0000-000000000002', GoalType::YOUTUBE_VIDEOS, new GoalTarget(2), Period::DAILY));
        $this->em->clear();

        self::assertCount(2, $this->repository->findAll());
    }

    public function testRemoveDeletesGoal(): void
    {
        $goal = new Goal('g0000004-0000-0000-0000-000000000001', GoalType::MUSIC_ALBUMS, new GoalTarget(1), Period::MONTHLY);
        $this->repository->save($goal);
        $this->em->clear();

        $loaded = $this->repository->findById('g0000004-0000-0000-0000-000000000001');
        self::assertNotNull($loaded);
        $this->repository->remove($loaded);
        $this->em->clear();

        self::assertNull($this->repository->findById('g0000004-0000-0000-0000-000000000001'));
    }
}
