<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\Query;

use App\Shared\Pagination\PageRequest;

/**
 * Read the notifications, newest first, one page at a time.
 *
 * The bespoke `limit` this query used to carry was folded into the shared
 * {@see PageRequest}: it already meant the same thing and enforced the same
 * ceiling, and one pagination vocabulary across the API is the point.
 */
final readonly class GetNotificationHistory
{
    public function __construct(public PageRequest $page = new PageRequest())
    {
    }
}
