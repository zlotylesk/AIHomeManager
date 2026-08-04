<?php

declare(strict_types=1);

namespace App\Module\Books\Application\Query;

use App\Shared\Pagination\PageRequest;

final readonly class GetAllBooks
{
    public function __construct(
        public ?string $status = null,
        public PageRequest $page = new PageRequest(),
    ) {
    }
}
