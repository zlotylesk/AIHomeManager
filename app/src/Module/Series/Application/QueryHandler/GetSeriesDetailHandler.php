<?php

declare(strict_types=1);

namespace App\Module\Series\Application\QueryHandler;

use App\Module\Series\Application\DTO\EpisodeDTO;
use App\Module\Series\Application\DTO\SeasonDTO;
use App\Module\Series\Application\DTO\SeriesDetailDTO;
use App\Module\Series\Application\Query\GetSeriesDetail;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetSeriesDetailHandler
{
    public function __construct(
        private Connection $connection,
    ) {}

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

        $first = $rows[0];
        $seasonMap = [];
        $episodeMap = [];

        foreach ($rows as $row) {
            $seasonId = $row['season_id'];

            if ($seasonId !== null && !isset($seasonMap[$seasonId])) {
                $seasonMap[$seasonId] = ['id' => $seasonId, 'number' => (int) $row['season_number']];
                $episodeMap[$seasonId] = [];
            }

            if ($row['episode_id'] !== null) {
                $episodeMap[$seasonId][] = new EpisodeDTO(
                    id: $row['episode_id'],
                    title: $row['episode_title'],
                    rating: $row['episode_rating'] !== null ? (int) $row['episode_rating'] : null,
                );
            }
        }

        $seasonDTOs = [];
        foreach ($seasonMap as $seasonId => $season) {
            $seasonDTOs[] = new SeasonDTO(
                id: $season['id'],
                number: $season['number'],
                episodes: $episodeMap[$seasonId] ?? [],
            );
        }

        return new SeriesDetailDTO(
            id: $first['series_id'],
            title: $first['series_title'],
            createdAt: $first['series_created_at'],
            seasons: $seasonDTOs,
        );
    }
}
