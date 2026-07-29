<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\DTO;

/**
 * One category's row in the monthly report: what was spent against it this
 * month, and — when a limit is set — how that compares. `percentUsed`/
 * `overLimit` are null/false for an unlimited category (nothing to exceed).
 */
final readonly class CategoryBudgetDTO
{
    public function __construct(
        public string $categoryId,
        public string $categoryName,
        public string $type,
        public int $spentInCents,
        public ?int $monthlyLimitInCents,
        public ?string $monthlyLimitCurrency,
        public ?float $percentUsed,
        public bool $overLimit,
    ) {
    }
}
