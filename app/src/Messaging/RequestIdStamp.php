<?php

declare(strict_types=1);

namespace App\Messaging;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the HTTP request correlation id onto a Messenger envelope so a message
 * dispatched to the async transport can be tied back — in the worker's logs — to
 * the request that produced it (HMAI-367).
 *
 * Attached at dispatch by {@see AttachRequestIdStampMiddleware}, restored on the
 * worker side by the receiver middleware, and read as a fallback by
 * {@see \App\Logging\RequestIdProcessor}.
 */
final readonly class RequestIdStamp implements StampInterface
{
    public function __construct(public string $requestId)
    {
    }
}
