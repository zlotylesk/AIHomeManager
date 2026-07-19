<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\DTO;

/**
 * A delivered (or attempted) notification, as the history list shows it.
 */
final readonly class NotificationDTO
{
    /**
     * @param array<string, mixed> $payload
     * @param string|null          $sentAt        ISO-8601, null while pending or failed
     * @param string|null          $failureReason null unless the delivery failed
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $channel,
        public string $status,
        public array $payload,
        public string $createdAt,
        public ?string $sentAt,
        public ?string $failureReason,
    ) {
    }
}
