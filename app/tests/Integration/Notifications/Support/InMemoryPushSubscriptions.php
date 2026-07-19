<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notifications\Support;

use App\Module\Notifications\Domain\Entity\PushSubscription;
use App\Module\Notifications\Domain\Repository\PushSubscriptionRepositoryInterface;

/**
 * Lets the channel's fan-out and expiry rules be asserted against the resulting
 * subscription set, without a database round trip per case.
 */
final class InMemoryPushSubscriptions implements PushSubscriptionRepositoryInterface
{
    /**
     * @param list<PushSubscription> $subscriptions
     */
    public function __construct(private array $subscriptions = [])
    {
    }

    public function save(PushSubscription $subscription): void
    {
        $this->subscriptions[] = $subscription;
    }

    public function findAll(): array
    {
        return $this->subscriptions;
    }

    public function findByEndpoint(string $endpoint): ?PushSubscription
    {
        foreach ($this->subscriptions as $subscription) {
            if ($subscription->endpoint() === $endpoint) {
                return $subscription;
            }
        }

        return null;
    }

    public function remove(PushSubscription $subscription): void
    {
        $this->subscriptions = array_values(array_filter(
            $this->subscriptions,
            static fn (PushSubscription $candidate): bool => $candidate->id() !== $subscription->id(),
        ));
    }
}
