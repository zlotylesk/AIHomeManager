<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\DTO;

/**
 * A recipe with everything needed to cook from it.
 *
 * Composes RecipeDTO rather than restating its fields, so the list and the
 * detail cannot drift apart; the normalizer delegates that half to the RecipeDTO
 * normalizer (the PodcastDetailDTO / BookDetailDTO precedent).
 *
 * Steps are a plain ordered list of strings, mirroring the aggregate: a step is
 * only ever meaningful relative to the ones before it, so it has no identity to
 * expose and nothing to carry beyond its text and its place in the sequence.
 */
final readonly class RecipeDetailDTO
{
    /**
     * @param list<RecipeIngredientDTO> $ingredients
     * @param list<string>              $steps
     */
    public function __construct(
        public RecipeDTO $recipe,
        public array $ingredients,
        public array $steps,
    ) {
    }
}
