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
 * trail. CLI/worker contexts have no main request and pass through untouched.
 */
#[AsMonologProcessor]
final readonly class RequestIdProcessor
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return $record;
        }

        $requestId = $request->attributes->get(RequestIdListener::ATTRIBUTE_NAME);
        if (!\is_string($requestId) || '' === $requestId) {
            return $record;
        }

        return $record->with(extra: [...$record->extra, 'request_id' => $requestId]);
    }
}
