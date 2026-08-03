<?php

declare(strict_types=1);

namespace App\Module\Recipes\Infrastructure\Persistence;

use App\Module\Recipes\Application\TagsColumn;
use App\Module\Recipes\Domain\Entity\Recipe;
use App\Module\Recipes\Domain\Enum\MeasurementUnit;
use App\Module\Recipes\Domain\Repository\RecipeRepositoryInterface;
use App\Module\Recipes\Domain\ValueObject\Ingredient;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Persists the Recipe aggregate over three tables with plain DBAL.
 *
 * Deliberately NOT an ORM mapping, unlike every other aggregate in the project.
 * Ingredient is a `final readonly` value object with no identity, and Doctrine
 * cannot map a collection of embeddables at all — the only ORM-shaped way out
 * would be to demote Ingredient to an entity with a surrogate id, giving a
 * value object an identity purely to satisfy the mapper. Doctrine also hydrates
 * entities by bypassing the constructor, so an ORM-mapped Recipe would be the
 * one Recipe in the system whose invariants (at least one ingredient, unique
 * (name, unit), normalised tags) had never run.
 *
 * Reading through the real constructor instead means every recipe that comes
 * out of the database has been validated by the same code that validates one
 * the user just typed. The cost is that these three tables are outside
 * `doctrine:schema:validate` (they are excluded from the schema filter, like
 * every other non-ORM table), so the round-trip integration test is what pins
 * the mapping.
 */
final readonly class DoctrineRecipeRepository implements RecipeRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(Recipe $recipe): void
    {
        $this->connection->transactional(function (Connection $connection) use ($recipe): void {
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO recipes (id, title, servings, prep_time_minutes, tags)
                    VALUES (:id, :title, :servings, :prepTime, :tags) AS new
                    ON DUPLICATE KEY UPDATE
                        title = new.title,
                        servings = new.servings,
                        prep_time_minutes = new.prep_time_minutes,
                        tags = new.tags
                    SQL,
                [
                    'id' => $recipe->id(),
                    'title' => $recipe->title(),
                    'servings' => $recipe->servings(),
                    'prepTime' => $recipe->prepTimeMinutes(),
                    'tags' => TagsColumn::encode($recipe->tags()),
                ],
                [
                    'servings' => ParameterType::INTEGER,
                    'prepTime' => ParameterType::INTEGER,
                ],
            );

            // Ingredients and steps are value objects carried by position, not
            // rows with an identity to update — replacing them wholesale is the
            // only operation that matches what the aggregate actually does to
            // them, and it keeps a removed ingredient from surviving a save.
            $this->replaceChildren($connection, $recipe);
        });
    }

    public function findById(string $id): ?Recipe
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, title, servings, prep_time_minutes, tags FROM recipes WHERE id = :id',
            ['id' => $id],
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrate(
            $row,
            $this->ingredientsFor([$id])[$id] ?? [],
            $this->stepsFor([$id])[$id] ?? [],
        );
    }

    /** @return Recipe[] */
    public function findAll(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, title, servings, prep_time_minutes, tags FROM recipes ORDER BY title ASC',
        );

        if ([] === $rows) {
            return [];
        }

        // Two extra queries for the whole set rather than two per recipe — the
        // N+1 the Series loader avoids the same way.
        $ids = array_map(static fn (array $row): string => (string) $row['id'], $rows);
        $ingredients = $this->ingredientsFor($ids);
        $steps = $this->stepsFor($ids);

        return array_map(
            fn (array $row): Recipe => $this->hydrate(
                $row,
                $ingredients[(string) $row['id']] ?? [],
                $steps[(string) $row['id']] ?? [],
            ),
            $rows,
        );
    }

    public function remove(Recipe $recipe): void
    {
        $this->connection->transactional(function (Connection $connection) use ($recipe): void {
            $this->deleteChildren($connection, $recipe->id());
            $connection->executeStatement('DELETE FROM recipes WHERE id = :id', ['id' => $recipe->id()]);
        });
    }

    private function replaceChildren(Connection $connection, Recipe $recipe): void
    {
        $this->deleteChildren($connection, $recipe->id());

        foreach ($recipe->ingredients() as $position => $ingredient) {
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO recipe_ingredients (recipe_id, `position`, name, quantity, unit)
                    VALUES (:recipeId, :position, :name, :quantity, :unit)
                    SQL,
                [
                    'recipeId' => $recipe->id(),
                    'position' => $position,
                    'name' => $ingredient->name(),
                    'quantity' => $ingredient->quantity(),
                    'unit' => $ingredient->unit()->value,
                ],
                ['position' => ParameterType::INTEGER],
            );
        }

        foreach ($recipe->steps() as $position => $text) {
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO recipe_steps (recipe_id, `position`, text)
                    VALUES (:recipeId, :position, :text)
                    SQL,
                [
                    'recipeId' => $recipe->id(),
                    'position' => $position,
                    'text' => $text,
                ],
                ['position' => ParameterType::INTEGER],
            );
        }
    }

    private function deleteChildren(Connection $connection, string $recipeId): void
    {
        $connection->executeStatement('DELETE FROM recipe_ingredients WHERE recipe_id = :id', ['id' => $recipeId]);
        $connection->executeStatement('DELETE FROM recipe_steps WHERE recipe_id = :id', ['id' => $recipeId]);
    }

    /**
     * @param list<string> $recipeIds
     *
     * @return array<string, list<Ingredient>>
     */
    private function ingredientsFor(array $recipeIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT recipe_id, name, quantity, unit
                FROM recipe_ingredients
                WHERE recipe_id IN (:ids)
                ORDER BY recipe_id ASC, `position` ASC
                SQL,
            ['ids' => $recipeIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $byRecipe = [];
        foreach ($rows as $row) {
            $byRecipe[(string) $row['recipe_id']][] = new Ingredient(
                (string) $row['name'],
                (float) $row['quantity'],
                MeasurementUnit::from((string) $row['unit']),
            );
        }

        return $byRecipe;
    }

    /**
     * @param list<string> $recipeIds
     *
     * @return array<string, list<string>>
     */
    private function stepsFor(array $recipeIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT recipe_id, text
                FROM recipe_steps
                WHERE recipe_id IN (:ids)
                ORDER BY recipe_id ASC, `position` ASC
                SQL,
            ['ids' => $recipeIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $byRecipe = [];
        foreach ($rows as $row) {
            $byRecipe[(string) $row['recipe_id']][] = (string) $row['text'];
        }

        return $byRecipe;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<Ingredient>     $ingredients
     * @param list<string>         $steps
     */
    private function hydrate(array $row, array $ingredients, array $steps): Recipe
    {
        return new Recipe(
            (string) $row['id'],
            (string) $row['title'],
            $ingredients,
            $steps,
            (int) $row['servings'],
            null === $row['prep_time_minutes'] ? null : (int) $row['prep_time_minutes'],
            TagsColumn::parse($row['tags']),
        );
    }
}
