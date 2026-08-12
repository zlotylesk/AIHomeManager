<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 100)]
final readonly class ApiRateLimitListener
{
    public function __construct(
        private RateLimiterFactory $apiPerIpLimiter,
        private RateLimiterFactory $healthPerIpLimiter,
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

        // /api/health gets its own, looser limiter rather than a full exemption — a
        // public endpoint that does five network round trips per call and answers to
        // anyone is still a knob worth bounding, just not at the same threshold that
        // would risk tripping over an uptime monitor or app:monitor:run.
        if (str_starts_with($path, '/api/health')) {
            $this->enforceLimit($event, $this->healthPerIpLimiter, 'health_per_ip', $request, $path);

            return;
        }

        if (!str_starts_with($path, '/api/')) {
            return;
        }

        $this->enforceLimit($event, $this->apiPerIpLimiter, 'api_per_ip', $request, $path);
    }

    private function enforceLimit(
        RequestEvent $event,
        RateLimiterFactory $limiterFactory,
        string $limiterName,
        Request $request,
        string $path,
    ): void {
        $clientIp = $request->getClientIp() ?? 'unknown';
        $limiter = $limiterFactory->create($clientIp);
        $limit = $limiter->consume();

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            $this->logger->warning('API rate limit triggered', [
                'rate_limit_triggered' => true,
                'limiter' => $limiterName,
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
