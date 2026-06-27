<?php

declare(strict_types=1);

namespace App\Messaging;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Thin, typed wrapper over the command bus (HMAI-241).
 *
 * `dispatch()` is a plain fire-and-forget passthrough — safe for both sync and
 * async-routed commands (an async command gets a SentStamp, never a
 * HandledStamp, so it must not go through {@see HandleTrait}).
 *
 * `dispatchAndReturn()` is for the handful of synchronously handled commands
 * that return a value (e.g. the id of a freshly created aggregate). It replaces
 * the null-unsafe `->last(HandledStamp::class)->getResult()` chain and throws a
 * clear exception when no handler ran instead of dereferencing `null`.
 */
final class CommandBus
{
    use HandleTrait;

    public function __construct(MessageBusInterface $commandBus)
    {
        $this->messageBus = $commandBus;
    }

    /**
     * @param StampInterface[] $stamps
     */
    public function dispatch(object $command, array $stamps = []): Envelope
    {
        return $this->messageBus->dispatch($command, $stamps);
    }

    public function dispatchAndReturn(object $command): mixed
    {
        return $this->handle($command);
    }
}
