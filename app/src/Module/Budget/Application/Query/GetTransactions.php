<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Query;

use App\Shared\Pagination\PageRequest;

/**
 * List transactions, optionally filtered by month (YYYY-MM), category, and
 * type. Every filter is independent and optional; a null field means "do not
 * filter on this".
 */
final readonly class GetTransactions
{
    public function __construct(
        public ?string $month = null,
        public ?string $categoryId = null,
        public ?string $type = null,
        public PageRequest $page = new PageRequest(),
    ) {
    }
}
