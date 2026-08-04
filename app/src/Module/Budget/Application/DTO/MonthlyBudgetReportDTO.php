<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\DTO;

/**
 * The month's income/expense summary and balance, plus a spend-vs-limit
 * breakdown for every category (including ones untouched that month — a
 * category never disappears from the report just because nothing was spent
 * against it, the Insights "no activity is a zero point" precedent).
 *
 * Every figure here is denominated in `currency`, the one currency the budget
 * is kept in ({@see \App\Module\Budget\Application\SystemCurrency}). Carrying it
 * is the point rather than decoration: a financial report is the last place
 * that can afford to state an amount without its unit.
 */
final readonly class MonthlyBudgetReportDTO
{
    /**
     * @param list<CategoryBudgetDTO> $categories
     */
    public function __construct(
        public string $month,
        public string $currency,
        public int $totalIncomeInCents,
        public int $totalExpensesInCents,
        public int $balanceInCents,
        public array $categories,
    ) {
    }
}
