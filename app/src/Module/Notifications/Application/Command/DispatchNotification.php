<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\Command;

/**
 * Announce one real-world occurrence. The single entry point both triggers use
 * — the reactive Messenger handlers and the scheduler sweep — so the send/skip
 * rules live in one place rather than in each trigger.
 *
 * {@see $subject} and {@see $window} identify the occurrence for deduplication:
 * the same task on the same day is the same occurrence however it was noticed.
 */
final readonly class DispatchNotification
{
    /**
     * @param array<string, mixed> $payload template variables for the channel adapters
     */
    public function __construct(
        public string $type,
        public string $subject,
        public string $window,
        public array $payload = [],
    ) {
    }
}
