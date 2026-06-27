<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\QueryHandler;

use App\Module\YouTubeProgress\Application\DTO\VideoDTO;
use App\Module\YouTubeProgress\Application\Query\GetWatchlist;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetWatchlistHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<VideoDTO> */
    public function __invoke(GetWatchlist $query): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT youtube_id, title, channel, duration_seconds, started_at, watched_at
             FROM videos
             ORDER BY added_to_watchlist_at ASC, youtube_id ASC',
        );

        return array_map(VideoDTO::fromRow(...), $rows);
    }
}
