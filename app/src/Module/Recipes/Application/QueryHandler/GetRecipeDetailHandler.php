<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\QueryHandler;

use App\Module\Recipes\Application\DTO\RecipeDetailDTO;
use App\Module\Recipes\Application\DTO\RecipeDTO;
use App\Module\Recipes\Application\DTO\RecipeIngredientDTO;
use App\Module\Recipes\Application\Query\GetRecipeDetail;
use App\Module\Recipes\Application\TagsColumn;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetRecipeDetailHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(GetRecipeDetail $query): ?RecipeDetailDTO
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    r.id,
                    r.title,
                    r.servings,
                    r.prep_time_minutes,
                    r.tags,
                    (SELECT COUNT(*) FROM recipe_ingredients i WHERE i.recipe_id = r.id) AS ingredient_count
                FROM recipes r
                WHERE r.id = :id
                SQL,
            ['id' => $query->id],
        );

        if (false === $row) {
            return null;
        }

        return new RecipeDetailDTO(
            recipe: new RecipeDTO(
                id: (string) $row['id'],
                title: (string) $row['title'],
                servings: (int) $row['servings'],
                prepTimeMinutes: null === $row['prep_time_minutes'] ? null : (int) $row['prep_time_minutes'],
                tags: TagsColumn::parse($row['tags']),
                ingredientCount: (int) $row['ingredient_count'],
            ),
            ingredients: $this->ingredients($query->id),
            steps: $this->steps($query->id),
        );
    }

    /**
     * Ordered by the stored position, never by insertion or primary-key order:
     * an ingredient list is read alongside the steps that consume it, and a
     * scrambled one silently describes a different recipe.
     *
     * @return list<RecipeIngredientDTO>
     */
    private function ingredients(string $recipeId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT name, quantity, unit
                FROM recipe_ingredients
                WHERE recipe_id = :id
                ORDER BY `position` ASC
                SQL,
            ['id' => $recipeId],
        );

        return array_map(
            static fn (array $row): RecipeIngredientDTO => new RecipeIngredientDTO(
                name: (string) $row['name'],
                quantity: (float) $row['quantity'],
                unit: (string) $row['unit'],
            ),
            $rows,
        );
    }

    /**
     * @return list<string>
     */
    private function steps(string $recipeId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT text
                FROM recipe_steps
                WHERE recipe_id = :id
                ORDER BY `position` ASC
                SQL,
            ['id' => $recipeId],
        );

        return array_map(static fn (array $row): string => (string) $row['text'], $rows);
    }
}
