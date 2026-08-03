<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recipes\Application\Service;

use App\Module\Recipes\Application\DTO\ShoppingListDTO;
use App\Module\Recipes\Application\DTO\ShoppingListItemDTO;
use App\Module\Recipes\Application\Service\ShoppingListExporter;
use PHPUnit\Framework\TestCase;

/**
 * Pins the export's rounding contract.
 *
 * `ShoppingListItemDTO` ships raw sums and hands rounding to presentation, and
 * an export is presentation — so the same rules exist twice, here and in
 * `assets/recipes/format.js`. Nine constants cannot be shared between PHP and a
 * browser bundle without a build step nobody wants for this, so what keeps the
 * two from drifting is that both are pinned by tests naming the same numbers.
 * The `rounds a decimal half the way the export does` case in
 * `assets/tests/recipes_format.test.js` is this file's counterpart; changing
 * one side without the other turns one of the two suites red.
 */
final class ShoppingListExporterTest extends TestCase
{
    private ShoppingListExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new ShoppingListExporter();
    }

    /**
     * A CSV is processed — sorted, filtered, pivoted — so it carries the
     * canonical identifier, which is a stable key; "szt." is not.
     */
    public function testCsvRowsCarryTheCanonicalUnit(): void
    {
        $rows = $this->exporter->rows($this->listOf(['Mąka', 'g', 500.0], ['Jajko', 'piece', 3.0]));

        self::assertSame([['Mąka', 'g', '500'], ['Jajko', 'piece', '3']], $rows);
    }

    /** The PDF is read rather than processed, so it spells the unit out. */
    public function testThePdfRowsSpellTheUnitOut(): void
    {
        $rows = $this->exporter->printableRows($this->listOf(['Jajko', 'piece', 3.0], ['Cukier', 'tablespoon', 2.0]));

        self::assertSame(
            [
                ['name' => 'Jajko', 'unit' => 'szt.', 'quantity' => '3'],
                ['name' => 'Cukier', 'unit' => 'łyżka', 'quantity' => '2'],
            ],
            $rows,
        );
    }

    public function testPrecisionIsPerUnit(): void
    {
        // Nobody weighs 333.33 g, but 0.667 l rounded to a whole litre is a
        // different amount of milk.
        self::assertSame('333', $this->quantity(333.3333333, 'g'));
        self::assertSame('167', $this->quantity(166.6666, 'ml'));
        self::assertSame('0.667', $this->quantity(0.6666666, 'l'));
        self::assertSame('0.333', $this->quantity(0.3333333, 'kg'));
        // Half and quarter measures are real quantities a cook uses.
        self::assertSame('0.5', $this->quantity(0.5, 'tablespoon'));
        self::assertSame('1.25', $this->quantity(1.25, 'cup'));
    }

    public function testTrailingZerosAreStripped(): void
    {
        self::assertSame('1.5', $this->quantity(1.5, 'kg'));
        self::assertSame('2', $this->quantity(2.0, 'cup'));
    }

    /**
     * Scaling 2 eggs by two thirds gives 1.33, and rounding that down leaves
     * the cook an egg short halfway through the recipe — the one direction of
     * error a shopping list must not make.
     */
    public function testIndivisibleUnitsRoundUp(): void
    {
        self::assertSame('2', $this->quantity(1.3333, 'piece'));
        self::assertSame('1', $this->quantity(0.1, 'piece'));
        self::assertSame('3', $this->quantity(2.0001, 'pinch'));
    }

    public function testAnExactWholeOfAnIndivisibleUnitIsNotInflated(): void
    {
        self::assertSame('2', $this->quantity(2.0, 'piece'));
        // 6 * (1/3) is 2.0000000000000004 in binary, not 2 — rounding that up
        // to 3 would add an egg nobody needs.
        self::assertSame('2', $this->quantity(6 * (1 / 3), 'piece'));
    }

    /**
     * The counterpart of the Vitest case naming the same six numbers.
     *
     * A decimal half is the one input where the two languages disagree by
     * default: JS `toFixed` rounds the exact binary double (1.005 is stored as
     * 1.00499999999999989, so it answers "1.00") while PHP's `number_format`
     * rounds the decimal the value prints as. A printout that differed from the
     * screen at the last digit would undermine the one thing the export is for.
     */
    public function testADecimalHalfRoundsTheWayTheScreenDoes(): void
    {
        self::assertSame('1.01', $this->quantity(1.005, 'cup'));
        self::assertSame('2.68', $this->quantity(2.675, 'tablespoon'));
        self::assertSame('1.12', $this->quantity(1.115, 'teaspoon'));
        self::assertSame('1.001', $this->quantity(1.0005, 'kg'));
        self::assertSame('0.001', $this->quantity(0.0005, 'l'));
        self::assertSame('1', $this->quantity(0.5, 'g'));
    }

    /** The float artifact the DTO warns about must never reach the file. */
    public function testAFloatSumIsFormattedRatherThanPrinted(): void
    {
        self::assertSame('0.3', $this->quantity(0.1 + 0.2, 'l'));
    }

    /**
     * A unit this class has not heard of is still no reason to print seventeen
     * significant digits on a shopping list.
     */
    public function testAnUnknownUnitFallsBackToTwoDecimalsAndKeepsItsIdentifier(): void
    {
        self::assertSame('1.23', $this->quantity(1.2345, 'garnek'));

        $rows = $this->exporter->printableRows($this->listOf(['Woda', 'garnek', 1.2345]));
        self::assertSame('garnek', $rows[0]['unit']);
    }

    public function testAnEmptyListProducesNoRows(): void
    {
        $list = new ShoppingListDTO('2026-08-03', '2026-08-09', []);

        self::assertSame([], $this->exporter->rows($list));
        self::assertSame([], $this->exporter->printableRows($list));
    }

    private function quantity(float $quantity, string $unit): string
    {
        $rows = $this->exporter->rows($this->listOf(['x', $unit, $quantity]));

        self::assertIsString($rows[0][2]);

        return $rows[0][2];
    }

    /**
     * @param array{0: string, 1: string, 2: float} ...$items
     */
    private function listOf(array ...$items): ShoppingListDTO
    {
        $mapped = [];
        foreach ($items as [$name, $unit, $quantity]) {
            $mapped[] = new ShoppingListItemDTO($name, $unit, $quantity);
        }

        return new ShoppingListDTO('2026-08-03', '2026-08-09', $mapped);
    }
}
