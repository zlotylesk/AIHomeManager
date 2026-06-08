<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\Handler;

use App\Module\YouTubeProgress\Application\Command\PushSessionToYouTube;
use App\Module\YouTubeProgress\Domain\Port\YouTubePlaylistWriterInterface;
use App\Module\YouTubeProgress\Domain\Repository\WatchSessionRepositoryInterface;
use App\Module\YouTubeProgress\Domain\ValueObject\WatchSessionId;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class PushSessionToYouTubeHandler
{
    public function __construct(
        private WatchSessionRepositoryInterface $sessions,
        private YouTubePlaylistWriterInterface $writer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PushSessionToYouTube $command): void
    {
        $session = $this->sessions->findById(WatchSessionId::fromString($command->sessionId));
        if (null === $session) {
            throw new NotFoundHttpException(sprintf('Session "%s" not found', $command->sessionId));
        }

        if ($session->isPushedToYouTube()) {
            // Double-click in the UI or a retry on an already-synced session.
            // Bail before hitting the YouTube API so we never create a duplicate
            // playlist; the warning surfaces the (likely) frontend race in Graylog.
            $this->logger->warning('PushSessionToYouTube: session already pushed, no-op', [
                'session_id' => $command->sessionId,
                'existing_playlist_id' => $session->youtubePlaylistId(),
            ]);

            return;
        }

        $videoIds = array_values($session->videoIds());

        $name = sprintf('AIHM Session %s', $session->createdAt()->format('Y-m-d H:i'));
        $playlistId = $this->writer->createPlaylist($name);
        $this->writer->addVideosToPlaylist($playlistId, $videoIds);

        // No DB transaction spans the YouTube calls (external, non-transactional).
        // We persist only after both API calls succeed: a mid-flight failure
        // leaves the session unmarked so the user can safely retry (Dev Notes).
        $session->markPushedToYouTube($playlistId);
        $this->sessions->save($session);

        $this->logger->info('Session pushed to YouTube', [
            'session_id' => $command->sessionId,
            'playlist_id' => $playlistId,
            'video_count' => count($videoIds),
        ]);
    }
}
