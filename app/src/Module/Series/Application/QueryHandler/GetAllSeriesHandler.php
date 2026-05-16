<?php

declare(strict_types=1);

namespace App\Module\Series\Application\QueryHandler;

use App\Module\Series\Application\DTO\SeriesDetailDTO;
use App\Module\Series\Application\Query\GetAllSeries;
use App\Module\Series\Application\Service\SeriesRowHydrator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetAllSeriesHandler
{
    public function __construct(
        private Connection $connection,
        private SeriesRowHydrator $hydrator,
    ) {
    }

    /** @return list<SeriesDetailDTO> */
    public function __invoke(GetAllSeries $query): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.id AS series_id, s.title AS series_title, s.created_at AS series_created_at,
                    se.id AS season_id, se.number AS season_number,
                    e.id AS episode_id, e.title AS episode_title, e.rating_value AS episode_rating
             FROM series s
             LEFT JOIN series_seasons se ON se.series_id = s.id
             LEFT JOIN series_episodes e ON e.season_id = se.id
             ORDER BY s.created_at DESC, se.number ASC, e.id ASC'
        );

        return $this->hydrator->hydrate($rows);
    }
}
