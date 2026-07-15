<?php

declare(strict_types=1);

namespace App\Module\Notifications\Domain\Entity;

use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationStatus;
use App\Module\Notifications\Domain\Enum\NotificationType;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

/**
 * A single notification bound for one channel: what it is about ($type), how it
 * goes out ($channel), the template variables to render it from ($payload) and
 * where its delivery stands ($status).
 *
 * The $dedupKey identifies the real-world occurrence the notification stands for
 * (e.g. "task 42 due on 2026-07-16"), so the reactive and scheduler triggers can
 * recognize the same occurrence and not notify about it twice.
 *
 * The aggregate owns only its own state transitions; the policy deciding whether
 * and where to send lives in the dispatch engine.
 */
final class Notification
{
    /**
     * @param array<string, mixed> $payload template variables the channel adapter renders from
     */
    public function __construct(
        private readonly string $id,
        private readonly NotificationType $type,
        private readonly Channel $channel,
        private readonly array $payload,
        private readonly string $dedupKey,
        private readonly DateTimeImmutable $createdAt,
        private NotificationStatus $status = NotificationStatus::PENDING,
        private ?DateTimeImmutable $sentAt = null,
        private ?string $failureReason = null,
    ) {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('Notification id cannot be empty.');
        }

        if ('' === trim($dedupKey)) {
            throw new InvalidArgumentException('Notification dedup key cannot be empty.');
        }

        if (NotificationStatus::SENT === $status && null === $sentAt) {
            throw new InvalidArgumentException('A sent notification must carry the time it was sent.');
        }

        if (NotificationStatus::FAILED === $status && null === $failureReason) {
            throw new InvalidArgumentException('A failed notification must carry the failure reason.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): NotificationType
    {
        return $this->type;
    }

    public function channel(): Channel
    {
        return $this->channel;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function dedupKey(): string
    {
        return $this->dedupKey;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function status(): NotificationStatus
    {
        return $this->status;
    }

    public function sentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    /**
     * Record a successful delivery. A previous failure may be retried; a
     * notification already sent must not be sent again (that is the duplicate
     * the dedup key exists to prevent).
     */
    public function markSent(DateTimeImmutable $sentAt): void
    {
        if (NotificationStatus::SENT === $this->status) {
            throw new DomainException('Notification has already been sent.');
        }

        $this->status = NotificationStatus::SENT;
        $this->sentAt = $sentAt;
        $this->failureReason = null;
    }

    /**
     * Record a failed delivery attempt, keeping the reason for diagnostics.
     */
    public function markFailed(string $reason): void
    {
        if (NotificationStatus::SENT === $this->status) {
            throw new DomainException('A sent notification cannot be marked as failed.');
        }

        if ('' === trim($reason)) {
            throw new InvalidArgumentException('Notification failure reason cannot be empty.');
        }

        $this->status = NotificationStatus::FAILED;
        $this->failureReason = $reason;
    }
}
