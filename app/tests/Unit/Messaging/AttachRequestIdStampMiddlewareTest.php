<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messaging;

use App\EventListener\RequestIdListener;
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

        $middleware = new AttachRequestIdStampMiddleware($requestStack);
        $envelope = $middleware->handle(new Envelope(new stdClass()), $this->getStackMock());

        $stamp = $envelope->last(RequestIdStamp::class);
        self::assertInstanceOf(RequestIdStamp::class, $stamp);
        self::assertSame('req-abc-123', $stamp->requestId);
    }

    public function testAddsNoStampWithoutAMainRequest(): void
    {
        // CLI/scheduler context: no HTTP request, so the message goes out bare.
        $middleware = new AttachRequestIdStampMiddleware(new RequestStack());
        $envelope = $middleware->handle(new Envelope(new stdClass()), $this->getStackMock());

        self::assertNull($envelope->last(RequestIdStamp::class));
    }

    public function testDoesNotDuplicateAnExistingStamp(): void
    {
        // On the worker the middleware runs again; the stamp from the wire must survive.
        $requestStack = new RequestStack();
        $request = new Request();
        $request->attributes->set(RequestIdListener::ATTRIBUTE_NAME, 'from-request');
        $requestStack->push($request);

        $middleware = new AttachRequestIdStampMiddleware($requestStack);
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

        $middleware = new AttachRequestIdStampMiddleware($requestStack);
        $envelope = $middleware->handle(new Envelope(new stdClass()), $this->getStackMock());

        self::assertNull($envelope->last(RequestIdStamp::class));
    }
}
