<?php

declare(strict_types=1);

namespace App\Messaging;

use App\EventListener\RequestIdListener;
use App\Logging\RequestIdHolder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Sender-side middleware: stamps an envelope with the current request's
 * correlation id at dispatch, so an async-routed message carries it to the
 * worker (HMAI-367).
 *
 * A stamp is added only when the envelope has none yet AND a correlation id is
 * available — from the main HTTP request's `_request_id` attribute (set by
 * {@see RequestIdListener}), or, failing that, from {@see RequestIdHolder}. The
 * holder covers the case the request source cannot: a handler running on the
 * worker that chains another async message (Movies' watched-movies import fires
 * the ratings import at the end), which would otherwise start a fresh, unlinked
 * trail even though it is a direct consequence of the original request.
 *
 * With neither source set — the scheduler firing on its own — this is a no-op and
 * the message goes out bare. On the worker the middleware also runs for the
 * incoming envelope, but it already carries its stamp, so what arrived over the
 * wire is left untouched (no duplication, no overwrite).
 */
final readonly class AttachRequestIdStampMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private RequestIdHolder $holder,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(RequestIdStamp::class)) {
            $requestId = $this->fromRequest() ?? $this->holder->get();
            if (\is_string($requestId) && '' !== $requestId) {
                $envelope = $envelope->with(new RequestIdStamp($requestId));
            }
        }

        return $stack->next()->handle($envelope, $stack);
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
