<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\DTO;

/**
 * One notification type as the settings screen sees it: whether it is wanted,
 * which channels carry it, and the quiet window that mutes it.
 *
 * Every type is always present, including those the user never configured — the
 * read side materialises the Domain default so the UI renders a complete panel
 * instead of a partial one.
 */
final readonly class NotificationPreferenceDTO
{
    /**
     * @param list<string> $channels  the enabled channel values (e.g. ["email", "push"])
     * @param string|null  $quietFrom start of the quiet window as "HH:MM", null when unset
     * @param string|null  $quietTo   end of the quiet window as "HH:MM", null when unset
     */
    public function __construct(
        public string $type,
        public bool $enabled,
        public array $channels,
        public ?string $quietFrom,
        public ?string $quietTo,
    ) {
    }
}
