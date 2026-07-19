<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\CommandHandler;

use App\Module\Notifications\Application\Command\RegisterPushSubscription;
use App\Module\Notifications\Domain\Entity\PushSubscription;
use App\Module\Notifications\Domain\Repository\PushSubscriptionRepositoryInterface;
use DateTimeImmutable;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Idempotent by endpoint: browsers hand back the same subscription on every
 * page load, so re-registering an existing one is a no-op rather than a
 * duplicate row (the unique index would reject it anyway).
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class RegisterPushSubscriptionHandler
{
    public function __construct(private PushSubscriptionRepositoryInterface $subscriptions)
    {
    }

    public function __invoke(RegisterPushSubscription $command): void
    {
        if (null !== $this->subscriptions->findByEndpoint($command->endpoint)) {
            return;
        }

        $this->subscriptions->save(new PushSubscription(
            Uuid::v4()->toRfc4122(),
            $command->endpoint,
            $command->publicKey,
            $command->authToken,
            new DateTimeImmutable(),
        ));
    }
}
