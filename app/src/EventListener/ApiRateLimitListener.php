<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 100)]
final readonly class ApiRateLimitListener
{
    public function __construct(
        private RateLimiterFactory $apiPerIpLimiter,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (!str_starts_with($path, '/api/') || str_starts_with($path, '/api/health')) {
            return;
        }

        $clientIp = $request->getClientIp() ?? 'unknown';
        $limiter = $this->apiPerIpLimiter->create($clientIp);
        $limit = $limiter->consume();

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            $this->logger->warning('API rate limit triggered', [
                'rate_limit_triggered' => true,
                'limiter' => 'api_per_ip',
                'ip' => $clientIp,
                'path' => $path,
                'retry_after' => $retryAfter,
            ]);

            $event->setResponse(new JsonResponse(
                ['error' => 'Too Many Requests', 'retry_after' => $retryAfter],
                Response::HTTP_TOO_MANY_REQUESTS,
                [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Remaining' => '0',
                    'X-RateLimit-Limit' => (string) $limit->getLimit(),
                ],
            ));
        }
    }
}
