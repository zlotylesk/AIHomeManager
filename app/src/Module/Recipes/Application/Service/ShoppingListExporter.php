<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Service;

use App\Module\Recipes\Application\DTO\ShoppingListDTO;

/**
 * Turns an already-computed shopping list into export rows (the Tasks/Articles
 * CSV export pattern, HMAI-36).
 *
 * The list is deliberately NOT re-queried here. It carries real derivation —
 * the per-meal `planned / recipe` servings scaling, the case-insensitive
 * (name, unit) grouping, the window rules — so a second copy of that SQL would
 * eventually let the exported list disagree with the one `/meal-plan/shopping-list`
 * serves and the one on screen. For a shopping list that divergence is the
 * whole failure: the printout is taken to the shop precisely because it is
 * supposed to be what the screen said. The controller dispatches
 * `GetShoppingList` on query.bus and hands the DTO here, which only maps it to
 * rows (the Budget report half, HMAI-384).
 *
 * Nothing is streamed, unlike the Budget ledger: that grows without bound over
 * years, whereas this list is bounded by the distinct ingredients of at most a
 * 92-day window and the DTO is already materialised in memory anyway.
 *
 * ## Why the rounding rules live here too
 *
 * `ShoppingListItemDTO` ships raw sums on purpose and hands rounding to
 * presentation, because the precision a unit deserves differs per unit. An
 * export IS presentation — it is read by a person at the shop or pasted into a
 * spreadsheet — so it has to apply the same contract the UI applies, or the
 * printed list reads "0.30000000000000004 kg mąka", which is exactly the float
 * artifact the DTO warns about.
 *
 * That means the contract now exists twice: here and in
 * `assets/recipes/format.js` (`shoppingQuantityLabel`, which is the shopping
 * list's formatter — `quantityLabel` beside it renders a recipe's own
 * ingredients and deliberately does NOT round up, since half an onion is an
 * ordinary recipe line while half an egg is not something you can buy).
 * Stated rather than hidden, because there is no
 * honest way to share nine constants between PHP and a browser bundle without
 * a build step nobody wants for this. What keeps the two from drifting quietly
 * is that both sides are pinned by tests naming the same numbers —
 * `ShoppingListExporterTest::testADecimalHalfRoundsTheWayTheScreenDoes` and the
 * `rounds a decimal half the way the export does` case in
 * `assets/tests/recipes_format.test.js` — so a change made on one side and not
 * the other turns one of the two suites red.
 *
 * The decimal half is where the two languages disagree by default, and it is
 * why the JS side does not simply call `toFixed()`: that rounds the exact
 * binary double (1.005 is stored as 1.00499999999999989, so it answers "1.00")
 * while `number_format()` below rounds the decimal the value prints as. A
 * printout that differed from the screen at the last digit would undermine the
 * one thing an exported shopping list is for.
 *
 * Two of the rules carry meaning rather than formatting:
 *  - precision is per unit (grams and millilitres whole — nobody weighs
 *    333.33 g; kilograms and litres to three, since 0.667 l rounded to a whole
 *    litre is a different amount of milk);
 *  - indivisible units round **UP**, never to nearest: scaling 2 eggs by two
 *    thirds gives 1.33, and rounding that down leaves the cook an egg short
 *    halfway through the recipe — the one direction of error a shopping list
 *    must not make.
 */
final readonly class ShoppingListExporter
{
    /** @var list<string> */
    public const array HEADERS = ['name', 'unit', 'quantity'];

    /**
     * Decimal places per unit. Mirrors UNIT_DECIMALS in assets/recipes/format.js.
     *
     * @var array<string, int>
     */
    private const array UNIT_DECIMALS = [
        'g' => 0,
        'ml' => 0,
        'kg' => 3,
        'l' => 3,
        'tablespoon' => 2,
        'teaspoon' => 2,
        'cup' => 2,
        'pinch' => 0,
    ];

    /**
     * Units you cannot buy a fraction of. Mirrors INDIVISIBLE_UNITS in
     * assets/recipes/format.js.
     *
     * @var list<string>
     */
    private const array INDIVISIBLE_UNITS = ['piece', 'pinch'];

    /**
     * Polish unit captions for the PDF.
     *
     * They live here rather than on the `MeasurementUnit` enum on purpose: the
     * enum's backing values are canonical identifiers that reach the database
     * and the shopping list's grouping key, and the project's standing
     * convention keeps their labels in the presentation layer.
     *
     * @var array<string, string>
     */
    private const array UNIT_LABELS = [
        'g' => 'g',
        'kg' => 'kg',
        'ml' => 'ml',
        'l' => 'l',
        'piece' => 'szt.',
        'tablespoon' => 'łyżka',
        'teaspoon' => 'łyżeczka',
        'cup' => 'szklanka',
        'pinch' => 'szczypta',
    ];

    /**
     * CSV rows, carrying the **canonical** unit identifier.
     *
     * A CSV is processed rather than read — sorted, filtered, grouped — so a
     * stable machine key beats a caption there ("szt." is a poor sort key, and
     * every other CSV in the project exports enum backing values the same way).
     * The PDF, which is read rather than processed, gets the caption instead.
     *
     * @return list<list<scalar|null>>
     */
    public function rows(ShoppingListDTO $list): array
    {
        $rows = [];

        foreach ($list->items as $item) {
            $rows[] = [$item->name, $item->unit, self::formatQuantity($item->quantity, $item->unit)];
        }

        return $rows;
    }

    /**
     * The same lines with the unit spelled the way the UI spells it, for the
     * document a person actually reads.
     *
     * @return list<array{name: string, unit: string, quantity: string}>
     */
    public function printableRows(ShoppingListDTO $list): array
    {
        $rows = [];

        foreach ($list->items as $item) {
            $rows[] = [
                'name' => $item->name,
                'unit' => self::UNIT_LABELS[$item->unit] ?? $item->unit,
                'quantity' => self::formatQuantity($item->quantity, $item->unit),
            ];
        }

        return $rows;
    }

    /**
     * The rounding contract. An unknown unit falls back to two decimals rather
     * than to the raw float — a unit this class has not heard of is still not a
     * reason to print seventeen significant digits on a shopping list.
     */
    private static function formatQuantity(float $quantity, string $unit): string
    {
        if (\in_array($unit, self::INDIVISIBLE_UNITS, true)) {
            // The epsilon keeps a quantity that is a whole number in intent but
            // 2.0000000000000004 in floating point from being rounded up to 3.
            return (string) (int) ceil($quantity - 1e-9);
        }

        $decimals = self::UNIT_DECIMALS[$unit] ?? 2;
        $fixed = number_format($quantity, $decimals, '.', '');

        if (0 === $decimals) {
            return $fixed;
        }

        // Strip trailing zeros, so 1.500 kg reads "1.5" while 1.234 keeps its
        // precision. number_format always leaves a digit before the separator,
        // so this cannot trim the value away entirely.
        return rtrim(rtrim($fixed, '0'), '.');
    }
}
