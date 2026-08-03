<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\DTO;

/**
 * Read model for a recipe as the catalog list renders it.
 *
 * The list deliberately carries an ingredient *count* rather than the
 * ingredients themselves: a card shows "12 składników · 30 min · 4 porcje", and
 * shipping every ingredient of every recipe to render one number would move the
 * whole collection over the wire for the sake of a `length`. The count is
 * computed in the read query rather than at serialize time (the HMAI-242 rule).
 */
final readonly class RecipeDTO
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        public string $title,
        public int $servings,
        public ?int $prepTimeMinutes,
        public array $tags,
        public int $ingredientCount,
    ) {
    }
}
