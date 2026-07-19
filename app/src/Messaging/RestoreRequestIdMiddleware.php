<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Logging\RequestIdHolder;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Receiver-side middleware: while a message is handled, exposes the correlation
 * id carried by its {@see RequestIdStamp} through {@see RequestIdHolder}, so logs
 * emitted by the handler on a worker (where there is no HTTP request) can be tied
 * back to the request that dispatched the message (HMAI-367).
 *
 * The holder is set only when the envelope carries a stamp, and cleared in
 * `finally` only when this frame set it. That guard matters for a nested
 * synchronous dispatch inside a worker handler (e.g. PollLastFmRecentTracks
 * dispatching LogListeningSession on the sync command.bus): the nested message
 * carries no stamp, so this middleware leaves the outer id in place instead of
 * wiping it mid-handling.
 *
 * Registered on command.bus + event.bus (the buses that consume the async
 * transport); query.bus is always sync so it needs no restoration.
 */
final readonly class RestoreRequestIdMiddleware implements MiddlewareInterface
{
    public function __construct(private RequestIdHolder $holder)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(RequestIdStamp::class);
        if ($stamp instanceof RequestIdStamp) {
            $this->holder->set($stamp->requestId);
        }

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            if ($stamp instanceof RequestIdStamp) {
                $this->holder->clear();
            }
        }
    }
}
