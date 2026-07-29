<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Command;

/**
 * Add a ledger entry. Primitives only — the handler builds the Money VO
 * (amount > 0 is validated there), resolves the TransactionType, parses the
 * date and confirms the referenced category exists.
 */
final readonly class AddTransaction
{
    public function __construct(
        public int $amountInCents,
        public string $currency,
        public string $date,
        public string $categoryId,
        public string $type,
        public ?string $description = null,
    ) {
    }
}
