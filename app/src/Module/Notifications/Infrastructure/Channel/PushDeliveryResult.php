<?php

declare(strict_types=1);

namespace App\Module\Notifications\Infrastructure\Channel;

/**
 * What one push service answered for one subscription, reduced to the two facts
 * the channel acts on: did it go out, and is this subscription dead for good.
 *
 * The distinction matters — a transient 5xx must leave the subscription alone,
 * while 404/410 means the browser is gone and the row has to be dropped.
 */
final readonly class PushDeliveryResult
{
    private function __construct(
        public bool $successful,
        public bool $subscriptionExpired,
        public string $reason,
    ) {
    }

    public static function delivered(): self
    {
        return new self(true, false, '');
    }

    public static function failed(string $reason): self
    {
        return new self(false, false, $reason);
    }

    /**
     * The push service rejected the subscription itself (404/410) — it will never
     * accept it again, so the caller removes it.
     */
    public static function expired(string $reason): self
    {
        return new self(false, true, $reason);
    }
}
