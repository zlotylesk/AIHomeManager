<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Messaging\QueryBus;
use App\Module\Dashboard\Application\DTO\DashboardDTO;
use App\Module\Dashboard\Application\Query\GetDashboard;
use DateTimeImmutable;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Thin cockpit endpoint: dispatches GetDashboard for "today" through the
 * query.bus and serializes the composed read-model. No domain logic here —
 * the widget composition (and its per-widget fault isolation) lives in the
 * GetDashboardHandler.
 */
#[Route('/dashboard')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly QueryBus $queryBus,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        summary: 'Startup cockpit — the picture of the day',
        description: 'Aggregates each module\'s "today" slice into one landing read-model: today\'s pending tasks, the article of the day, goal snapshots with streaks, what to continue watching/reading, and recent listening. A widget whose source is unavailable degrades to an empty section rather than failing the whole response.',
        tags: ['Dashboard'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The composed cockpit read-model.',
                content: new OA\JsonContent(ref: new Model(type: DashboardDTO::class)),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function index(): JsonResponse
    {
        $dashboard = $this->queryBus->ask(new GetDashboard(new DateTimeImmutable('today')));

        return new JsonResponse($this->normalizer->normalize($dashboard));
    }
}
