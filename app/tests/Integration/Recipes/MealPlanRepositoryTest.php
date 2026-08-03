<?php

declare(strict_types=1);

namespace App\Tests\Integration\Recipes;

use App\Module\Recipes\Domain\Entity\PlannedMeal;
use App\Module\Recipes\Domain\Enum\MealSlot;
use App\Module\Recipes\Infrastructure\Persistence\DoctrineMealPlanRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Unlike its sibling Recipe, PlannedMeal is ORM-mapped, so
 * `doctrine:schema:validate` already covers the mapping-vs-schema question.
 * What it cannot cover is whether the values survive a round trip — the custom
 * `meal_slot` type, the DATE column, and hydration into a readonly class — and
 * whether the rules that span rows actually hold in MySQL.
 */
final class MealPlanRepositoryTest extends KernelTestCase
{
    private DoctrineMealPlanRepository $repository;
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->repository = new DoctrineMealPlanRepository($this->entityManager);

        $this->connection->executeStatement('TRUNCATE TABLE meal_plan');
    }

    public function testSaveAndFindByIdRoundTripsEveryField(): void
    {
        $this->repository->save(new PlannedMeal(
            'm0000001-0000-0000-0000-000000000001',
            new DateTimeImmutable('2026-08-05'),
            MealSlot::LUNCH,
            'r0000001-0000-0000-0000-000000000001',
            4,
        ));

        // Without clearing, find() would hand back the very object just saved
        // and prove nothing about the mapping.
        $this->entityManager->clear();

        $found = $this->repository->findById('m0000001-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertSame('m0000001-0000-0000-0000-000000000001', $found->id());
        self::assertSame('2026-08-05', $found->date()->format('Y-m-d'));
        self::assertSame(MealSlot::LUNCH, $found->slot());
        self::assertSame('r0000001-0000-0000-0000-000000000001', $found->recipeId());
        self::assertSame(4, $found->servings());
    }

    public function testEverySlotSurvivesTheRoundTrip(): void
    {
        foreach (MealSlot::cases() as $index => $slot) {
            $this->repository->save(new PlannedMeal(
                'm-'.$index,
                new DateTimeImmutable('2026-08-05'),
                $slot,
                'r-1',
                1,
            ));
        }

        $this->entityManager->clear();

        foreach (MealSlot::cases() as $index => $slot) {
            $found = $this->repository->findById('m-'.$index);

            self::assertNotNull($found);
            self::assertSame($slot, $found->slot());
        }
    }

    public function testUnknownIdIsNull(): void
    {
        self::assertNull($this->repository->findById('nope'));
    }

    /**
     * The module's central decision: a slot holds a list. Soup and a main
     * course are one "obiad", and refusing the second would make the plan
     * unable to describe an ordinary dinner.
     */
    public function testTwoDifferentRecipesShareOneSlot(): void
    {
        $this->repository->save(new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-soup', 4));
        $this->repository->save(new PlannedMeal('m-2', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-main', 4));

        self::assertSame(
            2,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM meal_plan WHERE slot = ?', ['lunch']),
        );
    }

    /**
     * ...but the same recipe twice in one slot is a mis-click, and the shopping
     * list would silently buy its ingredients twice. The handler will report it
     * readably (HMAI-390); this pins that the database refuses it regardless,
     * so two concurrent writes cannot both pass the pre-check.
     */
    public function testTheSameRecipeCannotBePlannedTwiceInOneSlot(): void
    {
        $this->repository->save(new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', 4));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->repository->save(new PlannedMeal('m-2', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', 2));
    }

    public function testExistsForFindsAPlannedRecipe(): void
    {
        $this->repository->save(new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', 4));

        self::assertTrue($this->repository->existsFor(new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1'));
    }

    public function testExistsForDistinguishesDateSlotAndRecipe(): void
    {
        $this->repository->save(new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', 4));

        self::assertFalse($this->repository->existsFor(new DateTimeImmutable('2026-08-06'), MealSlot::LUNCH, 'r-1'));
        self::assertFalse($this->repository->existsFor(new DateTimeImmutable('2026-08-05'), MealSlot::DINNER, 'r-1'));
        self::assertFalse($this->repository->existsFor(new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-2'));
    }

    /**
     * A caller holding "today" from a clock passes a date with a time on it. If
     * that reached the comparison intact, the duplicate check would find
     * nothing and the insert behind it would hit the unique index instead —
     * a readable 409 turning into a database error.
     */
    public function testExistsForIgnoresATimeOnTheProbeDate(): void
    {
        $this->repository->save(new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', 4));

        self::assertTrue($this->repository->existsFor(
            new DateTimeImmutable('2026-08-05 16:32:09'),
            MealSlot::LUNCH,
            'r-1',
        ));
    }

    /**
     * Moving a meal onto its own current position is a no-op, not a conflict
     * with itself.
     */
    public function testExistsForCanExcludeTheMealBeingMoved(): void
    {
        $this->repository->save(new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', 4));

        self::assertFalse($this->repository->existsFor(
            new DateTimeImmutable('2026-08-05'),
            MealSlot::LUNCH,
            'r-1',
            excludingId: 'm-1',
        ));
    }

    public function testExcludingADifferentMealStillReportsTheConflict(): void
    {
        $this->repository->save(new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', 4));

        self::assertTrue($this->repository->existsFor(
            new DateTimeImmutable('2026-08-05'),
            MealSlot::LUNCH,
            'r-1',
            excludingId: 'm-2',
        ));
    }

    public function testExistsForRecipeAnswersAcrossEveryDayAndSlot(): void
    {
        $this->repository->save(new PlannedMeal('m-1', new DateTimeImmutable('2020-01-06'), MealSlot::SNACK, 'r-1', 1));

        // Deliberately a date long past and a slot nobody would look at: the
        // delete guard spans the whole calendar, not the window a user happens
        // to be viewing.
        self::assertTrue($this->repository->existsForRecipe('r-1'));
        self::assertFalse($this->repository->existsForRecipe('r-2'));
    }

    public function testExistsForRecipeIsFalseOnAnEmptyPlan(): void
    {
        self::assertFalse($this->repository->existsForRecipe('r-1'));
    }

    public function testExistsForRecipeStopsReportingOnceTheMealIsRemoved(): void
    {
        $this->repository->save(new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', 4));

        $meal = $this->repository->findById('m-1');
        self::assertNotNull($meal);
        $this->repository->remove($meal);

        self::assertFalse($this->repository->existsForRecipe('r-1'));
    }

    public function testMoveToIsPersistedByASave(): void
    {
        $this->repository->save(new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', 4));

        $meal = $this->repository->findById('m-1');
        self::assertNotNull($meal);

        $meal->moveTo(new DateTimeImmutable('2026-08-08'), MealSlot::DINNER);
        $this->repository->save($meal);
        $this->entityManager->clear();

        $moved = $this->repository->findById('m-1');
        self::assertNotNull($moved);
        self::assertSame('2026-08-08', $moved->date()->format('Y-m-d'));
        self::assertSame(MealSlot::DINNER, $moved->slot());
    }

    public function testRemoveDeletesOnlyThatMeal(): void
    {
        $this->repository->save(new PlannedMeal('m-1', new DateTimeImmutable('2026-08-05'), MealSlot::LUNCH, 'r-1', 4));
        $this->repository->save(new PlannedMeal('m-2', new DateTimeImmutable('2026-08-05'), MealSlot::DINNER, 'r-2', 2));

        $meal = $this->repository->findById('m-1');
        self::assertNotNull($meal);

        $this->repository->remove($meal);
        $this->entityManager->clear();

        self::assertNull($this->repository->findById('m-1'));
        self::assertNotNull($this->repository->findById('m-2'));
    }
}
