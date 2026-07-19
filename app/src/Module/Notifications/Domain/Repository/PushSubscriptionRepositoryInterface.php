<?php

declare(strict_types=1);

namespace App\Module\Notifications\Domain\Repository;

use App\Module\Notifications\Domain\Entity\PushSubscription;

interface PushSubscriptionRepositoryInterface
{
    public function save(PushSubscription $subscription): void;

    /**
     * Every browser currently subscribed. The push channel delivers to all of
     * them — a single user is still reachable on several devices.
     *
     * @return list<PushSubscription>
     */
    public function findAll(): array;

    /**
     * The endpoint is the subscription's natural key: re-subscribing the same
     * browser yields the same endpoint, so this is how a repeat registration is
     * recognized instead of duplicated.
     */
    public function findByEndpoint(string $endpoint): ?PushSubscription;

    /**
     * Drop a subscription the push service no longer accepts (404/410).
     */
    public function remove(PushSubscription $subscription): void;
}
