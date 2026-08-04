<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Query;

use App\Shared\Pagination\PageRequest;

/**
 * List the shows with their seasons and episodes, one {@see PageRequest} window
 * of shows at a time. The window counts shows, never joined rows — see the
 * handler.
 */
final readonly class GetAllSeries
{
    public function __construct(public PageRequest $page = new PageRequest())
    {
    }
}
