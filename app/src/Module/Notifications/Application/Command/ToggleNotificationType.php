<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\Command;

/**
 * Opt in to or out of a whole notification type, independently of which
 * channels carry it. The raw {@see $type} is validated in the handler.
 */
final readonly class ToggleNotificationType
{
    public function __construct(
        public string $type,
        public bool $enabled,
    ) {
    }
}
