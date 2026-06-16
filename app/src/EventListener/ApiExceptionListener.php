<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Converts uncaught exceptions on `^/api/*` paths to JSON responses. Without
 * this, Symfony's default ErrorListener renders an HTML error page — and a JS
 * client that did `await response.json()` gets a parse error instead of a
 * useful status code. Priority 64 runs before the framework default (-64).
 *
 * For HTTP exceptions (4xx routed via abort()) we preserve the status; for
 * unrecognised throwables we settle on 500 with a generic message and let the
 * log carry the real cause. Non-/api/* requests fall through unchanged so the
 * Twig frontend keeps its rendered error pages.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 64)]
final readonly class ApiExceptionListener
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();

        if ($exception instanceof HandlerFailedException) {
            $previous = $exception->getPrevious();
            if (null !== $previous) {
                $exception = $previous;
            }
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $message = $status >= 500 ? 'Internal server error.' : $exception->getMessage();
        } else {
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            $message = 'Internal server error.';
        }

        $this->logger->error('Unhandled API exception', [
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
            'status' => $status,
            'exception' => $exception,
        ]);

        $event->setResponse(new JsonResponse(
            ['error' => $message],
            $status
        ));
    }
}
