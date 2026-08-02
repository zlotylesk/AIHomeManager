<?php

declare(strict_types=1);

namespace App\Module\Recipes\Domain\Entity;

use App\Module\Recipes\Domain\Enum\MeasurementUnit;
use App\Module\Recipes\Domain\ValueObject\Ingredient;
use InvalidArgumentException;

/**
 * A cooking recipe: what it makes, what goes into it and how it is made.
 *
 * The aggregate refuses to exist without at least one ingredient — a recipe
 * with an empty ingredient list contributes nothing to the shopping list
 * (HMAI-391) and cannot be cooked from, so there is no state in which it is
 * useful. Steps are allowed to be empty (some recipes really are "mix it all
 * together"), which is why only the ingredient rule is an invariant.
 */
final class Recipe
{
    public const int MAX_TITLE_LENGTH = 255;

    public const int MAX_STEP_LENGTH = 1000;

    public const int MAX_TAG_LENGTH = 50;

    private string $title;

    /** @var list<Ingredient> */
    private array $ingredients;

    /** @var list<string> */
    private array $steps;

    private int $servings;

    private ?int $prepTimeMinutes;

    /** @var list<string> */
    private array $tags;

    /**
     * @param list<Ingredient> $ingredients
     * @param list<string>     $steps
     * @param list<string>     $tags
     */
    public function __construct(
        private readonly string $id,
        string $title,
        array $ingredients,
        array $steps = [],
        int $servings = 1,
        ?int $prepTimeMinutes = null,
        array $tags = [],
    ) {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('Recipe id cannot be empty.');
        }

