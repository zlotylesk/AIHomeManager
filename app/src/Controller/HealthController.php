<?php

declare(strict_types=1);

namespace App\Controller;

use App\Health\HealthChecker;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HMAI-37: /api/health — readiness probe for orchestrators and uptime monitors.
 *
 * Public endpoint (firewall-bypassed in ApiKeyAuthenticator::supports). Returns
 * 200 when every checked component is reachable, 503 the moment one is down.
 * The JSON body lists each component individually so an operator can spot the
 * culprit without trawling logs.
 */
final class HealthController extends AbstractController
{
    public function __construct(private readonly HealthChecker $checker)
    {
    }

    #[Route('/api/health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $components = $this->checker->check();
        $hasDown = in_array('down', $components, true);
        $hasDegraded = in_array('degraded', $components, true);

        // HMAI-155: 3-state. `degraded` keeps 200 so orchestrators keep routing
        // traffic, but the body signal lets monitoring page before things fail.
        $status = match (true) {
            $hasDown => 'unhealthy',
            $hasDegraded => 'degraded',
            default => 'healthy',
        };

        return new JsonResponse(
            [
                'status' => $status,
                'components' => $components,
                'timestamp' => new DateTimeImmutable()->format(DATE_ATOM),
            ],
            $hasDown ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK,
        );
    }
}
