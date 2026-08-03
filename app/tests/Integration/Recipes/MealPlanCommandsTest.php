<?php

declare(strict_types=1);

namespace App\Tests\Integration\Recipes;

use App\Messaging\CommandBus;
use App\Module\Recipes\Application\Command\CreateRecipe;
use App\Module\Recipes\Application\Command\DeleteRecipe;
use App\Module\Recipes\Application\Command\MoveMeal;
use App\Module\Recipes\Application\Command\PlanMeal;
use App\Module\Recipes\Application\Command\UnplanMeal;
use App\Module\Recipes\Application\Exception\MealAlreadyPlannedException;
use App\Module\Recipes\Application\Exception\PlannedMealNotFoundException;
use App\Module\Recipes\Application\Exception\RecipeIsPlannedException;
use App\Module\Recipes\Application\Exception\RecipeNotFoundException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

/**
 * The plan's write side through the real command bus, the real handlers and
 * real MySQL.
 *
 * The unit tests already pin each handler's rules against doubles; what only
 * this layer can show is that the rules survive contact with the schema — that
 * the conflict the handler reports is the same one the unique index enforces,
 * and that a delete refused in PHP corresponds to rows the database really
 * still holds.
 */
final class MealPlanCommandsTest extends KernelTestCase
{
    private Connection $connection;
    private CommandBus $commandBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->connection = $container->get(EntityManagerInterface::class)->getConnection();
        $this->commandBus = $container->get(CommandBus::class);

