<?php

declare(strict_types=1);

namespace App\Tests\Integration\Recipes;

use App\Module\Recipes\Application\PlanWindow;
use App\Tests\Support\AuthenticatedApiTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MealPlanApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private const string UNKNOWN_UUID = '00000000-0000-0000-0000-000000000000';
    private const string WEEK = '?from=2026-08-03&to=2026-08-09';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $this->connection->executeStatement('DELETE FROM meal_plan');
        $this->connection->executeStatement('DELETE FROM recipe_ingredients');
        $this->connection->executeStatement('DELETE FROM recipe_steps');
        $this->connection->executeStatement('DELETE FROM recipes');
    }

    /**
     * The response shape follows the window, not the data: a client renders a
     * grid straight from it without knowing the slot vocabulary or its order.
     */
    public function testAnEmptyWeekStillCarriesEveryDayAndSlot(): void
    {
        $this->client->request('GET', '/api/meal-plan'.self::WEEK);
        self::assertResponseIsSuccessful();
        $plan = $this->jsonResponse($this->client);

        self::assertSame('2026-08-03', $plan['from']);
        self::assertSame('2026-08-09', $plan['to']);
        self::assertCount(7, $plan['days']);
        self::assertSame('2026-08-03', $plan['days'][0]['date']);
        self::assertSame('2026-08-09', $plan['days'][6]['date']);
        self::assertSame(
            ['breakfast', 'lunch', 'dinner', 'snack'],
            array_column($plan['days'][0]['slots'], 'slot'),
        );
        self::assertSame([], $plan['days'][0]['slots'][0]['meals']);
    }

    public function testPlanMoveAndUnplan(): void
    {
        $recipeId = $this->createRecipe();

        $this->client->request('POST', '/api/meal-plan', content: (string) json_encode([
            'date' => '2026-08-05', 'slot' => 'lunch', 'recipeId' => $recipeId, 'servings' => 6,
        ]));
        self::assertResponseStatusCodeSame(201);
        $mealId = $this->jsonResponse($this->client)['id'];

        $meal = $this->mealAt('2026-08-05', 'lunch');
        self::assertSame($mealId, $meal['id']);
        self::assertSame($recipeId, $meal['recipeId']);
        self::assertSame('Naleśniki', $meal['recipeTitle']);
        self::assertSame(6, $meal['servings']);

        $this->client->request('PATCH', '/api/meal-plan/'.$mealId, content: (string) json_encode([
            'date' => '2026-08-07', 'slot' => 'dinner',
        ]));
        self::assertResponseStatusCodeSame(204);

        self::assertSame([], $this->slotAt('2026-08-05', 'lunch')['meals']);
        // The move keeps the servings — changing them would make it a different
        // plan rather than the same plan on another day.
        self::assertSame(6, $this->mealAt('2026-08-07', 'dinner')['servings']);

        $this->client->request('DELETE', '/api/meal-plan/'.$mealId);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->slotAt('2026-08-07', 'dinner')['meals']);
    }

    /** A Polish lunch is routinely soup plus a main course. */
    public function testTwoDifferentRecipesShareOneSlot(): void
    {
        $soup = $this->createRecipe(['title' => 'Zupa pomidorowa']);
        $main = $this->createRecipe(['title' => 'Kotlet']);

        $this->planMeal($soup, '2026-08-05', 'lunch');
        $this->planMeal($main, '2026-08-05', 'lunch');

        self::assertCount(2, $this->slotAt('2026-08-05', 'lunch')['meals']);
    }

    /** A double-clicked button, not a menu. */
    public function testTheSameRecipeTwiceInOneSlotIsAConflict(): void
    {
        $recipeId = $this->createRecipe();
        $this->planMeal($recipeId, '2026-08-05', 'lunch');

        $this->client->request('POST', '/api/meal-plan', content: (string) json_encode([
            'date' => '2026-08-05', 'slot' => 'lunch', 'recipeId' => $recipeId,
        ]));
        self::assertResponseStatusCodeSame(409);
        self::assertArrayHasKey('error', $this->jsonResponse($this->client));

        self::assertCount(1, $this->slotAt('2026-08-05', 'lunch')['meals']);
    }

    /** Dropping a card back where it came from is a no-op, not a self-conflict. */
    public function testMovingAMealOntoItsOwnPositionSucceeds(): void
    {
        $recipeId = $this->createRecipe();
        $mealId = $this->planMeal($recipeId, '2026-08-05', 'lunch');

        $this->client->request('PATCH', '/api/meal-plan/'.$mealId, content: (string) json_encode([
            'date' => '2026-08-05', 'slot' => 'lunch',
        ]));
        self::assertResponseStatusCodeSame(204);
        self::assertCount(1, $this->slotAt('2026-08-05', 'lunch')['meals']);
    }

    public function testMovingOntoAnOccupiedPositionIsAConflict(): void
    {
        $recipeId = $this->createRecipe();
        $this->planMeal($recipeId, '2026-08-05', 'lunch');
        $mealId = $this->planMeal($recipeId, '2026-08-06', 'lunch');

        $this->client->request('PATCH', '/api/meal-plan/'.$mealId, content: (string) json_encode([
            'date' => '2026-08-05', 'slot' => 'lunch',
        ]));
        self::assertResponseStatusCodeSame(409);

        // The refused move left the meal where it was.
        self::assertCount(1, $this->slotAt('2026-08-06', 'lunch')['meals']);
    }

    public function testPlanningAnUnknownRecipeIsNotFound(): void
    {
        $this->client->request('POST', '/api/meal-plan', content: (string) json_encode([
            'date' => '2026-08-05', 'slot' => 'lunch', 'recipeId' => self::UNKNOWN_UUID,
        ]));
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownPlanEntryIsNotFound(): void
    {
        $this->client->request('DELETE', '/api/meal-plan/'.self::UNKNOWN_UUID);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('PATCH', '/api/meal-plan/'.self::UNKNOWN_UUID, content: (string) json_encode([
            'date' => '2026-08-05', 'slot' => 'lunch',
        ]));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPlanPayloads')]
    public function testRejectsMalformedPlanPayloads(array $payload): void
    {
        $this->client->request('POST', '/api/meal-plan', content: (string) json_encode($payload));
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('error', $this->jsonResponse($this->client));
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function invalidPlanPayloads(): iterable
    {
        $base = ['date' => '2026-08-05', 'slot' => 'lunch', 'recipeId' => self::UNKNOWN_UUID];

        yield 'no date' => [['slot' => 'lunch', 'recipeId' => self::UNKNOWN_UUID]];
        yield 'no slot' => [['date' => '2026-08-05', 'recipeId' => self::UNKNOWN_UUID]];
        yield 'no recipeId' => [['date' => '2026-08-05', 'slot' => 'lunch']];
        yield 'unknown slot' => [array_merge($base, ['slot' => 'brunch'])];
        // Rolled over rather than rejected, the meal would appear on a date
        // nobody chose — simply missing from the day the user was looking at.
        yield 'impossible day' => [array_merge($base, ['date' => '2026-02-31'])];
        yield 'unpadded date' => [array_merge($base, ['date' => '2026-8-5'])];
        // Reaches 422 even with an unknown recipe id, because the parser runs
        // before the handler ever looks the recipe up.
        yield 'servings not an integer' => [array_merge($base, ['servings' => '4'])];
    }

    /**
     * Servings are validated by the aggregate, which the handler only reaches
     * after confirming the recipe exists — so unlike the shape rules above this
     * one needs a real recipe to be observable at all.
     */
    public function testRejectsServingsBelowOne(): void
    {
        $recipeId = $this->createRecipe();

        $this->client->request('POST', '/api/meal-plan', content: (string) json_encode([
            'date' => '2026-08-05', 'slot' => 'lunch', 'recipeId' => $recipeId, 'servings' => 0,
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->slotAt('2026-08-05', 'lunch')['meals']);
    }

    public function testShoppingListAggregatesThePlannedWeek(): void
    {
        $recipeId = $this->createRecipe();
        $this->planMeal($recipeId, '2026-08-05', 'lunch', 6);

        $this->client->request('GET', '/api/meal-plan/shopping-list'.self::WEEK);
        self::assertResponseIsSuccessful();
        $list = $this->jsonResponse($this->client);

        self::assertSame('2026-08-03', $list['from']);
        self::assertSame('2026-08-09', $list['to']);
        self::assertSame(['Mąka', 'Mleko'], array_column($list['items'], 'name'));
        self::assertSame(['g', 'ml'], array_column($list['items'], 'unit'));
        // Scaled from the recipe's 4 portions up to the 6 planned.
        self::assertEqualsWithDelta(750.0, $list['items'][0]['quantity'], 0.0001);
        self::assertEqualsWithDelta(375.0, $list['items'][1]['quantity'], 0.0001);
    }

    /** No gap-filling here: an empty list means an empty window. */
    public function testAnEmptyWindowYieldsAnEmptyShoppingList(): void
    {
        $this->client->request('GET', '/api/meal-plan/shopping-list'.self::WEEK);
        self::assertSame([], $this->jsonResponse($this->client)['items']);
    }

    /**
     * @param array<string, string> $query
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidWindows')]
    public function testRejectsAMalformedWindow(array $query): void
    {
        foreach (['/api/meal-plan', '/api/meal-plan/shopping-list'] as $path) {
            $this->client->request('GET', $path.'?'.http_build_query($query));
            self::assertResponseStatusCodeSame(422);
            self::assertArrayHasKey('error', $this->jsonResponse($this->client));
        }
    }

    /** @return iterable<string, array{0: array<string, string>}> */
    public static function invalidWindows(): iterable
    {
        yield 'no from' => [['to' => '2026-08-09']];
        yield 'no to' => [['from' => '2026-08-03']];
        yield 'blank from' => [['from' => '', 'to' => '2026-08-09']];
        yield 'impossible day' => [['from' => '2026-02-31', 'to' => '2026-03-05']];
        yield 'not a date' => [['from' => 'wczoraj', 'to' => '2026-08-09']];
        yield 'inverted' => [['from' => '2026-08-09', 'to' => '2026-08-03']];
        yield 'longer than the cap' => [[
            'from' => '2026-08-03',
            'to' => new DateTimeImmutable('2026-08-03')->modify(sprintf('+%d days', PlanWindow::MAX_DAYS))->format('Y-m-d'),
        ]];
    }

    public function testVersionedAndAliasPrefixesAgree(): void
    {
        $recipeId = $this->createRecipe();
        $this->planMeal($recipeId, '2026-08-05', 'lunch');

        $this->client->request('GET', '/api/meal-plan'.self::WEEK);
        $alias = $this->jsonResponse($this->client);

        $this->client->request('GET', '/api/v1/meal-plan'.self::WEEK);
        self::assertResponseIsSuccessful();
        self::assertSame($alias, $this->jsonResponse($this->client));

        $this->client->request('GET', '/api/meal-plan/shopping-list'.self::WEEK);
        $aliasList = $this->jsonResponse($this->client);

        $this->client->request('GET', '/api/v1/meal-plan/shopping-list'.self::WEEK);
        self::assertSame($aliasList, $this->jsonResponse($this->client));
    }

    public function testInvalidApiKeyIs401(): void
    {
        $this->client->setServerParameter('HTTP_X_API_KEY', 'wrong-key');
        $this->client->request('GET', '/api/meal-plan'.self::WEEK);
        self::assertResponseStatusCodeSame(401);
    }

    /** @return array<string, mixed> */
    private function slotAt(string $date, string $slot): array
    {
        $this->client->request('GET', '/api/meal-plan'.self::WEEK);
        self::assertResponseIsSuccessful();

        foreach ($this->jsonResponse($this->client)['days'] as $day) {
            if ($day['date'] !== $date) {
                continue;
            }

            foreach ($day['slots'] as $candidate) {
                if ($candidate['slot'] === $slot) {
                    return $candidate;
                }
            }
        }

        self::fail(sprintf('No "%s" slot on %s in the plan.', $slot, $date));
    }

    /** @return array<string, mixed> */
    private function mealAt(string $date, string $slot): array
    {
        $meals = $this->slotAt($date, $slot)['meals'];
        self::assertCount(1, $meals);

        return $meals[0];
    }

    private function planMeal(string $recipeId, string $date, string $slot, int $servings = 4): string
    {
        $this->client->request('POST', '/api/meal-plan', content: (string) json_encode([
            'date' => $date, 'slot' => $slot, 'recipeId' => $recipeId, 'servings' => $servings,
        ]));
        self::assertResponseStatusCodeSame(201);

        return $this->jsonResponse($this->client)['id'];
    }

    /** @param array<string, mixed> $overrides */
    private function createRecipe(array $overrides = []): string
    {
        $payload = array_merge([
            'title' => 'Naleśniki',
            'ingredients' => [
                ['name' => 'Mąka', 'quantity' => 500.0, 'unit' => 'g'],
                ['name' => 'Mleko', 'quantity' => 250.0, 'unit' => 'ml'],
            ],
            'servings' => 4,
        ], $overrides);

        $this->client->request('POST', '/api/recipes', content: (string) json_encode($payload));
        self::assertResponseStatusCodeSame(201);

        return $this->jsonResponse($this->client)['id'];
    }
}
