<?php

declare(strict_types=1);

namespace App\Tests\Integration\Recipes;

use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RecipesApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private const string UNKNOWN_UUID = '00000000-0000-0000-0000-000000000000';

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

    public function testCreateListDetailUpdateDelete(): void
    {
        $id = $this->createRecipe();

        $this->client->request('GET', '/api/recipes');
        self::assertResponseIsSuccessful();
        $list = $this->jsonList($this->client);
        self::assertCount(1, $list);
        self::assertSame($id, $list[0]['id']);
        self::assertSame('Naleśniki', $list[0]['title']);
        self::assertSame(4, $list[0]['servings']);
        self::assertSame(30, $list[0]['prepTimeMinutes']);
        self::assertSame(['obiad'], $list[0]['tags']);
        // The list carries a count, never the ingredients themselves.
        self::assertSame(2, $list[0]['ingredientCount']);
        self::assertArrayNotHasKey('ingredients', $list[0]);

        $this->client->request('GET', '/api/recipes/'.$id);
        self::assertResponseIsSuccessful();
        $detail = $this->jsonResponse($this->client);
        // The recipe half is flattened, not nested under an envelope key.
        self::assertArrayNotHasKey('recipe', $detail);
        self::assertSame('Naleśniki', $detail['title']);
        // JSON has one number type, so a whole quantity comes back as `500`,
        // not `500.0` — compared numerically rather than with assertSame
        // against a float (the Insights HMAI-332 note).
        self::assertSame(['Mąka', 'Mleko'], array_column($detail['ingredients'], 'name'));
        self::assertSame(['g', 'ml'], array_column($detail['ingredients'], 'unit'));
        self::assertEqualsWithDelta(500.0, $detail['ingredients'][0]['quantity'], 0.0001);
        self::assertEqualsWithDelta(250.0, $detail['ingredients'][1]['quantity'], 0.0001);
        self::assertSame(['Wymieszaj składniki', 'Usmaż'], $detail['steps']);

        $this->client->request('PUT', '/api/recipes/'.$id, content: (string) json_encode([
            'title' => 'Naleśniki z serem',
            'ingredients' => [['name' => 'Mąka', 'quantity' => 600.0, 'unit' => 'g']],
            'steps' => ['Wymieszaj'],
            'servings' => 6,
            'prepTimeMinutes' => null,
            'tags' => ['kolacja'],
        ]));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/recipes/'.$id);
        $updated = $this->jsonResponse($this->client);
        self::assertSame('Naleśniki z serem', $updated['title']);
        self::assertSame(6, $updated['servings']);
        self::assertNull($updated['prepTimeMinutes']);
        self::assertSame(['kolacja'], $updated['tags']);
        self::assertCount(1, $updated['ingredients']);
        self::assertSame(['Wymieszaj'], $updated['steps']);

        $this->client->request('DELETE', '/api/recipes/'.$id);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/recipes');
        self::assertSame([], $this->jsonList($this->client));
    }

    public function testFiltersByTagAndPhrase(): void
    {
        $this->createRecipe(['title' => 'Zupa pomidorowa', 'tags' => ['obiad']]);
        $this->createRecipe(['title' => 'Naleśniki', 'tags' => ['śniadanie']]);

        $this->client->request('GET', '/api/recipes?tag=obiad');
        self::assertCount(1, $this->jsonList($this->client));

        $this->client->request('GET', '/api/recipes?phrase=nale');
        $byPhrase = $this->jsonList($this->client);
        self::assertCount(1, $byPhrase);
        self::assertSame('Naleśniki', $byPhrase[0]['title']);

        // Both filters at once, contradicting each other.
        $this->client->request('GET', '/api/recipes?tag=obiad&phrase=nale');
        self::assertSame([], $this->jsonList($this->client));
    }

    /**
     * An emptied search box submits `''`. Answering "no recipes" to a user who
     * just cleared it would be a wrong answer that looks like a correct one.
     */
    public function testBlankFiltersAreNoFilterAtAll(): void
    {
        $this->createRecipe();

        $this->client->request('GET', '/api/recipes?tag=&phrase=');
        self::assertCount(1, $this->jsonList($this->client));
    }

    public function testUnknownRecipeIsNotFound(): void
    {
        $this->client->request('GET', '/api/recipes/'.self::UNKNOWN_UUID);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('PUT', '/api/recipes/'.self::UNKNOWN_UUID, content: (string) json_encode([
            'title' => 'X', 'ingredients' => [['name' => 'Mąka', 'quantity' => 1.0, 'unit' => 'g']], 'servings' => 1,
        ]));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('DELETE', '/api/recipes/'.self::UNKNOWN_UUID);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCreatePayloads')]
    public function testRejectsMalformedCreatePayloads(array $payload): void
    {
        $this->client->request('POST', '/api/recipes', content: (string) json_encode($payload));
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('error', $this->jsonResponse($this->client));
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function invalidCreatePayloads(): iterable
    {
        $ingredient = ['name' => 'Mąka', 'quantity' => 500.0, 'unit' => 'g'];

        yield 'no title' => [['ingredients' => [$ingredient]]];
        yield 'blank title' => [['title' => '  ', 'ingredients' => [$ingredient]]];
        yield 'no ingredients key' => [['title' => 'X']];
        yield 'empty ingredient list' => [['title' => 'X', 'ingredients' => []]];
        yield 'unknown unit' => [['title' => 'X', 'ingredients' => [['name' => 'Mąka', 'quantity' => 1.0, 'unit' => 'szklanka']]]];
        yield 'ingredient quantity as string' => [['title' => 'X', 'ingredients' => [['name' => 'Mąka', 'quantity' => '2,5', 'unit' => 'g']]]];
        yield 'ingredient not an object' => [['title' => 'X', 'ingredients' => ['Mąka']]];
        yield 'zero quantity' => [['title' => 'X', 'ingredients' => [['name' => 'Mąka', 'quantity' => 0.0, 'unit' => 'g']]]];
        yield 'duplicate (name, unit)' => [['title' => 'X', 'ingredients' => [$ingredient, ['name' => 'mąka', 'quantity' => 1.0, 'unit' => 'g']]]];
        yield 'servings below one' => [['title' => 'X', 'ingredients' => [$ingredient], 'servings' => 0]];
        yield 'servings not an integer' => [['title' => 'X', 'ingredients' => [$ingredient], 'servings' => '4']];
        yield 'steps not strings' => [['title' => 'X', 'ingredients' => [$ingredient], 'steps' => [['a']]]];
    }

    /**
     * JSON has one number type, so a whole quantity arrives as an int. Rejecting
     * it would refuse an entirely correct payload.
     */
    public function testAcceptsAWholeQuantitySentAsAnInteger(): void
    {
        $this->client->request('POST', '/api/recipes', content: (string) json_encode([
            'title' => 'Jajecznica',
            'ingredients' => [['name' => 'Jajko', 'quantity' => 3, 'unit' => 'piece']],
        ]));
        self::assertResponseStatusCodeSame(201);

        $id = $this->jsonResponse($this->client)['id'];
        $this->client->request('GET', '/api/recipes/'.$id);
        self::assertEqualsWithDelta(3.0, $this->jsonResponse($this->client)['ingredients'][0]['quantity'], 0.0001);
    }

    /**
     * The one field of the full replace that must not fall back to a default:
     * the shopping list scales every quantity by planned/recipe servings, so a
     * silently reset value would multiply a whole ingredient list.
     */
    public function testUpdateRequiresServings(): void
    {
        $id = $this->createRecipe();

        $this->client->request('PUT', '/api/recipes/'.$id, content: (string) json_encode([
            'title' => 'Naleśniki',
            'ingredients' => [['name' => 'Mąka', 'quantity' => 500.0, 'unit' => 'g']],
        ]));
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', '/api/recipes/'.$id);
        self::assertSame(4, $this->jsonResponse($this->client)['servings']);
    }

    public function testARejectedUpdateLeavesTheRecipeUntouched(): void
    {
        $id = $this->createRecipe();

        $this->client->request('PUT', '/api/recipes/'.$id, content: (string) json_encode([
            'title' => 'Nowa nazwa',
            'ingredients' => [['name' => 'Mąka', 'quantity' => 1.0, 'unit' => 'szklanka']],
            'servings' => 2,
        ]));
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', '/api/recipes/'.$id);
        $unchanged = $this->jsonResponse($this->client);
        self::assertSame('Naleśniki', $unchanged['title']);
        self::assertSame(4, $unchanged['servings']);
        self::assertCount(2, $unchanged['ingredients']);
    }

    /**
     * A recipe still on the calendar cannot be deleted: the shopping list joins
     * the plan to the recipes, so a dangling reference would leave it silently
     * short by that meal's ingredients.
     */
    public function testDeletingAPlannedRecipeIsAConflict(): void
    {
        $recipeId = $this->createRecipe();
        $this->client->request('POST', '/api/meal-plan', content: (string) json_encode([
            'date' => '2026-08-05', 'slot' => 'lunch', 'recipeId' => $recipeId, 'servings' => 4,
        ]));
        self::assertResponseStatusCodeSame(201);
        $mealId = $this->jsonResponse($this->client)['id'];

        $this->client->request('DELETE', '/api/recipes/'.$recipeId);
        self::assertResponseStatusCodeSame(409);
        self::assertArrayHasKey('error', $this->jsonResponse($this->client));

        // Freeing the calendar entry unblocks the delete.
        $this->client->request('DELETE', '/api/meal-plan/'.$mealId);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('DELETE', '/api/recipes/'.$recipeId);
        self::assertResponseStatusCodeSame(204);
    }

    public function testVersionedAndAliasPrefixesAgree(): void
    {
        $this->createRecipe();

        $this->client->request('GET', '/api/recipes');
        $alias = $this->jsonResponse($this->client);

        $this->client->request('GET', '/api/v1/recipes');
        self::assertResponseIsSuccessful();
        self::assertSame($alias, $this->jsonResponse($this->client));
    }

    public function testInvalidApiKeyIs401(): void
    {
        $this->client->setServerParameter('HTTP_X_API_KEY', 'wrong-key');
        $this->client->request('GET', '/api/recipes');
        self::assertResponseStatusCodeSame(401);
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
            'steps' => ['Wymieszaj składniki', 'Usmaż'],
            'servings' => 4,
            'prepTimeMinutes' => 30,
            'tags' => ['obiad'],
        ], $overrides);

        $this->client->request('POST', '/api/recipes', content: (string) json_encode($payload));
        self::assertResponseStatusCodeSame(201);

        return $this->jsonResponse($this->client)['id'];
    }
}