        $this->connection->executeStatement('DELETE FROM meal_plan');
        $this->connection->executeStatement('DELETE FROM recipe_ingredients');
        $this->connection->executeStatement('DELETE FROM recipe_steps');
        $this->connection->executeStatement('DELETE FROM recipes');
    }

    public function testPlansAMealAndStoresIt(): void
    {
        $recipeId = $this->createRecipe('Zupa pomidorowa');

        $mealId = $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-05', 'lunch', $recipeId, 4));

        $row = $this->connection->fetchAssociative('SELECT * FROM meal_plan WHERE id = ?', [$mealId]);

        self::assertIsArray($row);
        self::assertSame('2026-08-05', $row['date']);
        self::assertSame('lunch', $row['slot']);
        self::assertSame($recipeId, $row['recipe_id']);
        self::assertSame(4, (int) $row['servings']);
    }

    public function testPlanningAnUnknownRecipeIsRefusedAndWritesNothing(): void
    {
        $this->expectHandlerException(
            RecipeNotFoundException::class,
            fn () => $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-05', 'lunch', 'missing')),
        );

        self::assertSame(0, $this->plannedCount());
    }

    /**
     * The rule the module actually enforces: the same recipe twice in one slot
     * is a duplicate, two different recipes in one slot are a menu.
     */
    public function testTheSameRecipeCannotBePlannedTwiceInOneSlot(): void
    {
        $recipeId = $this->createRecipe('Zupa pomidorowa');
        $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-05', 'lunch', $recipeId));

        $this->expectHandlerException(
            MealAlreadyPlannedException::class,
            fn () => $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-05', 'lunch', $recipeId)),
        );

        self::assertSame(1, $this->plannedCount());
    }

    public function testTwoDifferentRecipesShareOneSlot(): void
    {
        $soup = $this->createRecipe('Zupa pomidorowa');
        $main = $this->createRecipe('Kotlet schabowy');

        $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-05', 'lunch', $soup));
        $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-05', 'lunch', $main));

        self::assertSame(2, $this->plannedCount());
    }

    public function testTheSameRecipeCanBePlannedOnAnotherDay(): void
    {
        $recipeId = $this->createRecipe('Zupa pomidorowa');

        $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-05', 'lunch', $recipeId));
        $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-06', 'lunch', $recipeId));

        self::assertSame(2, $this->plannedCount());
    }

    public function testMovesAMealToAnotherDayAndSlot(): void
    {
        $recipeId = $this->createRecipe('Zupa pomidorowa');
        $mealId = $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-05', 'lunch', $recipeId, 3));

        $this->commandBus->dispatch(new MoveMeal($mealId, '2026-08-08', 'dinner'));

        $row = $this->connection->fetchAssociative('SELECT * FROM meal_plan WHERE id = ?', [$mealId]);

        self::assertIsArray($row);
        self::assertSame('2026-08-08', $row['date']);
        self::assertSame('dinner', $row['slot']);
        self::assertSame(3, (int) $row['servings']);
        self::assertSame(1, $this->plannedCount());
    }

    /**
     * The self-exclusion, proven against the unique index rather than a
     * double: without it this move would collide with the very row it is
     * moving and surface as a database error instead of succeeding.
     */
    public function testMovingAMealOntoItsOwnPositionSucceeds(): void
    {
        $recipeId = $this->createRecipe('Zupa pomidorowa');
        $mealId = $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-05', 'lunch', $recipeId));

        $this->commandBus->dispatch(new MoveMeal($mealId, '2026-08-05', 'lunch'));

        self::assertSame(1, $this->plannedCount());
    }

    public function testMovingOntoAnOccupiedPositionIsRefusedAsAConflict(): void
    {
        $recipeId = $this->createRecipe('Zupa pomidorowa');
        $mealId = $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-05', 'lunch', $recipeId));
        $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-08', 'dinner', $recipeId));

        $this->expectHandlerException(
            MealAlreadyPlannedException::class,
            fn () => $this->commandBus->dispatch(new MoveMeal($mealId, '2026-08-08', 'dinner')),
        );

        $row = $this->connection->fetchAssociative('SELECT * FROM meal_plan WHERE id = ?', [$mealId]);

        self::assertIsArray($row);
        self::assertSame('2026-08-05', $row['date'], 'A refused move must leave the meal where it was.');
        self::assertSame('lunch', $row['slot']);
    }

    public function testUnplansAMeal(): void
    {
        $recipeId = $this->createRecipe('Zupa pomidorowa');
        $mealId = $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-05', 'lunch', $recipeId));

        $this->commandBus->dispatch(new UnplanMeal($mealId));

        self::assertSame(0, $this->plannedCount());
    }

    public function testUnplanningTwiceIsAMiss(): void
    {
        $recipeId = $this->createRecipe('Zupa pomidorowa');
        $mealId = $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-05', 'lunch', $recipeId));

        $this->commandBus->dispatch(new UnplanMeal($mealId));

        $this->expectHandlerException(
            PlannedMealNotFoundException::class,
            fn () => $this->commandBus->dispatch(new UnplanMeal($mealId)),
        );
    }

    /**
     * Unplanning frees the slot: the conflict rule must be about what is on
     * the calendar now, not about what once was.
     */
    public function testAFreedSlotAcceptsTheRecipeAgain(): void
    {
        $recipeId = $this->createRecipe('Zupa pomidorowa');
        $mealId = $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-05', 'lunch', $recipeId));

        $this->commandBus->dispatch(new UnplanMeal($mealId));
        $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-05', 'lunch', $recipeId));

        self::assertSame(1, $this->plannedCount());
    }

    public function testARecipeOnThePlanCannotBeDeleted(): void
    {
        $recipeId = $this->createRecipe('Zupa pomidorowa');
        $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-05', 'lunch', $recipeId));

        $this->expectHandlerException(
            RecipeIsPlannedException::class,
            fn () => $this->commandBus->dispatch(new DeleteRecipe($recipeId)),
        );

        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM recipes WHERE id = ?', [$recipeId]),
        );
    }

    public function testTheRecipeCanBeDeletedOnceItIsOffThePlan(): void
    {
        $recipeId = $this->createRecipe('Zupa pomidorowa');
        $mealId = $this->commandBus->dispatchAndReturn(new PlanMeal('2026-08-05', 'lunch', $recipeId));

        $this->commandBus->dispatch(new UnplanMeal($mealId));
        $this->commandBus->dispatch(new DeleteRecipe($recipeId));

        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM recipes WHERE id = ?', [$recipeId]),
        );
    }

    /**
     * The guard spans the whole calendar, not the visible window: a plan entry
     * long in the past holds the recipe just as much as tomorrow's does.
     */
    public function testAPastPlanEntryStillBlocksTheDelete(): void
    {
        $recipeId = $this->createRecipe('Zupa pomidorowa');
        $this->commandBus->dispatchAndReturn(new PlanMeal('2020-01-06', 'dinner', $recipeId));

        $this->expectHandlerException(
            RecipeIsPlannedException::class,
            fn () => $this->commandBus->dispatch(new DeleteRecipe($recipeId)),
        );
    }

    public function testAnUnplannedRecipeIsStillDeletable(): void
    {
        $recipeId = $this->createRecipe('Zupa pomidorowa');

        $this->commandBus->dispatch(new DeleteRecipe($recipeId));

        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM recipes WHERE id = ?', [$recipeId]),
        );
    }

    private function createRecipe(string $title): string
    {
        return $this->commandBus->dispatchAndReturn(new CreateRecipe(
            $title,
            [['name' => 'Woda', 'quantity' => 1.0, 'unit' => 'l']],
        ));
    }

    private function plannedCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM meal_plan');
    }

    /**
     * The bus wraps a handler's exception, exactly as it does in production —
     * where `ApiExceptionListener` unwraps it at the HTTP boundary. Asserting
     * on the wrapper would pin Messenger's behaviour instead of the module's.
     *
     * @param class-string<Throwable> $expected
     */
    private function expectHandlerException(string $expected, callable $act): void
    {
        try {
            $act();
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf($expected, $e->getPrevious());

            return;
        } catch (Throwable $e) {
            self::assertInstanceOf($expected, $e);

            return;
        }

        self::fail(sprintf('Expected %s, but nothing was thrown.', $expected));
    }
}
