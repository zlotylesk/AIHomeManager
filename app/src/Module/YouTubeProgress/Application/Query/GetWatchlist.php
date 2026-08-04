<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\Query;

use App\Shared\Pagination\PageRequest;

/**
 * Reads the watchlist one page at a time. Single-user panel, so it carries no
 * filter beyond the page window — but the watchlist grows without bound, so the
 * window is not optional.
 */
final readonly class GetWatchlist
{
    public function __construct(public PageRequest $page = new PageRequest())
    {
    }
}
