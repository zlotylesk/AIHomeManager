<?php

declare(strict_types=1);

namespace App\Module\Series\Application\QueryHandler;

use App\Module\Series\Application\DTO\SeriesDetailDTO;
use App\Module\Series\Application\Query\GetSeriesDetail;
use App\Module\Series\Application\Service\SeriesRowHydrator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetSeriesDetailHandler
{
    public function __construct(
        private Connection $connection,
        private SeriesRowHydrator $hydrator,
    ) {
    }

    public function __invoke(GetSeriesDetail $query): ?SeriesDetailDTO
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.id AS series_id, s.title AS series_title, s.created_at AS series_created_at,
                    se.id AS season_id, se.number AS season_number,
                    e.id AS episode_id, e.title AS episode_title, e.rating_value AS episode_rating
             FROM series s
             LEFT JOIN series_seasons se ON se.series_id = s.id
             LEFT JOIN series_episodes e ON e.season_id = se.id
             WHERE s.id = :id
             ORDER BY se.number ASC, e.id ASC',
            ['id' => $query->seriesId]
        );

        if (empty($rows)) {
            return null;
        }

        return $this->hydrator->hydrate($rows)[0] ?? null;
    }
}
