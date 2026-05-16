<?php

declare(strict_types=1);

namespace App\Module\Series\Infrastructure\Messenger;

use App\Module\Series\Domain\Event\EpisodeRated;
use Doctrine\DBAL\Connection;
use Redis;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class EpisodeRatedHandler
{
    public function __construct(
        private Connection $connection,
        private Redis $redis,
    ) {
    }

    public function __invoke(EpisodeRated $event): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT
                AVG(CASE WHEN e.season_id = :seasonId THEN e.rating_value END) AS season_avg,
                AVG(e.rating_value) AS series_avg
             FROM series_episodes e
             JOIN series_seasons s ON e.season_id = s.id
             WHERE s.series_id = :seriesId',
            [
                'seriesId' => $event->seriesId,
                'seasonId' => $event->seasonId,
            ]
        );

        if (false === $row) {
            return;
        }

        $ttl = 3600;

        if (null !== $row['season_avg']) {
            $this->redis->setex("season:avg:{$event->seasonId}", $ttl, (string) round((float) $row['season_avg'], 2));
        }

        if (null !== $row['series_avg']) {
            $this->redis->setex("series:avg:{$event->seriesId}", $ttl, (string) round((float) $row['series_avg'], 2));
        }
    }
}
