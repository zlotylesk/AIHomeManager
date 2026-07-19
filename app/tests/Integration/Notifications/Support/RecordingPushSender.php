<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notifications\Support;

use App\Module\Notifications\Domain\Entity\PushSubscription;
use App\Module\Notifications\Infrastructure\Channel\PushDeliveryResult;
use App\Module\Notifications\Infrastructure\Channel\WebPushSenderInterface;

/**
 * Records what each subscription was handed, and can be told how to answer for a
 * given subscription id — enough to drive the expired/failed branches without a
 * real push service.
 */
final class RecordingPushSender implements WebPushSenderInterface
{
    /** @var list<string> */
    public array $sent = [];

    /**
     * @param array<string, PushDeliveryResult> $resultsBySubscriptionId
     */
    public function __construct(private readonly array $resultsBySubscriptionId = [])
    {
    }

    public function send(PushSubscription $subscription, string $payload): PushDeliveryResult
    {
        $result = $this->resultsBySubscriptionId[$subscription->id()] ?? PushDeliveryResult::delivered();

        if ($result->successful) {
            $this->sent[] = $payload;
        }

        return $result;
    }
}
