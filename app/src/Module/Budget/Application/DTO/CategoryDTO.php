<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\DTO;

/**
 * Read model for a category. The monthly limit is split into its two raw
 * parts, both null when unlimited (the TransactionDTO amount precedent).
 */
final readonly class CategoryDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public ?int $monthlyLimitAmountInCents,
        public ?string $monthlyLimitCurrency,
    ) {
    }
}
