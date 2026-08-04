<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\QueryHandler;

use App\Module\YouTubeProgress\Application\DTO\VideoDTO;
use App\Module\YouTubeProgress\Application\Query\GetWatchlist;
use App\Shared\Pagination\Page;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetWatchlistHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return Page<VideoDTO> */
    public function __invoke(GetWatchlist $query): Page
    {
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM videos');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT youtube_id, title, channel, duration_seconds, started_at, watched_at
             FROM videos
             ORDER BY added_to_watchlist_at ASC, youtube_id ASC
             LIMIT :limit OFFSET :offset',
            ['limit' => $query->page->perPage, 'offset' => $query->page->offset()],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return Page::of(array_map(VideoDTO::fromRow(...), $rows), $total, $query->page);
    }
}
