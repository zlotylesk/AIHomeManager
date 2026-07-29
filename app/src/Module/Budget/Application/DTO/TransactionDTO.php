<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\DTO;

/**
 * Read model for a ledger entry. The amount is split into its two raw parts
 * (rather than a nested Money-shaped object) — every other DTO in the
 * project is a flat field map, and there is only ever one caller (the
 * normalizer, HMAI-381) that would otherwise have to unwrap a nested shape.
 */
final readonly class TransactionDTO
{
    public function __construct(
        public string $id,
        public int $amountInCents,
        public string $currency,
        public string $date,
        public string $categoryId,
        public string $type,
        public ?string $description,
    ) {
    }
}
