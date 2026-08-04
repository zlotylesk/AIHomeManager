<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Query;

use App\Shared\Pagination\PageRequest;

final readonly class GetCategories
{
    public function __construct(public PageRequest $page = new PageRequest())
    {
    }
}
