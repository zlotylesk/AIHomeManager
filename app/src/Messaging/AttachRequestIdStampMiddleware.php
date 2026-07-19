<?php

declare(strict_types=1);

namespace App\Messaging;

use App\EventListener\RequestIdListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Sender-side middleware: stamps an envelope with the current request's
 * correlation id at dispatch, so an async-routed message carries it to the
 * worker (HMAI-367).
 *
 * A stamp is added only when the envelope has none yet AND there is a main HTTP
 * request exposing the `_request_id` attribute set by {@see RequestIdListener}.
 * In CLI/scheduler context there is no main request, so this is a no-op — the
 * message is sent without a stamp. On the worker the middleware also runs, but
 * `getMainRequest()` is null there, so the stamp that arrived over the wire is
 * left untouched (no duplication, no overwrite).
 */
final readonly class AttachRequestIdStampMiddleware implements MiddlewareInterface
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(RequestIdStamp::class)) {
            $request = $this->requestStack->getMainRequest();
            if (null !== $request) {
                $requestId = $request->attributes->get(RequestIdListener::ATTRIBUTE_NAME);
                if (\is_string($requestId) && '' !== $requestId) {
                    $envelope = $envelope->with(new RequestIdStamp($requestId));
                }
            }
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
