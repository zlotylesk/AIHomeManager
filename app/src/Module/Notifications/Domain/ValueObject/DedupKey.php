<?php

declare(strict_types=1);

namespace App\Module\Notifications\Domain\ValueObject;

use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationType;
use InvalidArgumentException;

/**
 * Identifies the real-world occurrence a notification stands for — the notion of
 * "the same thing happened" that both triggers (reactive and scheduler) must
 * agree on, so an occurrence seen twice is not announced twice.
 *
 * An occurrence is a type plus the subject it is about plus the window it falls
 * in: "task_due" + "task-42" + "2026-07-16". The window is what makes a
 * recurring reminder about the *same* subject a new occurrence tomorrow.
 *
 * One occurrence produces one notification per channel, and
 * {@see \App\Module\Notifications\Domain\Repository\NotificationRepositoryInterface::findByDedupKey()}
 * resolves a single notification — so the value stored on the aggregate is the
 * channel-qualified {@see self::forChannel()}, not the bare occurrence.
 */
final readonly class DedupKey
{
    private const string SEPARATOR = ':';

    private function __construct(private string $occurrence)
    {
    }

    /**
     * @param string $subject stable reference to what the notification is about (e.g. "task-42")
     * @param string $window  the occurrence window that makes a repeat a new event (e.g. a date)
     */
    public static function forOccurrence(NotificationType $type, string $subject, string $window): self
    {
        foreach (['subject' => $subject, 'window' => $window] as $label => $part) {
            if ('' === trim($part)) {
                throw new InvalidArgumentException(sprintf('Dedup key %s cannot be empty.', $label));
            }

            if (str_contains($part, self::SEPARATOR)) {
                throw new InvalidArgumentException(sprintf('Dedup key %s cannot contain "%s".', $label, self::SEPARATOR));
            }
        }

        return new self(implode(self::SEPARATOR, [$type->value, trim($subject), trim($window)]));
    }

    /**
     * The occurrence identity, without a channel. Not what gets persisted — see
     * {@see self::forChannel()}.
     */
    public function occurrence(): string
    {
        return $this->occurrence;
    }

    /**
     * The key stored on a notification: this occurrence as carried by one
     * channel. Two channels for the same occurrence are two notifications with
     * two distinct keys, each independently deduplicated.
     */
    public function forChannel(Channel $channel): string
    {
        return $this->occurrence.self::SEPARATOR.$channel->value;
    }

    public function equals(self $other): bool
    {
        return $this->occurrence === $other->occurrence;
    }
}
