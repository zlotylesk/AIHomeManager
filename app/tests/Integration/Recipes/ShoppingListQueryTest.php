<?php

declare(strict_types=1);

namespace App\Tests\Integration\Recipes;

use App\Messaging\QueryBus;
use App\Module\Recipes\Application\DTO\ShoppingListDTO;
use App\Module\Recipes\Application\DTO\ShoppingListItemDTO;
use App\Module\Recipes\Application\Query\GetShoppingList;
use App\Module\Recipes\Domain\Enum\MealSlot;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The shopping list is one SUM/GROUP BY over a three-table join, with the
 * servings ratio applied in SQL — a doubled Connection could only prove the
 * query matches itself, so this runs against real MySQL.
 */
final class ShoppingListQueryTest extends KernelTestCase
{
    private Connection $connection;
    private QueryBus $queryBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->connection = $container->get(EntityManagerInterface::class)->getConnection();
        $this->queryBus = $container->get(QueryBus::class);

        $this->connection->executeStatement('DELETE FROM meal_plan');
        $this->connection->executeStatement('DELETE FROM recipe_ingredients');
        $this->connection->executeStatement('DELETE FROM recipe_steps');
        $this->connection->executeStatement('DELETE FROM recipes');
    }

    public function testAnEmptyWindowYieldsNoItemsButEchoesTheRange(): void
    {
        $list = $this->list('2026-08-03', '2026-08-09');

        self::assertSame('2026-08-03', $list->from);
        self::assertSame('2026-08-09', $list->to);
        self::assertSame([], $list->items);
    }

    public function testCarriesEveryFieldOfAnItem(): void
    {
        $this->recipeWith('r-1', 'Naleśniki', 4, [['Mąka', 500.0, 'g']]);
        $this->planMeal('m-1', '2026-08-05', MealSlot::BREAKFAST, 'r-1', 4);

        $items = $this->list('2026-08-03', '2026-08-09')->items;

        self::assertCount(1, $items);
        self::assertSame('Mąka', $items[0]->name);
        self::assertSame('g', $items[0]->unit);
        self::assertEqualsWithDelta(500.0, $items[0]->quantity, 0.0001);
    }

    /**
     * The whole reason PlannedMeal carries its own servings: cooking a
     * 4-portion recipe for 6 needs one and a half times everything.
     */
    public function testScalesQuantitiesByThePlannedServings(): void
    {
        $this->recipeWith('r-1', 'Naleśniki', 4, [['Mąka', 500.0, 'g']]);
        $this->planMeal('m-1', '2026-08-05', MealSlot::BREAKFAST, 'r-1', 6);

        self::assertEqualsWithDelta(750.0, $this->quantityOf('Mąka', 'g'), 0.0001);
    }

    public function testScalesDownWhenFewerPortionsArePlanned(): void
    {
        $this->recipeWith('r-1', 'Naleśniki', 4, [['Mąka', 500.0, 'g']]);
        $this->planMeal('m-1', '2026-08-05', MealSlot::BREAKFAST, 'r-1', 2);

        self::assertEqualsWithDelta(250.0, $this->quantityOf('Mąka', 'g'), 0.0001);
    }

    /**
     * A ratio with no exact decimal representation is exactly the case the
     * Ingredient VO documents as the reason quantities are floats rather than
     * minor units.
     */
    public function testHandlesARatioThatDoesNotDivideEvenly(): void
    {
        $this->recipeWith('r-1', 'Naleśniki', 3, [['Mąka', 500.0, 'g']]);
        $this->planMeal('m-1', '2026-08-05', MealSlot::BREAKFAST, 'r-1', 2);

        self::assertEqualsWithDelta(333.3333, $this->quantityOf('Mąka', 'g'), 0.001);
    }

    public function testSumsTheSameIngredientAcrossTwoRecipes(): void
    {
        $this->recipeWith('r-1', 'Naleśniki', 4, [['Mąka', 500.0, 'g']]);
        $this->recipeWith('r-2', 'Kluski', 4, [['Mąka', 300.0, 'g']]);
        $this->planMeal('m-1', '2026-08-05', MealSlot::BREAKFAST, 'r-1', 4);
        $this->planMeal('m-2', '2026-08-06', MealSlot::LUNCH, 'r-2', 4);

        $items = $this->list('2026-08-03', '2026-08-09')->items;

        self::assertCount(1, $items);
        self::assertEqualsWithDelta(800.0, $items[0]->quantity, 0.0001);
    }

    public function testSumsTheSameRecipePlannedTwice(): void
    {
        $this->recipeWith('r-1', 'Naleśniki', 4, [['Mąka', 500.0, 'g']]);
        $this->planMeal('m-1', '2026-08-05', MealSlot::BREAKFAST, 'r-1', 4);
        $this->planMeal('m-2', '2026-08-07', MealSlot::BREAKFAST, 'r-1', 2);

        self::assertEqualsWithDelta(750.0, $this->quantityOf('Mąka', 'g'), 0.0001);
    }

    /**
     * The grouping identity matches Ingredient::matches(), which compares the
     * name case-insensitively — otherwise the same shopping item would appear
     * twice because two recipes capitalised it differently.
     */
    public function testMergesTheSameIngredientSpelledWithDifferentCase(): void
    {
        $this->recipeWith('r-1', 'Naleśniki', 4, [['Mąka', 500.0, 'g']]);
        $this->recipeWith('r-2', 'Kluski', 4, [['mąka', 300.0, 'g']]);
        $this->planMeal('m-1', '2026-08-05', MealSlot::BREAKFAST, 'r-1', 4);
        $this->planMeal('m-2', '2026-08-06', MealSlot::LUNCH, 'r-2', 4);

        $items = $this->list('2026-08-03', '2026-08-09')->items;

        self::assertCount(1, $items);
        self::assertEqualsWithDelta(800.0, $items[0]->quantity, 0.0001);
    }

    /**
     * No unit conversion in the MVP: guessing whether 2 cups of flour are
     * 250 g would put an invented number on a shopping list.
     */
    public function testTheSameNameInTwoUnitsStaysTwoLines(): void
    {
        $this->recipeWith('r-1', 'Naleśniki', 4, [['Mleko', 500.0, 'ml']]);
        $this->recipeWith('r-2', 'Budyń', 4, [['Mleko', 1.0, 'l']]);
        $this->planMeal('m-1', '2026-08-05', MealSlot::BREAKFAST, 'r-1', 4);
        $this->planMeal('m-2', '2026-08-06', MealSlot::DINNER, 'r-2', 4);

        $items = $this->list('2026-08-03', '2026-08-09')->items;

        self::assertCount(2, $items);
        self::assertSame(['l', 'ml'], array_map(static fn (ShoppingListItemDTO $i): string => $i->unit, $items));
    }

    public function testCollectsEveryIngredientOfARecipe(): void
    {
        $this->recipeWith('r-1', 'Naleśniki', 4, [
            ['Mąka', 500.0, 'g'],
            ['Mleko', 250.0, 'ml'],
            ['Jajko', 2.0, 'piece'],
        ]);
        $this->planMeal('m-1', '2026-08-05', MealSlot::BREAKFAST, 'r-1', 4);

        $items = $this->list('2026-08-03', '2026-08-09')->items;

        // Alphabetical under utf8mb4_unicode_ci, which sorts "ą" alongside
        // "a" — so "Mąka" precedes "Mleko", which is also how a Polish
        // speaker would order them.
        self::assertSame(
            ['Jajko', 'Mąka', 'Mleko'],
            array_map(static fn (ShoppingListItemDTO $i): string => $i->name, $items),
        );
    }

    public function testIgnoresMealsOutsideTheWindow(): void
    {
        $this->recipeWith('r-1', 'Naleśniki', 4, [['Mąka', 500.0, 'g']]);
        $this->planMeal('m-before', '2026-08-02', MealSlot::BREAKFAST, 'r-1', 4);
        $this->planMeal('m-inside', '2026-08-05', MealSlot::BREAKFAST, 'r-1', 4);
        $this->planMeal('m-after', '2026-08-10', MealSlot::BREAKFAST, 'r-1', 4);

        self::assertEqualsWithDelta(500.0, $this->quantityOf('Mąka', 'g'), 0.0001);
    }

    public function testIncludesMealsOnBothEndsOfTheWindow(): void
    {
        $this->recipeWith('r-1', 'Naleśniki', 4, [['Mąka', 500.0, 'g']]);
        $this->planMeal('m-first', '2026-08-03', MealSlot::BREAKFAST, 'r-1', 4);
        $this->planMeal('m-last', '2026-08-09', MealSlot::DINNER, 'r-1', 4);

        self::assertEqualsWithDelta(1000.0, $this->quantityOf('Mąka', 'g'), 0.0001);
    }

    /**
     * A recipe nobody planned buys nothing — the list follows the calendar,
     * not the catalog.
     */
    public function testAnUnplannedRecipeContributesNothing(): void
    {
        $this->recipeWith('r-1', 'Naleśniki', 4, [['Mąka', 500.0, 'g']]);

        self::assertSame([], $this->list('2026-08-03', '2026-08-09')->items);
    }

    private function list(string $from, string $to): ShoppingListDTO
    {
        return $this->queryBus->ask(new GetShoppingList(new DateTimeImmutable($from), new DateTimeImmutable($to)));
    }

    private function quantityOf(string $name, string $unit): float
    {
        foreach ($this->list('2026-08-03', '2026-08-09')->items as $item) {
            if ($item->name === $name && $item->unit === $unit) {
                return $item->quantity;
            }
        }

        self::fail(sprintf('No "%s" (%s) on the shopping list.', $name, $unit));
    }

    /** @param list<array{0: string, 1: float, 2: string}> $ingredients */
    private function recipeWith(string $id, string $title, int $servings, array $ingredients): void
    {
        $this->connection->executeStatement(
            'INSERT INTO recipes (id, title, servings, prep_time_minutes, tags) VALUES (:id, :title, :servings, NULL, :tags)',
            ['id' => $id, 'title' => $title, 'servings' => $servings, 'tags' => json_encode([], JSON_THROW_ON_ERROR)],
            ['servings' => ParameterType::INTEGER],
        );

        foreach ($ingredients as $position => [$name, $quantity, $unit]) {
            $this->connection->executeStatement(
                'INSERT INTO recipe_ingredients (recipe_id, `position`, name, quantity, unit) VALUES (:recipeId, :position, :name, :quantity, :unit)',
                ['recipeId' => $id, 'position' => $position, 'name' => $name, 'quantity' => $quantity, 'unit' => $unit],
                ['position' => ParameterType::INTEGER],
            );
        }
    }

    private function planMeal(string $id, string $date, MealSlot $slot, string $recipeId, int $servings): void
    {
        $this->connection->executeStatement(
            'INSERT INTO meal_plan (id, date, slot, recipe_id, servings) VALUES (:id, :date, :slot, :recipeId, :servings)',
            ['id' => $id, 'date' => $date, 'slot' => $slot->value, 'recipeId' => $recipeId, 'servings' => $servings],
            ['servings' => ParameterType::INTEGER],
        );
    }
}
