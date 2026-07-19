<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\CommandHandler;

use App\Module\Notifications\Application\Command\RemovePushSubscription;
use App\Module\Notifications\Domain\Repository\PushSubscriptionRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Unsubscribing an endpoint that is already gone is success, not an error — the
 * caller's intent ("this browser should not receive push") is satisfied either
 * way, and the browser may well have revoked it first.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class RemovePushSubscriptionHandler
{
    public function __construct(private PushSubscriptionRepositoryInterface $subscriptions)
    {
    }

    public function __invoke(RemovePushSubscription $command): void
    {
        $subscription = $this->subscriptions->findByEndpoint($command->endpoint);

        if (null === $subscription) {
            return;
        }

        $this->subscriptions->remove($subscription);
    }
}
