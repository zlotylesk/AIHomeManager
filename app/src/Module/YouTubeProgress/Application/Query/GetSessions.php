<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\Query;

use App\Shared\Pagination\PageRequest;

/**
 * Reads one page of watch sessions (newest first) with their ordered videos.
 */
final readonly class GetSessions
{
    public function __construct(public PageRequest $page = new PageRequest())
    {
    }
}
