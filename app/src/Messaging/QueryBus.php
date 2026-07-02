<?php

declare(strict_types=1);

namespace App\Messaging;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Thin, typed wrapper over the query bus (HMAI-241).
 *
 * Replaces the repeated, null-unsafe
 * `$bus->dispatch($q)->last(HandledStamp::class)->getResult()` chain that lived
 * in every controller. {@see HandleTrait::handle()} dispatches the query and
 * returns the single handler's result, throwing a clear exception when a query
 * has no handler instead of dereferencing `null`.
 */
final class QueryBus
{
    use HandleTrait;

    public function __construct(#[Target('query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function ask(object $query): mixed
    {
        return $this->handle($query);
    }
}
