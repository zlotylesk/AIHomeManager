<?php

declare(strict_types=1);

namespace App\Controller;

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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

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
#[Route('/api/youtube-progress')]
final class YouTubeProgressController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly QueryBus $queryBus,
        #[Autowire('%env(YOUTUBE_WATCHLIST_PLAYLIST_ID)%')]
        private readonly string $watchlistPlaylistId,
    ) {
    }

    #[Route('/watchlist', methods: ['GET'])]
    public function watchlist(): JsonResponse
    {
        /** @var list<VideoDTO> $videos */
        $videos = $this->queryBus->ask(new GetWatchlist());

        return new JsonResponse([
            'videos' => array_map($this->serializeVideo(...), $videos),
        ]);
    }

    #[Route('/sessions', methods: ['GET'])]
    public function sessions(): JsonResponse
    {
        /** @var list<WatchSessionDTO> $sessions */
        $sessions = $this->queryBus->ask(new GetSessions());

        return new JsonResponse([
            'sessions' => array_map($this->serializeSession(...), $sessions),
        ]);
    }

    #[Route('/sync', methods: ['POST'])]
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
    public function startVideo(string $id): JsonResponse
    {
        $this->commandBus->dispatch(new MarkVideoStarted($id, new DateTimeImmutable()));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/videos/{id}/watched', methods: ['POST'], requirements: ['id' => '[A-Za-z0-9_-]+'])]
    public function watchedVideo(string $id): JsonResponse
    {
        $this->commandBus->dispatch(new MarkVideoWatched($id, new DateTimeImmutable()));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/sessions/{id}/push-to-youtube', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function pushSession(string $id): JsonResponse
    {
        $this->commandBus->dispatch(new PushSessionToYouTube($id));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeVideo(VideoDTO $video): array
    {
        return [
            'youtubeId' => $video->youtubeId,
            'title' => $video->title,
            'channel' => $video->channel,
            'durationSeconds' => $video->durationSeconds,
            'status' => $video->status,
            'startedAt' => $video->startedAt,
            'watchedAt' => $video->watchedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSession(WatchSessionDTO $session): array
    {
        return [
            'id' => $session->id,
            'createdAt' => $session->createdAt,
            'totalDurationSeconds' => $session->totalDurationSeconds,
            'youtubePlaylistId' => $session->youtubePlaylistId,
            'videos' => array_map($this->serializeVideo(...), $session->videos),
        ];
    }
}
