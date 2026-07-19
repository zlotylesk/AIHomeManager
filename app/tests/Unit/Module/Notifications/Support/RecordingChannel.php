<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Notifications\Support;

use App\Module\Notifications\Domain\Entity\Notification;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Port\NotificationChannelInterface;
use RuntimeException;

/**
 * A channel adapter that records what it was asked to deliver, and optionally
 * fails the first N attempts to exercise the retry path.
 */
final class RecordingChannel implements NotificationChannelInterface
{
    /** @var list<Notification> */
    public array $sent = [];

    public int $attempts = 0;

    public function __construct(
        private readonly Channel $channel,
        private int $failuresBeforeSuccess = 0,
        private readonly string $failureMessage = 'delivery failed',
    ) {
    }

    public function channel(): Channel
    {
        return $this->channel;
    }

    public function send(Notification $notification): void
    {
        ++$this->attempts;

        if ($this->failuresBeforeSuccess > 0) {
            --$this->failuresBeforeSuccess;

            throw new RuntimeException($this->failureMessage);
        }

        $this->sent[] = $notification;
    }
}
