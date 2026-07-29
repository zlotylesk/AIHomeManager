<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

/**
 * Splits the `amount:currency` string the `budget_money` DBAL type persists
 * (see MoneyType) back into its two parts. Query handlers read raw SQL rows
 * (HMAI-242 — no aggregate hydration for reads), so they never go through
 * that Doctrine type's conversion and must parse the packed column
 * themselves; this is the one place that parsing lives, shared by every
 * query handler that projects a Money-backed column.
 */
final readonly class MoneyColumn
{
    private function __construct()
    {
    }

    /**
     * @return array{0: int, 1: string}
     */
    public static function parse(string $packed): array
    {
        [$amountInCents, $currency] = explode(':', $packed, 2);

        return [(int) $amountInCents, $currency];
    }
}
