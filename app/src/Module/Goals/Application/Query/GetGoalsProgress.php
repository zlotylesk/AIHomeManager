<?php

declare(strict_types=1);

namespace App\Module\Goals\Application\Query;

use App\Shared\Pagination\PageRequest;

/**
 * Read the current-window progress of the defined goals, one page at a time.
 * Single-user, so it carries no filter payload beyond the page window.
 */
final readonly class GetGoalsProgress
{
    public function __construct(public PageRequest $page = new PageRequest())
    {
    }
}
