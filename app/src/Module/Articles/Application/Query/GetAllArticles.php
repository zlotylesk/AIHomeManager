<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\Query;

use App\Shared\Pagination\PageRequest;

final readonly class GetAllArticles
{
    public function __construct(public PageRequest $page = new PageRequest())
    {
    }
}
