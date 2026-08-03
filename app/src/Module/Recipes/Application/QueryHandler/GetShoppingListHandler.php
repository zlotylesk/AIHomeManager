<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\QueryHandler;

use App\Module\Recipes\Application\DTO\ShoppingListDTO;
use App\Module\Recipes\Application\DTO\ShoppingListItemDTO;
use App\Module\Recipes\Application\Query\GetShoppingList;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetShoppingListHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(GetShoppingList $query): ShoppingListDTO
    {
        return new ShoppingListDTO(
            from: $query->window->fromDate(),
            to: $query->window->toDate(),
            items: array_map(self::toItem(...), $this->connection->fetchAllAssociative(self::SQL, [
                'from' => $query->window->fromDate(),
                'to' => $query->window->toDate(),
            ])),
        );
    }

    /**
     * The whole list in one query: every planned meal in the window, joined to
     * its recipe's ingredients, scaled and summed by the database.
     *
     * **Scaling** is `quantity * planned_servings / recipe_servings`, which is
     * the entire reason `PlannedMeal` carries its own servings instead of
     * reading them off the recipe: cooking a 4-portion recipe for 6 needs one
     * and a half times everything. The divisor cannot be zero — `Recipe`'s
     * constructor rejects servings below one and `DoctrineRecipeRepository` is
     * the only writer of that table, so every row came through it.
     *
     * **Grouping** is by (lower-cased name, unit), matching
     * `Ingredient::matches()` — the same identity the recipe aggregate
     * deduplicates on, so "Mąka" in one recipe and "mąka" in another are one
     * line rather than two. The lower-casing is stated rather than left to the
     * column's case-insensitive collation, because here it decides the
     * *aggregation identity*: a collation change would otherwise silently split
     * lines apart. One consequence is worth naming: `utf8mb4_unicode_ci` is
     * also accent-insensitive, so "maka" and "mąka" merge even though the
     * aggregate's PHP-side comparison would treat them as distinct. That is
     * the harmless direction — merging joins two lines and never loses a
     * quantity, whereas splitting would send someone shopping twice for the
     * same thing.
     *
     * The label comes from `MIN(name)` so a group spelled several ways still
     * renders one deterministic caption rather than whichever row the engine
     * happened to read first.
     *
     * The recipe join is an INNER JOIN, unlike the calendar's LEFT JOIN. A
     * planned meal whose recipe is gone has no ingredients to contribute —
     * there is genuinely nothing to buy for it, since what it needed is
     * exactly the information that went missing. What stops that from
     * becoming a silently short list is `DeleteRecipeHandler`, which refuses
     * to delete a recipe the plan still points at; and the calendar shows such
     * an entry visibly, as a card with no name.
     */
    private const string SQL = <<<'SQL'
        SELECT
            MIN(i.name) AS name,
            i.unit,
            SUM(i.quantity * m.servings / r.servings) AS quantity
        FROM meal_plan m
        INNER JOIN recipes r ON r.id = m.recipe_id
        INNER JOIN recipe_ingredients i ON i.recipe_id = r.id
        WHERE m.date BETWEEN :from AND :to
        GROUP BY LOWER(i.name), i.unit
        ORDER BY name ASC, i.unit ASC
        SQL;

    /** @param array<string, mixed> $row */
    private static function toItem(array $row): ShoppingListItemDTO
    {
        return new ShoppingListItemDTO(
            name: (string) $row['name'],
            unit: (string) $row['unit'],
            quantity: (float) $row['quantity'],
        );
    }
}
