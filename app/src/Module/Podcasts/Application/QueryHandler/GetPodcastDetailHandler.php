<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Application\QueryHandler;

use App\Module\Podcasts\Application\DTO\PodcastDetailDTO;
use App\Module\Podcasts\Application\DTO\PodcastDTO;
use App\Module\Podcasts\Application\DTO\PodcastEpisodeDTO;
use App\Module\Podcasts\Application\DTO\PodcastListeningSessionDTO;
use App\Module\Podcasts\Application\Query\GetPodcastDetail;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetPodcastDetailHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(GetPodcastDetail $query): ?PodcastDetailDTO
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    p.id,
                    p.title,
                    p.publisher,
                    p.cover_url,
                    p.description,
                    p.created_at,
                    (SELECT COUNT(*) FROM podcast_episodes e WHERE e.podcast_id = p.id) AS episode_count,
                    (SELECT COUNT(DISTINCT s.episode_id) FROM podcast_listening_sessions s WHERE s.podcast_id = p.id) AS listened_episode_count,
                    (SELECT MAX(s.listened_at) FROM podcast_listening_sessions s WHERE s.podcast_id = p.id) AS last_listened_at
                FROM podcasts p
                WHERE p.id = :id
                SQL,
            ['id' => $query->id],
        );

        if (false === $row) {
            return null;
        }

        return new PodcastDetailDTO(
            podcast: $this->podcast($row),
            episodes: $this->episodes($query->id),
            sessions: $this->sessions($query->id),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function podcast(array $row): PodcastDTO
    {
        return new PodcastDTO(
            id: (string) $row['id'],
            title: (string) $row['title'],
            publisher: null !== $row['publisher'] ? (string) $row['publisher'] : null,
            coverUrl: null !== $row['cover_url'] ? (string) $row['cover_url'] : null,
            description: null !== $row['description'] ? (string) $row['description'] : null,
            episodeCount: (int) $row['episode_count'],
            listenedEpisodeCount: (int) $row['listened_episode_count'],
            lastListenedAt: null !== $row['last_listened_at'] ? $this->atom($row['last_listened_at']) : null,
            createdAt: $this->atom($row['created_at']),
        );
    }

    /**
     * Episodes carry the FURTHEST progress ever recorded, aggregated across
     * every session — an episode listened to over three evenings should read as
     * finished, not as whatever the last partial sitting left behind.
     *
     * @return list<PodcastEpisodeDTO>
     */
    private function episodes(string $podcastId): array
    {
        $rows = $this->connection->executeQuery(
            <<<'SQL'
                SELECT
                    e.id,
                    e.title,
                    e.published_at,
                    e.duration_ms,
                    COALESCE(MAX(s.resume_position_ms), 0) AS resume_position_ms,
                    COALESCE(MAX(s.fully_played), 0) AS fully_played,
                    COUNT(s.id) AS session_count
                FROM podcast_episodes e
                LEFT JOIN podcast_listening_sessions s ON s.episode_id = e.id
                WHERE e.podcast_id = :podcastId
                GROUP BY e.id, e.title, e.published_at, e.duration_ms, e.created_at
                ORDER BY e.published_at IS NULL, e.published_at DESC, e.created_at DESC
                SQL,
            ['podcastId' => $podcastId],
        )->fetchAllAssociative();

        return array_map(
            fn (array $row): PodcastEpisodeDTO => new PodcastEpisodeDTO(
                id: (string) $row['id'],
                title: (string) $row['title'],
                publishedAt: null !== $row['published_at'] ? $this->atom($row['published_at']) : null,
                durationMs: null !== $row['duration_ms'] ? (int) $row['duration_ms'] : null,
                listened: (int) $row['session_count'] > 0,
                resumePositionMs: (int) $row['resume_position_ms'],
                fullyPlayed: (bool) $row['fully_played'],
            ),
            $rows,
        );
    }

    /**
     * @return list<PodcastListeningSessionDTO>
     */
    private function sessions(string $podcastId): array
    {
        $rows = $this->connection->executeQuery(
            <<<'SQL'
                SELECT s.id, s.episode_id, e.title AS episode_title, s.listened_at, s.resume_position_ms, s.fully_played
                FROM podcast_listening_sessions s
                INNER JOIN podcast_episodes e ON e.id = s.episode_id
                WHERE s.podcast_id = :podcastId
                ORDER BY s.listened_at DESC
                SQL,
            ['podcastId' => $podcastId],
        )->fetchAllAssociative();

        return array_map(
            fn (array $row): PodcastListeningSessionDTO => new PodcastListeningSessionDTO(
                id: (string) $row['id'],
                episodeId: (string) $row['episode_id'],
                episodeTitle: (string) $row['episode_title'],
                listenedAt: $this->atom($row['listened_at']),
                resumePositionMs: (int) $row['resume_position_ms'],
                fullyPlayed: (bool) $row['fully_played'],
            ),
            $rows,
        );
    }

    private function atom(mixed $value): string
    {
        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
