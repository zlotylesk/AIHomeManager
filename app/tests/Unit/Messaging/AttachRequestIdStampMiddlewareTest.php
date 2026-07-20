<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messaging;

use App\EventListener\RequestIdListener;
use App\Logging\RequestIdHolder;
use App\Messaging\AttachRequestIdStampMiddleware;
use App\Messaging\RequestIdStamp;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Test\Middleware\MiddlewareTestCase;

final class AttachRequestIdStampMiddlewareTest extends MiddlewareTestCase
{
    public function testAttachesStampFromTheCurrentRequest(): void
    {
        $requestStack = new RequestStack();
        $request = new Request();
        $request->attributes->set(RequestIdListener::ATTRIBUTE_NAME, 'req-abc-123');
        $requestStack->push($request);

        $middleware = new AttachRequestIdStampMiddleware($requestStack, new RequestIdHolder());
        $envelope = $middleware->handle(new Envelope(new stdClass()), $this->getStackMock());

        $stamp = $envelope->last(RequestIdStamp::class);
        self::assertInstanceOf(RequestIdStamp::class, $stamp);
        self::assertSame('req-abc-123', $stamp->requestId);
    }

    public function testAddsNoStampWithoutAMainRequest(): void
    {
        // CLI/scheduler context: no HTTP request, no holder value, so the message goes out bare.
        $middleware = new AttachRequestIdStampMiddleware(new RequestStack(), new RequestIdHolder());
        $envelope = $middleware->handle(new Envelope(new stdClass()), $this->getStackMock());

        self::assertNull($envelope->last(RequestIdStamp::class));
    }

    public function testFallsBackToTheHolderWhenAWorkerHandlerChainsAMessage(): void
    {
        // A handler running on the worker has no HTTP request, but the message it
        // chains is a consequence of the original one and must stay on its trail.
        $holder = new RequestIdHolder();
        $holder->set('carried-from-the-wire');

        $middleware = new AttachRequestIdStampMiddleware(new RequestStack(), $holder);
        $envelope = $middleware->handle(new Envelope(new stdClass()), $this->getStackMock());

        $stamp = $envelope->last(RequestIdStamp::class);
        self::assertInstanceOf(RequestIdStamp::class, $stamp);
        self::assertSame('carried-from-the-wire', $stamp->requestId);
    }

    public function testTheCurrentRequestWinsOverTheHolder(): void
    {
        // Serving a request is the authoritative context; a holder value left over
        // from an earlier frame must not shadow it.
        $requestStack = new RequestStack();
        $request = new Request();
        $request->attributes->set(RequestIdListener::ATTRIBUTE_NAME, 'from-request');
        $requestStack->push($request);

        $holder = new RequestIdHolder();
        $holder->set('from-holder');

        $middleware = new AttachRequestIdStampMiddleware($requestStack, $holder);
        $envelope = $middleware->handle(new Envelope(new stdClass()), $this->getStackMock());

        $stamp = $envelope->last(RequestIdStamp::class);
        self::assertInstanceOf(RequestIdStamp::class, $stamp);
        self::assertSame('from-request', $stamp->requestId);
    }

    public function testDoesNotDuplicateAnExistingStamp(): void
    {
        // On the worker the middleware runs again; the stamp from the wire must survive.
        $requestStack = new RequestStack();
        $request = new Request();
        $request->attributes->set(RequestIdListener::ATTRIBUTE_NAME, 'from-request');
        $requestStack->push($request);

        $middleware = new AttachRequestIdStampMiddleware($requestStack, new RequestIdHolder());
        $envelope = new Envelope(new stdClass(), [new RequestIdStamp('already-here')]);
        $result = $middleware->handle($envelope, $this->getStackMock());

        self::assertCount(1, $result->all(RequestIdStamp::class));
        $stamp = $result->last(RequestIdStamp::class);
        self::assertInstanceOf(RequestIdStamp::class, $stamp);
        self::assertSame('already-here', $stamp->requestId);
    }

    public function testAddsNoStampWhenTheRequestHasNoRequestId(): void
    {
        // A request without the _request_id attribute (e.g. a sub-request) must not stamp.
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $middleware = new AttachRequestIdStampMiddleware($requestStack, new RequestIdHolder());
        $envelope = $middleware->handle(new Envelope(new stdClass()), $this->getStackMock());

        self::assertNull($envelope->last(RequestIdStamp::class));
    }
}
