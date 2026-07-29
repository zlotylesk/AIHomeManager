<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\DTO;

/**
 * The month's income/expense summary and balance, plus a spend-vs-limit
 * breakdown for every category (including ones untouched that month — a
 * category never disappears from the report just because nothing was spent
 * against it, the Insights "no activity is a zero point" precedent).
 */
final readonly class MonthlyBudgetReportDTO
{
    /**
     * @param list<CategoryBudgetDTO> $categories
     */
    public function __construct(
        public string $month,
        public int $totalIncomeInCents,
        public int $totalExpensesInCents,
        public int $balanceInCents,
        public array $categories,
    ) {
    }
}
