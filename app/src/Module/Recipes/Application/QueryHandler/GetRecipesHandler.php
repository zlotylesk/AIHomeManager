<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\QueryHandler;

use App\Module\Recipes\Application\DTO\RecipeDTO;
use App\Module\Recipes\Application\Query\GetRecipes;
use App\Module\Recipes\Application\TagsColumn;
use App\Shared\Pagination\Page;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetRecipesHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return Page<RecipeDTO> */
    public function __invoke(GetRecipes $query): Page
    {
        $conditions = [];
        $params = [];

        $tag = self::normalize($query->tag);
        if (null !== $tag) {
            // JSON_CONTAINS is exact-element membership, which is what "tagged
            // X" means — JSON_SEARCH with wildcards would also match a recipe
            // tagged "obiadowy" when the user asked for "obiad".
            //
            // The needle is lower-cased because the aggregate lower-cases tags
            // on write. Normalising storage only helps if the read normalises
            // the needle identically: without this, filtering by "Obiad" would
            // match nothing and report an empty catalog rather than an error.
            $conditions[] = 'JSON_CONTAINS(r.tags, :tag)';
            $params['tag'] = json_encode(mb_strtolower($tag), JSON_THROW_ON_ERROR);
        }

        $phrase = self::normalize($query->phrase);
        if (null !== $phrase) {
            // The table collation is utf8mb4_unicode_ci, so LIKE is already
            // case- and accent-insensitive; a LOWER() around the column would
            // add nothing and would rule out an index on title later.
            $conditions[] = 'r.title LIKE :phrase';
            $params['phrase'] = '%'.self::escapeLike($phrase).'%';
        }

        $sql = <<<'SQL'
            SELECT
                r.id,
                r.title,
                r.servings,
                r.prep_time_minutes,
                r.tags,
                (SELECT COUNT(*) FROM recipe_ingredients i WHERE i.recipe_id = r.id) AS ingredient_count
            FROM recipes r
            SQL;

        $where = [] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions);
        $sql .= $where.' ORDER BY r.title ASC, r.id ASC LIMIT :limit OFFSET :offset';

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM recipes r'.$where, $params);

        $rows = $this->connection->fetchAllAssociative(
            $sql,
            $params + ['limit' => $query->page->perPage, 'offset' => $query->page->offset()],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return Page::of(array_map(self::toDTO(...), $rows), $total, $query->page);
    }

    /**
     * The count comes from a correlated subquery rather than a LEFT JOIN with a
     * GROUP BY: once the steps are counted the same way (or joined for any other
     * reason) two joined child tables multiply each other's rows and both
     * counters come back inflated — the trap the Podcasts list read documents.
     *
     * @param array<string, mixed> $row
     */
    private static function toDTO(array $row): RecipeDTO
    {
        return new RecipeDTO(
            id: (string) $row['id'],
            title: (string) $row['title'],
            servings: (int) $row['servings'],
            prepTimeMinutes: null === $row['prep_time_minutes'] ? null : (int) $row['prep_time_minutes'],
            tags: TagsColumn::parse($row['tags']),
            ingredientCount: (int) $row['ingredient_count'],
        );
    }

    /**
     * A filter that is absent, blank, or only whitespace is no filter at all.
     */
    private static function normalize(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * LIKE wildcards typed by a user are literal text, not operators. Without
     * this, searching for "_" matches every recipe and searching for "100%"
     * matches every title containing "100" — both wrong answers that look like
     * correct ones. Backslash is MySQL's default LIKE escape, so no ESCAPE
     * clause is needed (adding one would only introduce a failure mode under
     * NO_BACKSLASH_ESCAPES).
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
