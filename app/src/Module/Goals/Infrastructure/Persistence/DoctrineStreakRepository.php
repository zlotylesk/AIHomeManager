<?php

declare(strict_types=1);

namespace App\Module\Goals\Infrastructure\Persistence;

use App\Module\Goals\Domain\Entity\Streak;
use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Repository\StreakRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineStreakRepository implements StreakRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Streak $streak): void
    {
        $this->entityManager->persist($streak);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?Streak
    {
        return $this->entityManager->find(Streak::class, $id);
    }

    public function findByType(GoalType $type): ?Streak
    {
        return $this->entityManager->createQuery(
            'SELECT s FROM '.Streak::class.' s WHERE s.type = :type'
        )
            ->setParameter('type', $type->value)
            ->getOneOrNullResult();
    }

    /** @return Streak[] */
    public function findAll(): array
    {
        return $this->entityManager->createQuery('SELECT s FROM '.Streak::class.' s')->getResult();
    }
}
