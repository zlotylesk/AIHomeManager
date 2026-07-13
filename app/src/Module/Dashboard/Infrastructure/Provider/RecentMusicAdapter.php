<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Infrastructure\Provider;

use App\Module\Dashboard\Domain\ReadModel\RecentTrack;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Reads the latest listening activity straight from the
 * `music_listening_sessions` table via DBAL — no import of any Music class,
 * keeping the Dashboard ← Music boundary deptrac-clean.
 */
final readonly class RecentMusicAdapter
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return RecentTrack[]
     */
    public function recentTracks(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT artist, title, played_at, source FROM music_listening_sessions '
                .'ORDER BY played_at DESC LIMIT %d',
                max(0, $limit),
            ),
        );

        return array_map(
            static fn (array $row): RecentTrack => new RecentTrack(
                (string) $row['artist'],
                (string) $row['title'],
                new DateTimeImmutable((string) $row['played_at']),
                (string) $row['source'],
            ),
            $rows,
        );
    }
}
