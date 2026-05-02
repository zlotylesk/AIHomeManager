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
        $seasonAvg = $this->connection->fetchOne(
            'SELECT AVG(rating_value) FROM series_episodes WHERE season_id = :seasonId AND rating_value IS NOT NULL',
            ['seasonId' => $event->seasonId]
        );

        $seriesAvg = $this->connection->fetchOne(
            'SELECT AVG(e.rating_value)
             FROM series_episodes e
             JOIN series_seasons s ON e.season_id = s.id
             WHERE s.series_id = :seriesId AND e.rating_value IS NOT NULL',
            ['seriesId' => $event->seriesId]
        );

        $ttl = 3600;

        if (false !== $seasonAvg && null !== $seasonAvg) {
            $this->redis->setex("season:avg:{$event->seasonId}", $ttl, (string) round((float) $seasonAvg, 2));
        }

        if (false !== $seriesAvg && null !== $seriesAvg) {
            $this->redis->setex("series:avg:{$event->seriesId}", $ttl, (string) round((float) $seriesAvg, 2));
        }
    }
}
