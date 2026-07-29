<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Command;

/**
 * Set or clear a category's monthly spending limit. Both fields null clears
 * the limit; both set applies it (validated through the Money VO in the
 * handler). Exactly one being null is rejected — a half-stated amount would
 * otherwise silently coerce to a default currency or crash.
 */
final readonly class SetMonthlyLimit
{
    public function __construct(
        public string $id,
        public ?int $amountInCents,
        public ?string $currency,
    ) {
    }
}
