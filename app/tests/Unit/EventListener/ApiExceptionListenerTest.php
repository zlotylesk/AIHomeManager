<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\ApiExceptionListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Throwable;

class ApiExceptionListenerTest extends TestCase
{
    public function testIgnoresNonApiPaths(): void
    {
        $event = $this->buildEvent('/series', new RuntimeException('boom'));

        (new ApiExceptionListener(new NullLogger()))($event);

        self::assertNull($event->getResponse());
    }

    public function testGenericThrowableYieldsGeneric500(): void
    {
        $event = $this->buildEvent('/api/series', new RuntimeException('Internal SQL detail'));

        (new ApiExceptionListener(new NullLogger()))($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Internal server error.', $body['error']);
        // The original exception message must not leak to the client — that
        // is the entire point of HMAI-79.
        self::assertStringNotContainsString('Internal SQL detail', (string) $response->getContent());
    }

    public function testHttp4xxExceptionPreservesStatusAndMessage(): void
    {
        $event = $this->buildEvent('/api/series', new NotFoundHttpException('Series not found.'));

        (new ApiExceptionListener(new NullLogger()))($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Series not found.', $body['error']);
    }

    public function testHttp4xxAlsoFiresWhenWrappedInHandlerFailedException(): void
    {
        $inner = new BadRequestHttpException('Malformed payload.');
        $envelope = new Envelope(new stdClass(), [new BusNameStamp('command.bus')]);
        $wrapped = new HandlerFailedException($envelope, [$inner]);

        $event = $this->buildEvent('/api/books', $wrapped);

        (new ApiExceptionListener(new NullLogger()))($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Malformed payload.', $body['error']);
    }

    public function testLoggerReceivesExceptionContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Unhandled API exception', $this->callback(static fn (array $ctx): bool => '/api/series' === $ctx['path']
                && 'GET' === $ctx['method']
                && 500 === $ctx['status']
                && $ctx['exception'] instanceof RuntimeException));

        $event = $this->buildEvent('/api/series', new RuntimeException('boom'));

        (new ApiExceptionListener($logger))($event);
    }

    private function buildEvent(string $path, Throwable $exception): ExceptionEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create($path, 'GET');

        return new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
    }
}
