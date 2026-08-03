<?php

declare(strict_types=1);

namespace App\Controller\Recipes;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Stateless payload parsing + shape validation for the Recipes REST surface,
 * kept out of the thin controllers (the SeriesRequestParser precedent,
 * HMAI-239). On invalid input it throws UnprocessableEntityHttpException,
 * which ApiExceptionListener turns into the {"error": …} 422 the API contract
 * expects.
 *
 * The split with the Application layer is deliberate and narrow: this parser
 * only answers "is this the shape of a recipe payload" — a JSON object, the
 * right PHP types, the right keys present. Everything that needs to know what
 * a recipe *is* (a non-empty title, at least one ingredient, no duplicate
 * (name, unit) pair, a unit the module knows, servings ≥ 1, a real calendar
 * date) stays in the aggregate, `IngredientInput` and `MealPlacementInput`,
 * where it is shared with every other caller of those commands.
 */
final class RecipesRequestParser
{
    /** @return array<string, mixed> */
    public function decode(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $data */
    public function requireTitle(array $data): string
    {
        $value = $data['title'] ?? null;

        if (!\is_string($value)) {
            throw new UnprocessableEntityHttpException('Field "title" is required and must be a string.');
        }

        return $value;
    }

    /**
     * The ingredient list as the commands carry it: primitives only, with the
     * measurement unit still a raw string for `IngredientInput` to resolve.
     *
     * A quantity arriving as an integer (`2` rather than `2.0`) is accepted and
     * widened — JSON has one number type, so a client that sends a whole
     * quantity is not making a mistake. A numeric *string* is not accepted,
     * because "2,5" and "2.5" are the same intent typed in two locales and
     * guessing which one a client meant is how a recipe silently gains an order
     * of magnitude.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{name: string, quantity: float, unit: string}>
     */
    public function requireIngredients(array $data): array
    {
        $raw = $data['ingredients'] ?? null;

        if (!\is_array($raw)) {
            throw new UnprocessableEntityHttpException('Field "ingredients" is required and must be an array.');
        }

        $ingredients = [];

        foreach ($raw as $item) {
            if (!\is_array($item)) {
                throw new UnprocessableEntityHttpException('Each ingredient must be an object with "name", "quantity" and "unit".');
            }

            $name = $item['name'] ?? null;
            $quantity = $item['quantity'] ?? null;
            $unit = $item['unit'] ?? null;

            if (!\is_string($name) || !\is_string($unit) || !\is_int($quantity) && !\is_float($quantity)) {
                throw new UnprocessableEntityHttpException('Each ingredient needs a string "name", a numeric "quantity" and a string "unit".');
            }

            $ingredients[] = ['name' => $name, 'quantity' => (float) $quantity, 'unit' => $unit];
        }

        return $ingredients;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    public function stringList(array $data, string $field): array
    {
        $raw = $data[$field] ?? [];

        if (!\is_array($raw)) {
            throw new UnprocessableEntityHttpException(sprintf('Field "%s" must be an array of strings.', $field));
        }

        $values = [];

        foreach ($raw as $value) {
            if (!\is_string($value)) {
                throw new UnprocessableEntityHttpException(sprintf('Field "%s" must be an array of strings.', $field));
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * Servings on create, where omitting them means "one portion" — a
     * reasonable default for a recipe being typed in for the first time.
     *
     * @param array<string, mixed> $data
     */
    public function servings(array $data): int
    {
        $value = $data['servings'] ?? 1;

        if (!\is_int($value)) {
            throw new UnprocessableEntityHttpException('Field "servings" must be an integer.');
        }

        return $value;
    }

    /**
     * Servings on a full replace, where they are required rather than
     * defaulted — the one field of the update that must not fall back.
     *
     * The rest of an omitted replace merely clears something the user can see
     * is gone (steps, tags, the prep time). Servings are different: the
     * shopping list scales every quantity by `planned / recipe` servings, so
     * quietly resetting a four-portion recipe to one would multiply its whole
     * ingredient list by four on the next shopping run — a wrong answer that
     * looks entirely plausible, and one nobody would trace back to an omitted
     * JSON key.
     *
     * @param array<string, mixed> $data
     */
    public function requireServings(array $data): int
    {
        $value = $data['servings'] ?? null;

        if (!\is_int($value)) {
            throw new UnprocessableEntityHttpException('Field "servings" is required and must be an integer.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public function optionalPrepTime(array $data): ?int
    {
        $value = $data['prepTimeMinutes'] ?? null;

        if (null !== $value && !\is_int($value)) {
            throw new UnprocessableEntityHttpException('Field "prepTimeMinutes" must be an integer or null.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public function requireString(array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        if (!\is_string($value) || '' === trim($value)) {
            throw new UnprocessableEntityHttpException(sprintf('Field "%s" is required.', $field));
        }

        return $value;
    }

    public function optionalQuery(Request $request, string $name): ?string
    {
        $value = $request->query->get($name);

        return \is_string($value) && '' !== trim($value) ? $value : null;
    }

    /**
     * Both ends of a plan window, required.
     *
     * They are deliberately not defaulted to "this week": a calendar client
     * always knows exactly which range it is showing, so the only thing a
     * default would buy is the risk of answering confidently about a different
     * week than the caller meant — and working out which day starts a week is
     * domain knowledge that has no business in an HTTP layer. A missing
     * parameter is a 422 naming what is missing.
     *
     * The date is only shape-checked here; `PlanWindow` owns the ordering and
     * the size cap, so both plan reads enforce one set of rules.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public function requireWindow(Request $request): array
    {
        return [$this->requireDateQuery($request, 'from'), $this->requireDateQuery($request, 'to')];
    }

    /**
     * The export format, defaulting to CSV.
     *
     * An unknown value is refused rather than falling back to the default: the
     * caller asked for a specific file and would otherwise download a CSV while
     * believing it had a PDF.
     */
    public function exportFormat(Request $request): string
    {
        $value = $request->query->get('format', 'csv');

        if (!\in_array($value, ['csv', 'pdf'], true)) {
            throw new UnprocessableEntityHttpException('Query parameter "format" must be "csv" or "pdf".');
        }

        return $value;
    }

    private function requireDateQuery(Request $request, string $name): DateTimeImmutable
    {
        $value = $request->query->get($name);

        if (!\is_string($value) || '' === trim($value)) {
            throw new UnprocessableEntityHttpException(sprintf('Query parameter "%s" is required (YYYY-MM-DD).', $name));
        }

        // Strict, via a round-trip comparison: createFromFormat resolves an
        // impossible day rather than rejecting it, so "2026-02-31" would
        // silently become March 3rd and the caller would be shown a week they
        // never asked for (the Budget 1.32.0 lesson).
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new UnprocessableEntityHttpException(sprintf('Query parameter "%s" must be a valid date (YYYY-MM-DD).', $name));
        }

        return $date;
    }
}
