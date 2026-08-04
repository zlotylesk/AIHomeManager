<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\PaginationRequestParser;
use App\Messaging\CommandBus;
use App\Messaging\QueryBus;
use App\Module\YouTubeProgress\Application\Command\MarkVideoStarted;
use App\Module\YouTubeProgress\Application\Command\MarkVideoWatched;
use App\Module\YouTubeProgress\Application\Command\PushSessionToYouTube;
use App\Module\YouTubeProgress\Application\Command\RegenerateSessions;
use App\Module\YouTubeProgress\Application\Command\SyncWatchlist;
use App\Module\YouTubeProgress\Application\DTO\VideoDTO;
use App\Module\YouTubeProgress\Application\DTO\WatchSessionDTO;
use App\Module\YouTubeProgress\Application\Query\GetSessions;
use App\Module\YouTubeProgress\Application\Query\GetWatchlist;
use App\Shared\Pagination\Page;
use DateTimeImmutable;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Read + command API for the /youtube-progress panel (T13).
 *
 * Reads go through the query bus (GetWatchlist / GetSessions, DBAL handlers
 * returning DTOs) — consistent with every other module (HMAI-236). Writes
 * dispatch the existing command handlers on the synchronous command bus;
 * not-found and idempotency invariants live in those handlers, so a
 * NotFoundHttpException thrown there is unwrapped by ApiExceptionListener back
 * into a 404 here.
 */
#[Route('/youtube-progress')]
final class YouTubeProgressController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
        #[Autowire('%env(YOUTUBE_WATCHLIST_PLAYLIST_ID)%')]
        private readonly string $watchlistPlaylistId,
        private readonly NormalizerInterface $normalizer,
        private readonly PaginationRequestParser $pagination,
    ) {
    }

    #[Route('/watchlist', methods: ['GET'])]
    #[OA\Get(
        summary: 'Get the watchlist',
        description: 'Returns one page of watch-later videos with their per-video progress status. The former `videos` key is now the shared `data` key, so every list endpoint answers with the same envelope.',
        tags: ['YouTubeProgress'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/PageParam'),
            new OA\Parameter(ref: '#/components/parameters/PerPageParam'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'One page of the watchlist.',
                content: new OA\JsonContent(
                    required: ['data', 'pagination'],
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: VideoDTO::class))),
                        new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function watchlist(Request $request): JsonResponse
    {
        /** @var Page<VideoDTO> $videos */
        $videos = $this->queryBus->ask(new GetWatchlist($this->pagination->parse($request)));

        return new JsonResponse($this->normalizer->normalize($videos));
    }

    #[Route('/sessions', methods: ['GET'])]
    #[OA\Get(
        summary: 'Get the watch sessions',
        description: 'Returns one page of generated watch sessions, each with its ordered videos and total duration. The former `sessions` key is now the shared `data` key, so every list endpoint answers with the same envelope.',
        tags: ['YouTubeProgress'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/PageParam'),
            new OA\Parameter(ref: '#/components/parameters/PerPageParam'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'One page of watch sessions.',
                content: new OA\JsonContent(
                    required: ['data', 'pagination'],
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: WatchSessionDTO::class))),
                        new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function sessions(Request $request): JsonResponse
    {
        /** @var Page<WatchSessionDTO> $sessions */
        $sessions = $this->queryBus->ask(new GetSessions($this->pagination->parse($request)));

        return new JsonResponse($this->normalizer->normalize($sessions));
    }

    #[Route('/sync', methods: ['POST'])]
    #[OA\Post(
        summary: 'Sync the watchlist from YouTube',
        description: 'Pulls the configured YouTube playlist and regenerates watch sessions. 400 when YOUTUBE_WATCHLIST_PLAYLIST_ID is not configured.',
        tags: ['YouTubeProgress'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sync completed; returns the new counts.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'sessions_count', type: 'integer'),
                    new OA\Property(property: 'videos_count', type: 'integer'),
                ]),
            ),
            new OA\Response(response: 400, description: 'The YouTube watchlist playlist is not configured.', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function sync(): JsonResponse
    {
        if ('' === trim($this->watchlistPlaylistId)) {
            return new JsonResponse(
                ['error' => 'YouTube watchlist not configured. Set YOUTUBE_WATCHLIST_PLAYLIST_ID.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $this->commandBus->dispatch(new SyncWatchlist($this->watchlistPlaylistId));
        $this->commandBus->dispatch(new RegenerateSessions());

        // The counters report the whole set, not the page that came back — a
        // "synced 50 videos" that really meant "the first page holds 50" would
        // understate the import as soon as the watchlist outgrew one page.
        /** @var Page<VideoDTO> $videos */
        $videos = $this->queryBus->ask(new GetWatchlist());
        /** @var Page<WatchSessionDTO> $sessions */
        $sessions = $this->queryBus->ask(new GetSessions());

        return new JsonResponse([
            'sessions_count' => $sessions->total,
            'videos_count' => $videos->total,
        ]);
    }

    #[Route('/videos/{id}/start', methods: ['POST'], requirements: ['id' => '[A-Za-z0-9_-]+'])]
    #[OA\Post(
        summary: 'Mark a video started',
        description: 'Idempotent — records the first-started timestamp for a watchlist video.',
        tags: ['YouTubeProgress'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'YouTube video id.', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Video marked started.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
        ],
    )]
    public function startVideo(string $id): JsonResponse
    {
        $this->commandBus->dispatch(new MarkVideoStarted($id, new DateTimeImmutable()));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/videos/{id}/watched', methods: ['POST'], requirements: ['id' => '[A-Za-z0-9_-]+'])]
    #[OA\Post(
        summary: 'Mark a video watched',
        description: 'Idempotent — records the watched timestamp for a watchlist video.',
        tags: ['YouTubeProgress'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'YouTube video id.', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Video marked watched.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
        ],
    )]
    public function watchedVideo(string $id): JsonResponse
    {
        $this->commandBus->dispatch(new MarkVideoWatched($id, new DateTimeImmutable()));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/sessions/{id}/push-to-youtube', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[OA\Post(
        summary: 'Push a session to YouTube',
        description: 'Creates a YouTube playlist from the session videos. 404 when the session does not exist.',
        tags: ['YouTubeProgress'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Watch session UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Session pushed to YouTube.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
        ],
    )]
    public function pushSession(string $id): JsonResponse
    {
        $this->commandBus->dispatch(new PushSessionToYouTube($id));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
