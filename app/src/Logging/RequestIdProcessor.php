<?php

declare(strict_types=1);

namespace App\Logging;

use App\EventListener\RequestIdListener;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Stamps every log line emitted during an HTTP request with the same
 * request_id that RequestIdListener echoes back on the response header — so a
 * single grep in Graylog ties the client-facing correlator to the server-side
 * trail.
 *
 * The Messenger worker has no HTTP request, so the correlator travels on the
 * envelope instead (HMAI-367): RestoreRequestIdMiddleware parks it in
 * {@see RequestIdHolder} for the duration of the handler, and this processor
 * falls back to it. The web source keeps priority — while a request is being
 * served it is the authoritative correlator, and a synchronously dispatched
 * message carries that very id anyway. With neither source set (e.g. the
 * scheduler firing on its own) the record passes through untouched, exactly as
 * before.
 */
#[AsMonologProcessor]
final readonly class RequestIdProcessor
{
    public function __construct(
        private RequestStack $requestStack,
        private RequestIdHolder $holder,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $requestId = $this->fromRequest() ?? $this->holder->get();
        if (!\is_string($requestId) || '' === $requestId) {
            return $record;
        }

        return $record->with(extra: [...$record->extra, 'request_id' => $requestId]);
    }

    private function fromRequest(): ?string
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return null;
        }

        $requestId = $request->attributes->get(RequestIdListener::ATTRIBUTE_NAME);

        return \is_string($requestId) && '' !== $requestId ? $requestId : null;
    }
}
