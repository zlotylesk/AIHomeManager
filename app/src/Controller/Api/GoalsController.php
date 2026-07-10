<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Messaging\CommandBus;
use App\Messaging\QueryBus;
use App\Module\Goals\Application\Command\CreateGoal;
use App\Module\Goals\Application\Command\DeleteGoal;
use App\Module\Goals\Application\Command\UpdateGoal;
use App\Module\Goals\Application\DTO\GoalProgressDTO;
use App\Module\Goals\Application\DTO\StreakDTO;
use App\Module\Goals\Application\Exception\GoalNotFoundException;
use App\Module\Goals\Application\Query\GetGoalsProgress;
use App\Module\Goals\Application\Query\GetStreaks;
use InvalidArgumentException;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[Route('/goals')]
final class GoalsController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        summary: 'List goals with progress',
        description: 'Returns every defined goal with its current-window progress (achieved amount, capped percent, met flag).',
        tags: ['Goals'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The goals with their computed progress.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: GoalProgressDTO::class))),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function list(): JsonResponse
    {
        /** @var GoalProgressDTO[] $progress */
        $progress = $this->queryBus->ask(new GetGoalsProgress());

        return new JsonResponse($this->normalizer->normalize($progress));
    }

    #[Route('/streaks', methods: ['GET'])]
    #[OA\Get(
        summary: 'List activity streaks',
        description: 'Returns the day-continuity streak (current and longest run) for every activity type that has a goal.',
        tags: ['Goals'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The streaks per activity type.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: StreakDTO::class))),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function streaks(): JsonResponse
    {
        /** @var StreakDTO[] $streaks */
        $streaks = $this->queryBus->ask(new GetStreaks());

        return new JsonResponse($this->normalizer->normalize($streaks));
    }

    #[Route('', methods: ['POST'])]
    #[OA\Post(
        summary: 'Define a goal',
        description: 'Creates a goal for an activity type, a positive target and a rolling period.',
        tags: ['Goals'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'target', 'period'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['book_pages', 'series_episodes', 'articles_read', 'music_albums', 'youtube_videos']),
                    new OA\Property(property: 'target', type: 'integer', minimum: 1, example: 50),
                    new OA\Property(property: 'period', type: 'string', enum: ['daily', 'weekly', 'monthly']),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Goal created.',
                content: new OA\JsonContent(required: ['id'], properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid')]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $type = $data['type'] ?? null;
        $target = $data['target'] ?? null;
        $period = $data['period'] ?? null;

        if (!is_string($type) || !is_int($target) || !is_string($period)) {
            return new JsonResponse(
                ['error' => 'Fields "type" (string), "target" (integer) and "period" (string) are required.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $id = $this->commandBus->dispatchAndReturn(new CreateGoal($type, $target, $period));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof InvalidArgumentException) {
                return new JsonResponse(['error' => $e->getPrevious()->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            throw $e;
        }

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PUT'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Put(
        summary: 'Update a goal',
        description: 'Adjusts a goal\'s target and period. The measured activity type is immutable.',
        tags: ['Goals'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Goal UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['target', 'period'],
                properties: [
                    new OA\Property(property: 'target', type: 'integer', minimum: 1, example: 100),
                    new OA\Property(property: 'period', type: 'string', enum: ['daily', 'weekly', 'monthly']),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Goal updated.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $target = $data['target'] ?? null;
        $period = $data['period'] ?? null;

        if (!is_int($target) || !is_string($period)) {
            return new JsonResponse(
                ['error' => 'Fields "target" (integer) and "period" (string) are required.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $this->commandBus->dispatch(new UpdateGoal($id, $target, $period));
        } catch (HandlerFailedException $e) {
            $prev = $e->getPrevious();
            if ($prev instanceof GoalNotFoundException) {
                return new JsonResponse(['error' => 'Goal not found.'], Response::HTTP_NOT_FOUND);
            }
            if ($prev instanceof InvalidArgumentException) {
                return new JsonResponse(['error' => $prev->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Delete(
        summary: 'Delete a goal',
        tags: ['Goals'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Goal UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Goal deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
        ],
    )]
    public function delete(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new DeleteGoal($id));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof GoalNotFoundException) {
                return new JsonResponse(['error' => 'Goal not found.'], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
