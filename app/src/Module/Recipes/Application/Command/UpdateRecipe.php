<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Command;

/**
 * Replace everything a recipe is, except its id.
 *
 * Deliberately a full replace rather than a set of partial edits: the edit
 * form submits the whole recipe, and an ingredient list is a set the user
 * rewrites rather than a log of add/remove operations. Every field is
 * therefore required — an absent one would be indistinguishable from a
 * deliberate clearing, which is exactly the ambiguity the Series
 * rename-vs-metadata split exists to avoid on a PATCH.
 */
final readonly class UpdateRecipe
{
    /**
     * @param list<array{name: string, quantity: float, unit: string}> $ingredients
     * @param list<string>                                             $steps
     * @param list<string>                                             $tags
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $ingredients,
        public array $steps,
        public int $servings,
        public ?int $prepTimeMinutes,
        public array $tags,
    ) {
    }
}
