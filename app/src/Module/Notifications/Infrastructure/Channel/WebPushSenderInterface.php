<?php

declare(strict_types=1);

namespace App\Module\Notifications\Infrastructure\Channel;

use App\Module\Notifications\Domain\Entity\PushSubscription;

/**
 * The seam between the push channel and the WebPush library.
 *
 * It exists so the channel's rules — fan-out across devices, dropping expired
 * subscriptions, deciding when the whole send counts as failed — are testable
 * without a real push service and without pinning the library's concrete
 * WebPush class into the channel.
 */
interface WebPushSenderInterface
{
    /**
     * Deliver an already-encoded payload to one subscription. Reports the outcome
     * as a value rather than throwing: a dead subscription among several is a
     * normal result the channel handles, not an exception.
     */
    public function send(PushSubscription $subscription, string $payload): PushDeliveryResult;
}
