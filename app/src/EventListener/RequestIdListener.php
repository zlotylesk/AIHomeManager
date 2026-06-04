<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Per-request correlation ID: read the inbound header (or generate UUID v4),
 * expose it on the request as _request_id, and echo it back on the response so
 * a client can pin a log search to the exact request they made.
 *
 * Inbound values are restricted to alphanumerics/dot/underscore/dash and ≤128
 * chars to keep control characters out of log lines that downstream tooling
 * (Graylog filters, grep) might trust. Anything outside that gets dropped and
 * a server-generated UUID takes its place.
 *
 * kernel.request runs at priority 256 — before ApiRateLimitListener (@100) so
 * a 429 already carries a correlator. Response side runs at the default 0.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 256, method: 'onRequest')]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse')]
final class RequestIdListener
{
    public const string HEADER_NAME = 'X-Request-ID';
    public const string ATTRIBUTE_NAME = '_request_id';

    private const string ALLOWED_PATTERN = '/\A[A-Za-z0-9._-]{1,128}\z/';

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $request->attributes->set(self::ATTRIBUTE_NAME, $this->resolveRequestId($request));
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requestId = $event->getRequest()->attributes->get(self::ATTRIBUTE_NAME);
        if (\is_string($requestId) && '' !== $requestId) {
            $event->getResponse()->headers->set(self::HEADER_NAME, $requestId);
        }
    }

    private function resolveRequestId(Request $request): string
    {
        $inbound = $request->headers->get(self::HEADER_NAME);

        if (\is_string($inbound) && 1 === preg_match(self::ALLOWED_PATTERN, $inbound)) {
            return $inbound;
        }

        return Uuid::v4()->toRfc4122();
    }
}
