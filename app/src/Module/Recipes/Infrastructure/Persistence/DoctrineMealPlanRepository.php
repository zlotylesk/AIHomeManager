<?php

declare(strict_types=1);

namespace App\Module\Recipes\Infrastructure\Persistence;

use App\Module\Recipes\Domain\Entity\PlannedMeal;
use App\Module\Recipes\Domain\Enum\MealSlot;
use App\Module\Recipes\Domain\Repository\MealPlanRepositoryInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineMealPlanRepository implements MealPlanRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(PlannedMeal $meal): void
    {
        $this->entityManager->persist($meal);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?PlannedMeal
    {
        return $this->entityManager->find(PlannedMeal::class, $id);
    }

    public function existsFor(
        DateTimeImmutable $date,
        MealSlot $slot,
        string $recipeId,
        ?string $excludingId = null,
    ): bool {
        $dql = 'SELECT COUNT(m.id) FROM '.PlannedMeal::class.' m'
            .' WHERE m.date = :date AND m.slot = :slot AND m.recipeId = :recipeId';

        if (null !== $excludingId) {
            $dql .= ' AND m.id <> :excludingId';
        }

        $query = $this->entityManager->createQuery($dql)
            // The date type is stated rather than inferred, and that is load
            // bearing: left to infer, Doctrine binds a DateTimeImmutable as a
            // full datetime, which MySQL does NOT match against a DATE column.
            // A caller passing "today" straight from a clock would then be told
            // no duplicate exists, and the insert behind that answer would hit
            // the unique index — a readable conflict turning into a database
            // error. Verified by removing this argument: the probe-with-a-time
            // case in MealPlanRepositoryTest goes red.
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->setParameter('slot', $slot->value)
            ->setParameter('recipeId', $recipeId);

        if (null !== $excludingId) {
            $query->setParameter('excludingId', $excludingId);
        }

        return (int) $query->getSingleScalarResult() > 0;
    }

    public function remove(PlannedMeal $meal): void
    {
        $this->entityManager->remove($meal);
        $this->entityManager->flush();
    }
}
