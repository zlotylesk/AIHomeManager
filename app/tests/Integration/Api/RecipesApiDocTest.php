<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pins the OpenAPI contract for the Recipes module, reaching parity with the
 * other modules' *ApiDocTest: every `/api/v1/recipes*` and `/api/v1/meal-plan*`
 * operation is documented and `Recipes`-tagged, the read schemas expose every
 * field the frontend consumes, and the module's own rules — the flattened
 * detail, the required plan window, the 409 guards, the full-replace update —
 * are part of the published contract rather than folklore.
 *
 * Note on nullability: the contract is OpenAPI **3.1**, which encodes a
 * nullable field as a type union (`type: ["integer","null"]`) rather than the
 * 3.0 `nullable: true` flag.
 */
final class RecipesApiDocTest extends WebTestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function recipesOperations(): array
    {
        return [
            'list recipes' => ['/api/v1/recipes', 'get'],
            'create recipe' => ['/api/v1/recipes', 'post'],
            'recipe detail' => ['/api/v1/recipes/{id}', 'get'],
            'replace recipe' => ['/api/v1/recipes/{id}', 'put'],
            'delete recipe' => ['/api/v1/recipes/{id}', 'delete'],
            'meal plan' => ['/api/v1/meal-plan', 'get'],
            'plan a meal' => ['/api/v1/meal-plan', 'post'],
            'move a meal' => ['/api/v1/meal-plan/{id}', 'patch'],
            'unplan a meal' => ['/api/v1/meal-plan/{id}', 'delete'],
            'shopping list' => ['/api/v1/meal-plan/shopping-list', 'get'],
        ];
    }

    public function testEveryRecipesOperationIsDocumentedAndTagged(): void
    {
        $spec = $this->fetchSpec(static::createClient());

        foreach (self::recipesOperations() as $label => [$path, $method]) {
            $operation = $this->nestedArray($spec, 'paths', $path, $method);
            self::assertContains(
                'Recipes',
                $operation['tags'] ?? [],
                sprintf('%s %s (%s) must be tagged "Recipes".', strtoupper($method), $path, $label),
            );
        }
    }

    /** The list carries a count, never the ingredients — that is the contract. */
    public function testTheRecipeSchemaMirrorsTheNormalizer(): void
    {
        $spec = $this->fetchSpec(static::createClient());
        $schema = $this->nestedArray($spec, 'components', 'schemas', 'RecipeDTO', 'properties');

        self::assertSame(
            ['id', 'title', 'servings', 'prepTimeMinutes', 'tags', 'ingredientCount'],
            array_keys($schema),
        );
        self::assertSame(['integer', 'null'], $schema['prepTimeMinutes']['type']);
        self::assertSame('array', $schema['tags']['type']);
        self::assertSame('integer', $schema['ingredientCount']['type']);
        self::assertArrayNotHasKey('ingredients', $schema);
    }

    public function testTheIngredientSchemaCarriesTheCanonicalUnit(): void
    {
        $spec = $this->fetchSpec(static::createClient());
        $schema = $this->nestedArray($spec, 'components', 'schemas', 'RecipeIngredientDTO', 'properties');

        self::assertSame(['name', 'quantity', 'unit'], array_keys($schema));
        self::assertSame('number', $schema['quantity']['type']);
        self::assertSame('string', $schema['unit']['type']);
    }

    /**
     * The detail normalizer flattens the recipe to the top level, so the
     * response is allOf[RecipeDTO, {ingredients, steps}] — a $ref to
     * RecipeDetailDTO would document a `recipe` envelope that never ships.
     */
    public function testTheDetailIsDocumentedAsAFlattenedRecipe(): void
    {
        $spec = $this->fetchSpec(static::createClient());
        $content = $this->nestedArray($spec, 'paths', '/api/v1/recipes/{id}', 'get', 'responses', '200', 'content', 'application/json', 'schema');

        self::assertArrayHasKey('allOf', $content);
        self::assertSame('#/components/schemas/RecipeDTO', $content['allOf'][0]['$ref']);
        self::assertSame(
            '#/components/schemas/RecipeIngredientDTO',
            $content['allOf'][1]['properties']['ingredients']['items']['$ref'],
        );
        self::assertSame('string', $content['allOf'][1]['properties']['steps']['items']['type']);

        self::assertSame(
            '#/components/responses/NotFoundError',
            $this->nestedArray($spec, 'paths', '/api/v1/recipes/{id}', 'get', 'responses', '404')['$ref'],
        );
    }

    public function testCreateRequiresATitleAndIngredientsAndReturnsAnId(): void
    {
        $spec = $this->fetchSpec(static::createClient());
        $body = $this->nestedArray($spec, 'paths', '/api/v1/recipes', 'post', 'requestBody', 'content', 'application/json', 'schema');

        self::assertSame(['title', 'ingredients'], $body['required']);
        self::assertSame('#/components/schemas/RecipeIngredientDTO', $body['properties']['ingredients']['items']['$ref']);

        $created = $this->nestedArray($spec, 'paths', '/api/v1/recipes', 'post', 'responses', '201', 'content', 'application/json', 'schema', 'properties');
        self::assertSame('uuid', $created['id']['format']);
    }

    /**
     * Servings are required on the full replace but not on create — the
     * asymmetry is deliberate (a silently defaulted value would rescale the
     * whole shopping list), so it belongs in the contract a client reads.
     */
    public function testTheFullReplaceRequiresServings(): void
    {
        $spec = $this->fetchSpec(static::createClient());

        $create = $this->nestedArray($spec, 'paths', '/api/v1/recipes', 'post', 'requestBody', 'content', 'application/json', 'schema');
        self::assertNotContains('servings', $create['required']);

        $replace = $this->nestedArray($spec, 'paths', '/api/v1/recipes/{id}', 'put', 'requestBody', 'content', 'application/json', 'schema');
        self::assertContains('servings', $replace['required']);
    }

    public function testDeletingARecipeDocumentsTheStillPlannedConflict(): void
    {
        $spec = $this->fetchSpec(static::createClient());
        $responses = $this->nestedArray($spec, 'paths', '/api/v1/recipes/{id}', 'delete', 'responses');

        self::assertArrayHasKey('204', $responses);
        self::assertSame('#/components/responses/NotFoundError', $responses['404']['$ref']);
        self::assertSame('#/components/responses/ConflictError', $responses['409']['$ref']);
    }

    /**
     * The window is required on both plan reads — a default would risk
     * answering confidently about a week the caller never asked for.
     */
    public function testBothPlanReadsRequireTheWindow(): void
    {
        $spec = $this->fetchSpec(static::createClient());

        foreach (['/api/v1/meal-plan', '/api/v1/meal-plan/shopping-list'] as $path) {
            $parameters = $this->nestedArray($spec, 'paths', $path, 'get', 'parameters');
            $required = [];

            foreach ($parameters as $parameter) {
                self::assertIsArray($parameter);
                self::assertTrue($parameter['required'] ?? false, sprintf('"%s" on %s must be required.', $parameter['name'], $path));
                $required[] = $parameter['name'];
            }

            self::assertSame(['from', 'to'], $required, sprintf('%s must take exactly a from/to window.', $path));
        }
    }

    /** Three levels deep, all resolved — a client lays out a grid from this. */
    public function testTheMealPlanSchemaNestsDaysSlotsAndMeals(): void
    {
        $spec = $this->fetchSpec(static::createClient());
        $schemas = $this->nestedArray($spec, 'components', 'schemas');

        self::assertSame(['from', 'to', 'days'], array_keys($schemas['MealPlanDTO']['properties']));
        self::assertSame('#/components/schemas/MealPlanDayDTO', $schemas['MealPlanDTO']['properties']['days']['items']['$ref']);
        self::assertSame('#/components/schemas/MealPlanSlotDTO', $schemas['MealPlanDayDTO']['properties']['slots']['items']['$ref']);
        self::assertSame('#/components/schemas/PlannedMealDTO', $schemas['MealPlanSlotDTO']['properties']['meals']['items']['$ref']);

        // Nullable because a plan entry keeps its place even if its recipe
        // vanished — the card must not silently disappear from its slot.
        self::assertSame(['string', 'null'], $schemas['PlannedMealDTO']['properties']['recipeTitle']['type']);
    }

    public function testPlanningAMealDocumentsItsBodyAndBothFailureModes(): void
    {
        $spec = $this->fetchSpec(static::createClient());
        $body = $this->nestedArray($spec, 'paths', '/api/v1/meal-plan', 'post', 'requestBody', 'content', 'application/json', 'schema');

        self::assertSame(['date', 'slot', 'recipeId'], $body['required']);
        self::assertSame(['breakfast', 'lunch', 'dinner', 'snack'], $body['properties']['slot']['enum']);

        $responses = $this->nestedArray($spec, 'paths', '/api/v1/meal-plan', 'post', 'responses');
        self::assertSame('#/components/responses/NotFoundError', $responses['404']['$ref']);
        self::assertSame('#/components/responses/ConflictError', $responses['409']['$ref']);
        self::assertSame('#/components/responses/UnprocessableEntityError', $responses['422']['$ref']);
    }

    public function testTheShoppingListSchemaEchoesTheWindowAndCarriesRawQuantities(): void
    {
        $spec = $this->fetchSpec(static::createClient());
        $schemas = $this->nestedArray($spec, 'components', 'schemas');

        self::assertSame(['from', 'to', 'items'], array_keys($schemas['ShoppingListDTO']['properties']));
        self::assertSame('#/components/schemas/ShoppingListItemDTO', $schemas['ShoppingListDTO']['properties']['items']['items']['$ref']);
        self::assertSame(['name', 'unit', 'quantity'], array_keys($schemas['ShoppingListItemDTO']['properties']));
        self::assertSame('number', $schemas['ShoppingListItemDTO']['properties']['quantity']['type']);
    }

    /**
     * @return array<mixed>
     */
    private function fetchSpec(KernelBrowser $client): array
    {
        $client->request('GET', '/api/doc.json');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode(), 'The OpenAPI spec must be reachable without an API key.');

        $content = $response->getContent();
        self::assertIsString($content);
        $doc = json_decode($content, true);
        self::assertIsArray($doc);

        return $doc;
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<mixed>
     */
    private function nestedArray(array $tree, string ...$keys): array
    {
        $node = $tree;
        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $node, sprintf('Missing "%s" in the OpenAPI document.', $key));
            self::assertIsArray($node[$key], sprintf('"%s" must be an object in the OpenAPI document.', $key));
            $node = $node[$key];
        }

        return $node;
    }
}
