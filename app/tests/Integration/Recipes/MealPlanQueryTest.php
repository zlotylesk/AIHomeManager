<?php

declare(strict_types=1);

namespace App\Tests\Integration\Recipes;

use App\Messaging\QueryBus;
use App\Module\Recipes\Application\DTO\MealPlanDayDTO;
use App\Module\Recipes\Application\DTO\MealPlanDTO;
use App\Module\Recipes\Application\DTO\MealPlanSlotDTO;
use App\Module\Recipes\Application\DTO\PlannedMealDTO;
use App\Module\Recipes\Application\Query\GetMealPlan;
use App\Module\Recipes\Domain\Enum\MealSlot;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The calendar read runs against real MySQL: the window is a BETWEEN on a DATE
 * column and the recipe title comes from a LEFT JOIN, so a doubled Connection
 * would only prove the SQL we wrote matches itself.
 */
final class MealPlanQueryTest extends KernelTestCase
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

    public function testEchoesTheWindowBack(): void
    {
        $plan = $this->plan('2026-08-03', '2026-08-09');

        self::assertSame('2026-08-03', $plan->from);
        self::assertSame('2026-08-09', $plan->to);
    }

    /**
     * Every day of the window is present even when nothing is planned, so a
     * calendar renders a week straight from the payload without working out
     * which dates are missing.
     */
    public function testAnEmptyWeekStillCarriesSevenDaysAndEverySlot(): void
    {
        $plan = $this->plan('2026-08-03', '2026-08-09');

        self::assertCount(7, $plan->days);
        self::assertSame('2026-08-03', $plan->days[0]->date);
        self::assertSame('2026-08-09', $plan->days[6]->date);

        foreach ($plan->days as $day) {
            self::assertCount(4, $day->slots);

            foreach ($day->slots as $slot) {
                self::assertSame([], $slot->meals);
            }
        }
    }

    /**
     * The slot order is domain knowledge, so the response carries it rather
     * than leaving each client to sort the vocabulary itself.
     */
    public function testSlotsComeBackInTheOrderOfTheDay(): void
    {
        $plan = $this->plan('2026-08-03', '2026-08-03');

        self::assertSame(
            ['breakfast', 'lunch', 'dinner', 'snack'],
            array_map(static fn (MealPlanSlotDTO $s): string => $s->slot, $plan->days[0]->slots),
        );
    }

    public function testCarriesEveryFieldOfAPlannedMealIncludingTheRecipeTitle(): void
    {
        $this->insertRecipe('r-1', 'Zupa pomidorowa');
        $this->planMeal('m-1', '2026-08-05', MealSlot::LUNCH, 'r-1', 4);

        $meal = $this->onlyMeal($this->plan('2026-08-03', '2026-08-09'), '2026-08-05', 'lunch');

        self::assertSame('m-1', $meal->id);
        self::assertSame('r-1', $meal->recipeId);
        self::assertSame('Zupa pomidorowa', $meal->recipeTitle);
        self::assertSame(4, $meal->servings);
    }

    public function testPlacesAMealOnItsOwnDayAndSlotOnly(): void
    {
        $this->insertRecipe('r-1', 'Zupa pomidorowa');
        $this->planMeal('m-1', '2026-08-05', MealSlot::DINNER, 'r-1', 2);

        $plan = $this->plan('2026-08-03', '2026-08-09');

        foreach ($plan->days as $day) {
            foreach ($day->slots as $slot) {
                $expected = '2026-08-05' === $day->date && 'dinner' === $slot->slot ? 1 : 0;
                self::assertCount($expected, $slot->meals, sprintf('%s / %s', $day->date, $slot->slot));
            }
        }
    }

    /**
     * A slot holds a list — soup plus a main course is one ordinary "obiad",
     * which is the whole reason the duplicate rule is aimed at (date, slot,
     * recipe) rather than at (date, slot).
     */
    public function testOneSlotCanHoldSeveralRecipes(): void
    {
        $this->insertRecipe('r-1', 'Zupa pomidorowa');
        $this->insertRecipe('r-2', 'Kotlet schabowy');
        $this->planMeal('m-1', '2026-08-05', MealSlot::LUNCH, 'r-1', 2);
        $this->planMeal('m-2', '2026-08-05', MealSlot::LUNCH, 'r-2', 2);

        $meals = $this->slot($this->plan('2026-08-03', '2026-08-09'), '2026-08-05', 'lunch')->meals;

        self::assertSame(
            ['Kotlet schabowy', 'Zupa pomidorowa'],
            array_map(static fn (PlannedMealDTO $m): ?string => $m->recipeTitle, $meals),
        );
    }

    public function testIgnoresMealsOutsideTheWindow(): void
    {
        $this->insertRecipe('r-1', 'Zupa pomidorowa');
        $this->planMeal('m-before', '2026-08-02', MealSlot::LUNCH, 'r-1', 2);
        $this->planMeal('m-inside', '2026-08-05', MealSlot::LUNCH, 'r-1', 2);
        $this->planMeal('m-after', '2026-08-10', MealSlot::LUNCH, 'r-1', 2);

        $plan = $this->plan('2026-08-03', '2026-08-09');

        self::assertSame(['m-inside'], $this->everyMealId($plan));
    }

    /**
     * BETWEEN on a DATE column includes both ends; a meal planned for the last
     * day of the window must not fall off it.
     */
    public function testIncludesMealsOnBothEndsOfTheWindow(): void
    {
        $this->insertRecipe('r-1', 'Zupa pomidorowa');
        $this->planMeal('m-first', '2026-08-03', MealSlot::BREAKFAST, 'r-1', 1);
        $this->planMeal('m-last', '2026-08-09', MealSlot::SNACK, 'r-1', 1);

        $plan = $this->plan('2026-08-03', '2026-08-09');

        self::assertSame(['m-first', 'm-last'], $this->everyMealId($plan));
    }

    /**
     * A recipe on the plan cannot normally be deleted, but if a row ever loses
     * its recipe the entry must still come back: an inner join would drop the
     * card from the calendar while it still occupied its slot, so re-planning
     * the same recipe would be refused as a conflict with something invisible.
     */
    public function testAMealWhoseRecipeIsMissingStillAppearsWithoutATitle(): void
    {
        $this->planMeal('m-1', '2026-08-05', MealSlot::LUNCH, 'ghost', 2);

        $meal = $this->onlyMeal($this->plan('2026-08-03', '2026-08-09'), '2026-08-05', 'lunch');

        self::assertSame('ghost', $meal->recipeId);
        self::assertNull($meal->recipeTitle);
    }

    /**
     * MySQL sorts NULL to the top of an ASC ordering, which would put the one
     * card that cannot be named at the head of the slot.
     */
    public function testAMealWithoutATitleSortsAfterTheNamedOnes(): void
    {
        $this->insertRecipe('r-1', 'Zupa pomidorowa');
        $this->planMeal('m-ghost', '2026-08-05', MealSlot::LUNCH, 'ghost', 2);
        $this->planMeal('m-named', '2026-08-05', MealSlot::LUNCH, 'r-1', 2);

        $meals = $this->slot($this->plan('2026-08-03', '2026-08-09'), '2026-08-05', 'lunch')->meals;

        self::assertSame(
            ['Zupa pomidorowa', null],
            array_map(static fn (PlannedMealDTO $m): ?string => $m->recipeTitle, $meals),
        );
    }

    public function testASingleDayWindowReturnsThatDayAlone(): void
    {
        $this->insertRecipe('r-1', 'Zupa pomidorowa');
        $this->planMeal('m-1', '2026-08-05', MealSlot::LUNCH, 'r-1', 2);

        $plan = $this->plan('2026-08-05', '2026-08-05');

        self::assertCount(1, $plan->days);
        self::assertSame(['m-1'], $this->everyMealId($plan));
    }

    /**
     * Month lengths are exactly what the client would have to get right if the
     * response omitted empty days.
     */
    public function testAWindowSpanningAMonthBoundaryFillsEveryDay(): void
    {
        $plan = $this->plan('2026-08-30', '2026-09-02');

        self::assertSame(
            ['2026-08-30', '2026-08-31', '2026-09-01', '2026-09-02'],
            array_map(static fn (MealPlanDayDTO $d): string => $d->date, $plan->days),
        );
    }

    private function plan(string $from, string $to): MealPlanDTO
    {
        return $this->queryBus->ask(new GetMealPlan(new DateTimeImmutable($from), new DateTimeImmutable($to)));
    }

    private function slot(MealPlanDTO $plan, string $date, string $slot): MealPlanSlotDTO
    {
        foreach ($plan->days as $day) {
            if ($day->date !== $date) {
                continue;
            }

            foreach ($day->slots as $candidate) {
                if ($candidate->slot === $slot) {
                    return $candidate;
                }
            }
        }

        self::fail(sprintf('No %s slot on %s in the returned plan.', $slot, $date));
    }

    private function onlyMeal(MealPlanDTO $plan, string $date, string $slot): PlannedMealDTO
    {
        $meals = $this->slot($plan, $date, $slot)->meals;

        self::assertCount(1, $meals);

        return $meals[0];
    }

    /** @return list<string> */
    private function everyMealId(MealPlanDTO $plan): array
    {
        $ids = [];

        foreach ($plan->days as $day) {
            foreach ($day->slots as $slot) {
                foreach ($slot->meals as $meal) {
                    $ids[] = $meal->id;
                }
            }
        }

        return $ids;
    }

    private function insertRecipe(string $id, string $title): void
    {
        $this->connection->executeStatement(
            'INSERT INTO recipes (id, title, servings, prep_time_minutes, tags) VALUES (:id, :title, 4, NULL, :tags)',
            ['id' => $id, 'title' => $title, 'tags' => json_encode([], JSON_THROW_ON_ERROR)],
        );
    }

    private function planMeal(string $id, string $date, MealSlot $slot, string $recipeId, int $servings): void
    {
        $this->connection->executeStatement(
            'INSERT INTO meal_plan (id, date, slot, recipe_id, servings) VALUES (:id, :date, :slot, :recipeId, :servings)',
            [
                'id' => $id,
                'date' => $date,
                'slot' => $slot->value,
                'recipeId' => $recipeId,
                'servings' => $servings,
            ],
            ['servings' => ParameterType::INTEGER],
        );
    }
}
