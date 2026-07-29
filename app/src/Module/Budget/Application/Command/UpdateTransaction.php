<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Command;

/**
 * Replace every editable field of an existing transaction at once (the
 * Movie `UpdateMovieMetadata` full-replace precedent).
 */
final readonly class UpdateTransaction
{
    public function __construct(
        public string $id,
        public int $amountInCents,
        public string $currency,
        public string $date,
        public string $categoryId,
        public string $type,
        public ?string $description = null,
    ) {
    }
}
