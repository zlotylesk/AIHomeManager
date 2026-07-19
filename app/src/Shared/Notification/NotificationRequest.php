<?php

declare(strict_types=1);

namespace App\Shared\Notification;

use InvalidArgumentException;

/**
 * What a source module wants announced, expressed in primitives only.
 *
 * This is the shared-kernel currency between the modules that know *when*
 * something worth announcing happened and the Notifications module that knows
 * *how* to announce it. Keeping it primitive is what lets both sides depend on
 * it without depending on each other.
 *
 * {@see $subject} and {@see $window} identify the occurrence for deduplication:
 * the same subject inside the same window is the same occurrence, however many
 * times it is reported.
 */
final readonly class NotificationRequest
{
    /**
     * @param string               $type    a NotificationType value (e.g. "task_due")
     * @param string               $subject stable reference to what this is about (e.g. "task-42")
     * @param string               $window  the window that makes a repeat a new occurrence (e.g. a date)
     * @param array<string, mixed> $payload template variables for the channel adapters
     */
    public function __construct(
        public string $type,
        public string $subject,
        public string $window,
        public array $payload = [],
    ) {
        foreach (['type' => $type, 'subject' => $subject, 'window' => $window] as $label => $part) {
            if ('' === trim($part)) {
                throw new InvalidArgumentException(sprintf('Notification request %s cannot be empty.', $label));
            }
        }
    }
}
