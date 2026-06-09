<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\YouTubeProgress\Application\Command\MarkVideoStarted;
use App\Module\YouTubeProgress\Application\Command\MarkVideoWatched;
use App\Module\YouTubeProgress\Application\Command\PushSessionToYouTube;
use App\Module\YouTubeProgress\Application\Command\RegenerateSessions;
use App\Module\YouTubeProgress\Application\Command\SyncWatchlist;
use App\Module\YouTubeProgress\Domain\Entity\Video;
use App\Module\YouTubeProgress\Domain\Entity\WatchSession;
use App\Module\YouTubeProgress\Domain\Repository\VideoRepositoryInterface;
use App\Module\YouTubeProgress\Domain\Repository\WatchSessionRepositoryInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read + command API for the /youtube-progress panel (T13).
 *
 * Reads go straight through the Domain repositories — the module has no
 * dedicated query layer, and the single-user panel never needs more than the
 * full watchlist / session list. Writes dispatch the existing command handlers
 * on the synchronous command bus; not-found and idempotency invariants live in
 * those handlers, so a NotFoundHttpException thrown there is unwrapped by
 * ApiExceptionListener back into a 404 here.
 */
#[Route('/api/youtube-progress')]
final class YouTubeProgressController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly VideoRepositoryInterface $videos,
        private readonly WatchSessionRepositoryInterface $sessions,
        #[Autowire('%env(YOUTUBE_WATCHLIST_PLAYLIST_ID)%')]
        private readonly string $watchlistPlaylistId,
    ) {
    }

    #[Route('/watchlist', methods: ['GET'])]
    public function watchlist(): JsonResponse
    {
        return new JsonResponse([
            'videos' => array_map($this->serializeVideo(...), $this->videos->findAll()),
        ]);
    }

    #[Route('/sessions', methods: ['GET'])]
    public function sessions(): JsonResponse
    {
        // A session aggregate carries only ordered video IDs, so resolve their
        // metadata once into a lookup map rather than N queries per session.
        $videoMap = [];
        foreach ($this->videos->findAll() as $video) {
            $videoMap[$video->id()->value()] = $video;
        }

        $sessions = array_map(
            fn (WatchSession $session): array => $this->serializeSession($session, $videoMap),
            $this->sessions->findAll(),
        );

        return new JsonResponse(['sessions' => $sessions]);
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

        // Pull the playlist into the local split pool, then rebuild the watch
        // sessions off the refreshed pool. Both are synchronous handlers, so the
        // counts below reflect the post-sync state.
        $this->commandBus->dispatch(new SyncWatchlist($this->watchlistPlaylistId));
        $this->commandBus->dispatch(new RegenerateSessions());

        return new JsonResponse([
            'sessions_count' => count($this->sessions->findAll()),
            'videos_count' => count($this->videos->findAll()),
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
    private function serializeVideo(Video $video): array
    {
        return [
            'youtubeId' => $video->id()->value(),
            'title' => $video->title(),
            'channel' => $video->channel()->value(),
            'durationSeconds' => $video->duration()->toSeconds(),
            'status' => $this->videoStatus($video),
            'startedAt' => $video->startedAt()?->format(DateTimeInterface::ATOM),
            'watchedAt' => $video->watchedAt()?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, Video> $videoMap
     *
     * @return array<string, mixed>
     */
    private function serializeSession(WatchSession $session, array $videoMap): array
    {
        $videos = [];
        foreach ($session->videoIds() as $videoId) {
            $video = $videoMap[$videoId->value()] ?? null;
            $videos[] = null !== $video
                ? $this->serializeVideo($video)
                : ['youtubeId' => $videoId->value(), 'title' => null, 'channel' => null, 'durationSeconds' => null, 'status' => null, 'startedAt' => null, 'watchedAt' => null];
        }

        return [
            'id' => $session->id()->value,
            'createdAt' => $session->createdAt()->format(DateTimeInterface::ATOM),
            'totalDurationSeconds' => $session->totalDurationSeconds(),
            'youtubePlaylistId' => $session->youtubePlaylistId(),
            'videos' => $videos,
        ];
    }

    private function videoStatus(Video $video): string
    {
        if (null !== $video->watchedAt()) {
            return 'watched';
        }

        if (null !== $video->startedAt()) {
            return 'started';
        }

        return 'split-pool';
    }
}
