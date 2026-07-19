<?php

declare(strict_types=1);

namespace App\Logging;

/**
 * Process-scoped carrier for the request correlation id in a context that has no
 * HTTP request — i.e. the Messenger worker (HMAI-367).
 *
 * A worker consumes messages sequentially in a single-threaded loop, so a plain
 * shared service instance is enough: the receiver middleware sets the id from the
 * envelope's {@see \App\Messaging\RequestIdStamp} before the handler runs and
 * clears it afterwards, while {@see RequestIdProcessor} reads it as a fallback
 * when {@see \Symfony\Component\HttpFoundation\RequestStack} has no main request.
 * No concurrency here, so no thread-local trickery is needed.
 */
final class RequestIdHolder
{
    private ?string $requestId = null;

    public function set(?string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function get(): ?string
    {
        return $this->requestId;
    }

    public function clear(): void
    {
        $this->requestId = null;
    }
}
