<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\QueryHandler;

use App\Module\YouTubeProgress\Application\DTO\VideoDTO;
use App\Module\YouTubeProgress\Application\DTO\WatchSessionDTO;
use App\Module\YouTubeProgress\Application\Query\GetSessions;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetSessionsHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<WatchSessionDTO> */
    public function __invoke(GetSessions $query): array
    {
        $sessions = $this->connection->fetchAllAssociative(
            'SELECT id, total_duration_seconds, created_at, youtube_playlist_id
             FROM watch_sessions
             ORDER BY created_at DESC, id ASC',
        );

        return array_map($this->toDTO(...), $sessions);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function toDTO(array $session): WatchSessionDTO
    {
        $id = (string) $session['id'];

        // LEFT JOIN: a session may reference a video no longer in the watchlist;
        // such rows come back with NULL video columns and become a placeholder.
        $videoRows = $this->connection->fetchAllAssociative(
            'SELECT sv.youtube_video_id, v.youtube_id, v.title, v.channel, v.duration_seconds, v.started_at, v.watched_at
             FROM watch_session_videos sv
             LEFT JOIN videos v ON v.youtube_id = sv.youtube_video_id
             WHERE sv.watch_session_id = ?
             ORDER BY sv.position ASC',
            [$id],
        );

        $videos = array_map(
            static fn (array $row): VideoDTO => null === $row['youtube_id']
                ? VideoDTO::missing((string) $row['youtube_video_id'])
                : VideoDTO::fromRow($row),
            $videoRows,
        );

        return new WatchSessionDTO(
            id: $id,
            createdAt: new DateTimeImmutable((string) $session['created_at'])->format(DateTimeInterface::ATOM),
            totalDurationSeconds: (int) $session['total_duration_seconds'],
            youtubePlaylistId: null === $session['youtube_playlist_id'] ? null : (string) $session['youtube_playlist_id'],
            videos: $videos,
        );
    }
}
