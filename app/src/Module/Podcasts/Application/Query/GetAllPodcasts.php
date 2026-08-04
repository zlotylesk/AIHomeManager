<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Application\Query;

use App\Shared\Pagination\PageRequest;

final readonly class GetAllPodcasts
{
    public function __construct(public PageRequest $page = new PageRequest())
    {
    }
}
