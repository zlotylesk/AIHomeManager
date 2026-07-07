<?php

declare(strict_types=1);

namespace App\Controller\Api;

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
use DateTimeImmutable;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
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
        private readonly MessageBusInterface $commandBus,
        private readonly QueryBus $queryBus,
        #[Autowire('%env(YOUTUBE_WATCHLIST_PLAYLIST_ID)%')]
        private readonly string $watchlistPlaylistId,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    #[Route('/watchlist', methods: ['GET'])]
    #[OA\Get(
        summary: 'Get the watchlist',
        description: 'Returns the watch-later videos with their per-video progress status.',
        tags: ['YouTubeProgress'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The watchlist.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'videos', type: 'array', items: new OA\Items(ref: new Model(type: VideoDTO::class))),
                ]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function watchlist(): JsonResponse
    {
        /** @var list<VideoDTO> $videos */
        $videos = $this->queryBus->ask(new GetWatchlist());

        return new JsonResponse([
            'videos' => $this->normalizer->normalize($videos),
        ]);
    }

    #[Route('/sessions', methods: ['GET'])]
    #[OA\Get(
        summary: 'Get the watch sessions',
        description: 'Returns the generated watch sessions, each with its ordered videos and total duration.',
        tags: ['YouTubeProgress'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The watch sessions.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'sessions', type: 'array', items: new OA\Items(ref: new Model(type: WatchSessionDTO::class))),
                ]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function sessions(): JsonResponse
    {
        /** @var list<WatchSessionDTO> $sessions */
        $sessions = $this->queryBus->ask(new GetSessions());

        return new JsonResponse([
            'sessions' => $this->normalizer->normalize($sessions),
        ]);
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

        /** @var list<VideoDTO> $videos */
        $videos = $this->queryBus->ask(new GetWatchlist());
        /** @var list<WatchSessionDTO> $sessions */
        $sessions = $this->queryBus->ask(new GetSessions());

        return new JsonResponse([
            'sessions_count' => count($sessions),
            'videos_count' => count($videos),
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
