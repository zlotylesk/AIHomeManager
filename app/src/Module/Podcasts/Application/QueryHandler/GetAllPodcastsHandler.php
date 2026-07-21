<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Application\QueryHandler;

use App\Module\Podcasts\Application\DTO\PodcastDTO;
use App\Module\Podcasts\Application\Query\GetAllPodcasts;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetAllPodcastsHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<PodcastDTO>
     */
    public function __invoke(GetAllPodcasts $query): array
    {
        // The counters are aggregated here rather than at serialize time
        // (HMAI-242). Two independent LEFT JOINs would multiply each other's
        // rows, so the listening figures come from correlated subqueries — a
        // show with no episodes and one with no listening both stay one row.
        $sql = <<<'SQL'
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
            ORDER BY last_listened_at IS NULL, last_listened_at DESC, p.title ASC
            SQL;

        $rows = $this->connection->executeQuery($sql)->fetchAllAssociative();

        return array_map(
            static fn (array $row): PodcastDTO => new PodcastDTO(
                id: (string) $row['id'],
                title: (string) $row['title'],
                publisher: null !== $row['publisher'] ? (string) $row['publisher'] : null,
                coverUrl: null !== $row['cover_url'] ? (string) $row['cover_url'] : null,
                description: null !== $row['description'] ? (string) $row['description'] : null,
                episodeCount: (int) $row['episode_count'],
                listenedEpisodeCount: (int) $row['listened_episode_count'],
                lastListenedAt: null !== $row['last_listened_at']
                    ? new DateTimeImmutable((string) $row['last_listened_at'], new DateTimeZone('UTC'))->format(DATE_ATOM)
                    : null,
                createdAt: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC'))->format(DATE_ATOM),
            ),
            $rows,
        );
    }
}
