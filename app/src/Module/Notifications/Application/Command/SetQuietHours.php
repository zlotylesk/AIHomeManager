<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\Command;

/**
 * Set the daily window in which a notification type must not be delivered, or
 * clear it by passing null for both times. The `HH:MM` strings are validated by
 * the QuietHours value object in the handler.
 */
final readonly class SetQuietHours
{
    public function __construct(
        public string $type,
        public ?string $start = null,
        public ?string $end = null,
    ) {
    }
}
