<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\Query;

use InvalidArgumentException;

/**
 * Read the most recent notifications, newest first.
 */
final readonly class GetNotificationHistory
{
    public const int MAX_LIMIT = 100;

    public function __construct(public int $limit = 20)
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf('History limit must be between 1 and %d.', self::MAX_LIMIT));
        }
    }
}
