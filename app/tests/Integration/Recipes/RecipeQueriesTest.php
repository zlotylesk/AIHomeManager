<?php

declare(strict_types=1);

namespace App\Tests\Integration\Recipes;

use App\Messaging\QueryBus;
use App\Module\Recipes\Application\DTO\RecipeDetailDTO;
use App\Module\Recipes\Application\DTO\RecipeDTO;
use App\Module\Recipes\Application\Query\GetRecipeDetail;
use App\Module\Recipes\Application\Query\GetRecipes;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The read side runs against real MySQL on purpose: the tag filter is a
 * JSON_CONTAINS expression and the ordering is a stored column, so a test with
 * a doubled Connection would assert the SQL we wrote rather than the answer the
 * database gives back.
 */
final class RecipeQueriesTest extends KernelTestCase
{
    private Connection $connection;
    private QueryBus $queryBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->connection = $container->get(EntityManagerInterface::class)->getConnection();
        $this->queryBus = $container->get(QueryBus::class);

        $this->connection->executeStatement('DELETE FROM recipe_ingredients');
        $this->connection->executeStatement('DELETE FROM recipe_steps');
        $this->connection->executeStatement('DELETE FROM recipes');
    }

    public function testListsEveryRecipeOrderedByTitle(): void
    {
        $this->insertRecipe('r-2', 'Zupa pomidorowa');
        $this->insertRecipe('r-1', 'Naleśniki');
        $this->insertRecipe('r-3', 'Placki ziemniaczane');

        $result = $this->queryBus->ask(new GetRecipes());

        self::assertSame(
            ['Naleśniki', 'Placki ziemniaczane', 'Zupa pomidorowa'],
            array_map(static fn (RecipeDTO $r): string => $r->title, $result),
        );
    }

    public function testMapsEveryListFieldIncludingTheIngredientCount(): void
    {
        $this->insertRecipe('r-1', 'Naleśniki', servings: 4, prepTime: 30, tags: ['śniadanie', 'słodkie']);
        $this->insertIngredient('r-1', 0, 'Mąka', 200.0, 'g');
        $this->insertIngredient('r-1', 1, 'Mleko', 0.5, 'l');

        $result = $this->queryBus->ask(new GetRecipes());

        self::assertCount(1, $result);
        $recipe = $result[0];
        self::assertSame('r-1', $recipe->id);
        self::assertSame('Naleśniki', $recipe->title);
        self::assertSame(4, $recipe->servings);
        self::assertSame(30, $recipe->prepTimeMinutes);
        self::assertSame(['śniadanie', 'słodkie'], $recipe->tags);
        self::assertSame(2, $recipe->ingredientCount);
    }

    public function testRecipeWithoutOptionalFieldsReportsRealNullAndZero(): void
    {
        $this->insertRecipe('r-1', 'Herbata');

        $result = $this->queryBus->ask(new GetRecipes());

        self::assertNull($result[0]->prepTimeMinutes);
        self::assertSame([], $result[0]->tags);
        self::assertSame(0, $result[0]->ingredientCount);
    }

    /**
     * A LEFT JOIN onto both child tables would multiply their rows and report
     * six ingredients for a recipe that has two — the counter has to come from
     * a correlated subquery.
     */
    public function testIngredientCountIsNotInflatedByTheNumberOfSteps(): void
    {
        $this->insertRecipe('r-1', 'Naleśniki');
        $this->insertIngredient('r-1', 0, 'Mąka', 200.0, 'g');
        $this->insertIngredient('r-1', 1, 'Mleko', 0.5, 'l');
        $this->insertStep('r-1', 0, 'Wymieszaj');
        $this->insertStep('r-1', 1, 'Odstaw');
        $this->insertStep('r-1', 2, 'Smaż');

        $result = $this->queryBus->ask(new GetRecipes());

        self::assertSame(2, $result[0]->ingredientCount);
    }

    public function testFiltersByTag(): void
    {
        $this->insertRecipe('r-1', 'Naleśniki', tags: ['śniadanie', 'słodkie']);
        $this->insertRecipe('r-2', 'Zupa', tags: ['obiad']);
        $this->insertRecipe('r-3', 'Kanapka', tags: ['śniadanie']);

        $result = $this->queryBus->ask(new GetRecipes(tag: 'śniadanie'));

        self::assertSame(['r-3', 'r-1'], array_map(static fn (RecipeDTO $r): string => $r->id, $result));
    }

    /**
     * The aggregate lower-cases tags on write, so the needle has to be
     * lower-cased too — otherwise a user filtering by "Obiad" is told the
     * catalog is empty rather than being shown the recipe that is tagged.
     */
    public function testTagFilterMatchesRegardlessOfTheCaseTheUserTyped(): void
    {
        $this->insertRecipe('r-1', 'Zupa', tags: ['obiad']);

        $result = $this->queryBus->ask(new GetRecipes(tag: 'Obiad'));

        self::assertCount(1, $result);
        self::assertSame('r-1', $result[0]->id);
    }

    public function testTagFilterMatchesWholeTagsNotPrefixes(): void
    {
        $this->insertRecipe('r-1', 'Zupa', tags: ['obiadowy']);

        self::assertSame([], $this->queryBus->ask(new GetRecipes(tag: 'obiad')));
    }

    public function testFiltersByPhraseInTitleCaseInsensitively(): void
    {
        $this->insertRecipe('r-1', 'Zupa pomidorowa');
        $this->insertRecipe('r-2', 'Naleśniki');
        $this->insertRecipe('r-3', 'Zupa ogórkowa');

        $result = $this->queryBus->ask(new GetRecipes(phrase: 'ZUPA'));

        self::assertSame(['r-3', 'r-1'], array_map(static fn (RecipeDTO $r): string => $r->id, $result));
    }

    /**
     * A wildcard typed into a search box is literal text. Unescaped, "%" would
     * match every recipe and "_" would match every recipe with at least one
     * character — wrong answers that look like correct ones.
     */
    public function testPhraseFilterTreatsLikeWildcardsAsLiteralText(): void
    {
        // Each wildcard case is paired with a decoy the unescaped needle would
        // also match, so dropping the escaping fails this test rather than
        // passing it for lack of anything to collide with.
        $this->insertRecipe('r-1', 'Sok 100% jabłkowy');
        $this->insertRecipe('r-2', 'Sok 100 procent jabłkowy');
        $this->insertRecipe('r-3', 'Zupa_krem');
        $this->insertRecipe('r-4', 'Zupa krem');

        $percent = $this->queryBus->ask(new GetRecipes(phrase: '100%'));
        self::assertSame(['r-1'], array_map(static fn (RecipeDTO $r): string => $r->id, $percent));

        $underscore = $this->queryBus->ask(new GetRecipes(phrase: 'Zupa_'));
        self::assertSame(['r-3'], array_map(static fn (RecipeDTO $r): string => $r->id, $underscore));
    }

    public function testTagAndPhraseFiltersCombine(): void
    {
        $this->insertRecipe('r-1', 'Zupa pomidorowa', tags: ['obiad']);
        $this->insertRecipe('r-2', 'Zupa mleczna', tags: ['śniadanie']);
        $this->insertRecipe('r-3', 'Kotlet', tags: ['obiad']);

        $result = $this->queryBus->ask(new GetRecipes(tag: 'obiad', phrase: 'zupa'));

        self::assertSame(['r-1'], array_map(static fn (RecipeDTO $r): string => $r->id, $result));
    }

    /**
     * An emptied search box submits '', which means "show everything" — not
     * "show me the recipes tagged with nothing".
     */
    public function testBlankFiltersAreNoFilterAtAll(): void
    {
        $this->insertRecipe('r-1', 'Zupa', tags: ['obiad']);
        $this->insertRecipe('r-2', 'Naleśniki');

        self::assertCount(2, $this->queryBus->ask(new GetRecipes(tag: '', phrase: '   ')));
    }

    public function testEmptyCatalogReturnsAnEmptyList(): void
    {
        self::assertSame([], $this->queryBus->ask(new GetRecipes()));
    }

    public function testDetailReturnsIngredientsAndStepsInStoredOrder(): void
    {
        $this->insertRecipe('r-1', 'Naleśniki', servings: 4, prepTime: 30, tags: ['śniadanie']);

        // Inserted out of order on purpose: a handler that leans on insertion
        // or primary-key order would pass with sequential seeding and silently
        // hand the user a recipe whose steps are shuffled.
        $this->insertStep('r-1', 2, 'Smaż na patelni');
        $this->insertStep('r-1', 0, 'Wymieszaj składniki');
        $this->insertStep('r-1', 1, 'Odstaw na 15 minut');
        $this->insertIngredient('r-1', 1, 'Mleko', 0.5, 'l');
        $this->insertIngredient('r-1', 0, 'Mąka', 200.0, 'g');

        $detail = $this->queryBus->ask(new GetRecipeDetail('r-1'));

        self::assertInstanceOf(RecipeDetailDTO::class, $detail);
        self::assertSame('Naleśniki', $detail->recipe->title);
        self::assertSame(4, $detail->recipe->servings);
        self::assertSame(30, $detail->recipe->prepTimeMinutes);
        self::assertSame(['śniadanie'], $detail->recipe->tags);
        self::assertSame(2, $detail->recipe->ingredientCount);

        self::assertSame(
            ['Wymieszaj składniki', 'Odstaw na 15 minut', 'Smaż na patelni'],
            $detail->steps,
        );

        self::assertCount(2, $detail->ingredients);
        self::assertSame('Mąka', $detail->ingredients[0]->name);
        self::assertSame(200.0, $detail->ingredients[0]->quantity);
        self::assertSame('g', $detail->ingredients[0]->unit);
        self::assertSame('Mleko', $detail->ingredients[1]->name);
        self::assertSame(0.5, $detail->ingredients[1]->quantity);
        self::assertSame('l', $detail->ingredients[1]->unit);
    }

    public function testDetailOfARecipeWithoutStepsReturnsAnEmptyStepList(): void
    {
        $this->insertRecipe('r-1', 'Herbata');
        $this->insertIngredient('r-1', 0, 'Woda', 0.25, 'l');

        $detail = $this->queryBus->ask(new GetRecipeDetail('r-1'));

        self::assertInstanceOf(RecipeDetailDTO::class, $detail);
        self::assertSame([], $detail->steps);
        self::assertCount(1, $detail->ingredients);
    }

    public function testDetailReturnsNullForAnUnknownRecipe(): void
    {
        $this->insertRecipe('r-1', 'Zupa');

        self::assertNull($this->queryBus->ask(new GetRecipeDetail('does-not-exist')));
    }

    public function testDetailCarriesOnlyItsOwnChildren(): void
    {
        $this->insertRecipe('r-1', 'Zupa');
        $this->insertIngredient('r-1', 0, 'Woda', 1.0, 'l');
        $this->insertStep('r-1', 0, 'Zagotuj');

        $this->insertRecipe('r-2', 'Naleśniki');
        $this->insertIngredient('r-2', 0, 'Mąka', 200.0, 'g');
        $this->insertStep('r-2', 0, 'Wymieszaj');

        $detail = $this->queryBus->ask(new GetRecipeDetail('r-1'));

        self::assertInstanceOf(RecipeDetailDTO::class, $detail);
        self::assertSame(['Zagotuj'], $detail->steps);
        self::assertCount(1, $detail->ingredients);
        self::assertSame('Woda', $detail->ingredients[0]->name);
    }

    /**
     * @param list<string> $tags
     */
    private function insertRecipe(string $id, string $title, int $servings = 1, ?int $prepTime = null, array $tags = []): void
    {
        $this->connection->executeStatement(
            'INSERT INTO recipes (id, title, servings, prep_time_minutes, tags) VALUES (:id, :title, :servings, :prepTime, :tags)',
            [
                'id' => $id,
                'title' => $title,
                'servings' => $servings,
                'prepTime' => $prepTime,
                'tags' => json_encode($tags, JSON_THROW_ON_ERROR),
            ],
            ['servings' => ParameterType::INTEGER, 'prepTime' => ParameterType::INTEGER],
        );
    }

    private function insertIngredient(string $recipeId, int $position, string $name, float $quantity, string $unit): void
    {
        $this->connection->executeStatement(
            'INSERT INTO recipe_ingredients (recipe_id, `position`, name, quantity, unit) VALUES (:recipeId, :position, :name, :quantity, :unit)',
            ['recipeId' => $recipeId, 'position' => $position, 'name' => $name, 'quantity' => $quantity, 'unit' => $unit],
            ['position' => ParameterType::INTEGER],
        );
    }

    private function insertStep(string $recipeId, int $position, string $text): void
    {
        $this->connection->executeStatement(
            'INSERT INTO recipe_steps (recipe_id, `position`, text) VALUES (:recipeId, :position, :text)',
            ['recipeId' => $recipeId, 'position' => $position, 'text' => $text],
            ['position' => ParameterType::INTEGER],
        );
    }
}