        $this->applyState($title, $ingredients, $steps, $servings, $prepTimeMinutes, $tags);
    }

    /**
     * Full replace of everything the recipe is, except its id — the shape the
     * edit form actually submits, so a user who deletes an ingredient and adds
     * two others describes the result rather than the individual moves.
     *
     * Deliberately all-or-nothing: the whole new state is validated into
     * locals before a single field is assigned, so a rejected update leaves the
     * aggregate exactly as it was. Mutating as it goes would leave a recipe
     * half-rewritten in memory after a 422 — with the caller still holding it.
     *
     * @param list<Ingredient> $ingredients
     * @param list<string>     $steps
     * @param list<string>     $tags
     */
    public function update(
        string $title,
        array $ingredients,
        array $steps,
        int $servings,
        ?int $prepTimeMinutes,
        array $tags,
    ): void {
        $this->applyState($title, $ingredients, $steps, $servings, $prepTimeMinutes, $tags);
    }

    /**
     * @param list<Ingredient> $ingredients
     * @param list<string>     $steps
     * @param list<string>     $tags
     */
    private function applyState(
        string $title,
        array $ingredients,
        array $steps,
        int $servings,
        ?int $prepTimeMinutes,
        array $tags,
    ): void {
        if ([] === $ingredients) {
            throw new InvalidArgumentException('A recipe must have at least one ingredient.');
        }

        $newTitle = self::guardTitle($title);
        $newServings = self::guardServings($servings);
        $newPrepTime = self::guardPrepTime($prepTimeMinutes);

        $newIngredients = [];
        foreach ($ingredients as $ingredient) {
            if (null !== self::indexOfIngredient($newIngredients, $ingredient->name(), $ingredient->unit())) {
                throw new InvalidArgumentException(sprintf('Ingredient "%s" is already listed in this unit.', $ingredient->name()));
            }

            $newIngredients[] = $ingredient;
        }

        $newSteps = [];
        foreach ($steps as $step) {
            $newSteps[] = self::guardStep($step);
        }

        $newTags = self::normalizeTags($tags);

        $this->title = $newTitle;
        $this->servings = $newServings;
        $this->prepTimeMinutes = $newPrepTime;
        $this->ingredients = $newIngredients;
        $this->steps = $newSteps;
        $this->tags = $newTags;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    /** @return list<Ingredient> */
    public function ingredients(): array
    {
        return $this->ingredients;
    }

    /** @return list<string> */
    public function steps(): array
    {
        return $this->steps;
    }

    public function servings(): int
    {
        return $this->servings;
    }

    public function prepTimeMinutes(): ?int
    {
        return $this->prepTimeMinutes;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->tags;
    }

    public function rename(string $title): void
    {
        $this->title = self::guardTitle($title);
    }

    public function setServings(int $servings): void
    {
        $this->servings = self::guardServings($servings);
    }

    /**
     * Ingredients are identified by (name, unit) — the same pair the shopping
     * list groups on. Adding a second line for a pair already present is
     * rejected rather than appended: the read side would have to collapse the
     * two anyway, and "200 g flour" plus "50 g flour" is one 250 g entry on
     * any shopping list a human would write.
     */
    public function addIngredient(Ingredient $ingredient): void
    {
        if (null !== $this->findIngredientIndex($ingredient->name(), $ingredient->unit())) {
            throw new InvalidArgumentException(sprintf('Ingredient "%s" is already listed in this unit.', $ingredient->name()));
        }

        $this->ingredients[] = $ingredient;
    }

    public function removeIngredient(string $name, MeasurementUnit $unit): void
    {
        $index = $this->findIngredientIndex($name, $unit);

        if (null === $index) {
            throw new InvalidArgumentException(sprintf('Ingredient "%s" is not listed in this recipe.', $name));
        }

        if (1 === count($this->ingredients)) {
            throw new InvalidArgumentException('A recipe must keep at least one ingredient.');
        }

        $remaining = $this->ingredients;
        unset($remaining[$index]);

        $this->ingredients = array_values($remaining);
    }

    /**
     * Steps carry their order in the array itself — a step is only ever
     * meaningful relative to the ones before it, so there is no id to reorder
     * by and no reason to expose one.
     */
    public function addStep(string $text): void
    {
        $this->steps[] = self::guardStep($text);
    }

    /**
     * Full replace, deliberately — tags are edited as a set in the UI, and a
     * per-tag add/remove pair would make "these are exactly my tags" take two
     * round trips. Tags are lower-cased so the tag filter (HMAI-388) does not
     * have to be case-insensitive at query time, and de-duplicated so one tag
     * cannot be listed twice.
     *
     * @param list<string> $tags
     */
    public function retag(array $tags): void
    {
        $this->tags = self::normalizeTags($tags);
    }

    private function findIngredientIndex(string $name, MeasurementUnit $unit): ?int
    {
        return self::indexOfIngredient($this->ingredients, $name, $unit);
    }

    /** @param list<Ingredient> $ingredients */
    private static function indexOfIngredient(array $ingredients, string $name, MeasurementUnit $unit): ?int
    {
        foreach ($ingredients as $index => $ingredient) {
            if ($ingredient->matches($name, $unit)) {
                return $index;
            }
        }

        return null;
    }

    private static function guardStep(string $text): string
    {
        $normalized = trim($text);

        if ('' === $normalized) {
            throw new InvalidArgumentException('Recipe step cannot be empty.');
        }

        if (mb_strlen($normalized) > self::MAX_STEP_LENGTH) {
            throw new InvalidArgumentException(sprintf('Recipe step cannot exceed %d characters.', self::MAX_STEP_LENGTH));
        }

        return $normalized;
    }

    /**
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private static function normalizeTags(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            $candidate = mb_strtolower(trim($tag));

            if ('' === $candidate) {
                continue;
            }

            if (mb_strlen($candidate) > self::MAX_TAG_LENGTH) {
                throw new InvalidArgumentException(sprintf('Recipe tag cannot exceed %d characters.', self::MAX_TAG_LENGTH));
            }

            // Deliberately NOT de-duplicated via array keys: PHP casts a
            // numeric string key to int, so a tag like "2024" would come back
            // as an integer and serialize as 2024 rather than "2024" — a
            // mismatch the tag filter (HMAI-388) would silently never match.
            if (!in_array($candidate, $normalized, true)) {
                $normalized[] = $candidate;
            }
        }

        return $normalized;
    }

    private static function guardTitle(string $title): string
    {
        $normalized = trim($title);

        if ('' === $normalized) {
            throw new InvalidArgumentException('Recipe title cannot be empty.');
        }

        if (mb_strlen($normalized) > self::MAX_TITLE_LENGTH) {
            throw new InvalidArgumentException(sprintf('Recipe title cannot exceed %d characters.', self::MAX_TITLE_LENGTH));
        }

        return $normalized;
    }

    private static function guardServings(int $servings): int
    {
        if ($servings < 1) {
            throw new InvalidArgumentException('Recipe servings must be at least 1.');
        }

        return $servings;
    }

    private static function guardPrepTime(?int $prepTimeMinutes): ?int
    {
        if (null !== $prepTimeMinutes && $prepTimeMinutes <= 0) {
            throw new InvalidArgumentException('Recipe preparation time must be greater than zero.');
        }

        return $prepTimeMinutes;
    }
}
