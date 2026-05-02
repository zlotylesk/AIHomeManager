<?php

declare(strict_types=1);

namespace App\Module\Series\Application\QueryHandler;

use App\Module\Series\Application\DTO\EpisodeDTO;
use App\Module\Series\Application\DTO\SeasonDTO;
use App\Module\Series\Application\DTO\SeriesDetailDTO;
use App\Module\Series\Application\Query\GetAllSeries;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetAllSeriesHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /** @return SeriesDetailDTO[] */
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

        return $this->hydrate($rows);
    }

    /** @return SeriesDetailDTO[] */
    private function hydrate(array $rows): array
    {
        $seriesMap = [];
        $seasonMap = [];
        $episodeMap = [];

        foreach ($rows as $row) {
            $seriesId = $row['series_id'];
            $seasonId = $row['season_id'];

            if (!isset($seriesMap[$seriesId])) {
                $seriesMap[$seriesId] = ['id' => $seriesId, 'title' => $row['series_title'], 'createdAt' => $row['series_created_at']];
                $seasonMap[$seriesId] = [];
            }

            if (null !== $seasonId && !isset($seasonMap[$seriesId][$seasonId])) {
                $seasonMap[$seriesId][$seasonId] = ['id' => $seasonId, 'number' => (int) $row['season_number']];
                $episodeMap[$seasonId] = [];
            }

            if (null !== $row['episode_id']) {
                $episodeMap[$seasonId][] = new EpisodeDTO(
                    id: $row['episode_id'],
                    title: $row['episode_title'],
                    rating: null !== $row['episode_rating'] ? (int) $row['episode_rating'] : null,
                );
            }
        }

        $result = [];
        foreach ($seriesMap as $seriesId => $s) {
            $seasonDTOs = [];
            foreach ($seasonMap[$seriesId] ?? [] as $seasonId => $season) {
                $seasonDTOs[] = new SeasonDTO(
                    id: $season['id'],
                    number: $season['number'],
                    episodes: $episodeMap[$seasonId] ?? [],
                );
            }
            $result[] = new SeriesDetailDTO(
                id: $s['id'],
                title: $s['title'],
                createdAt: $s['createdAt'],
                seasons: $seasonDTOs,
            );
        }

        return $result;
    }
}
