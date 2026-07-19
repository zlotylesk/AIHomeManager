<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\EventHandler;

use App\Module\Notifications\Application\Command\DispatchNotification;
use App\Shared\Notification\NotifiableEvent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The whole reactive trigger: one handler, listening for the shared-kernel
 * {@see NotifiableEvent} interface rather than for any source module's event
 * class. Messenger resolves handlers through the message's interfaces, so every
 * module that opts an event in is picked up here without a line of wiring — and
 * without Notifications ever importing Tasks, Articles or Goals.
 *
 * Nothing is decided here beyond "was anything worth announcing": whether the
 * user wants this type, over which channels, and whether quiet hours block it
 * belongs to the dispatch engine, which both triggers share. Idempotency is
 * shared the same way — the request's subject+window become the dedup key, so
 * this rail and the scheduler sweep cannot announce one occurrence twice.
 */
#[AsMessageHandler]
final readonly class AnnounceNotifiableEvent
{
    public function __construct(private MessageBusInterface $commandBus)
    {
    }

    public function __invoke(NotifiableEvent $event): void
    {
        $request = $event->toNotificationRequest();

        if (null === $request) {
            return;
        }

        $this->commandBus->dispatch(new DispatchNotification(
            $request->type,
            $request->subject,
            $request->window,
            $request->payload,
        ));
    }
}
