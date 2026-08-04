<?php

declare(strict_types=1);

namespace App\Module\Series\Application\QueryHandler;

use App\Module\Series\Application\DTO\SeriesDetailDTO;
use App\Module\Series\Application\Query\GetAllSeries;
use App\Module\Series\Application\Service\SeriesRowHydrator;
use App\Shared\Pagination\Page;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetAllSeriesHandler
{
    public function __construct(
        private Connection $connection,
        private SeriesRowHydrator $hydrator,
    ) {
    }

    /** @return Page<SeriesDetailDTO> */
    public function __invoke(GetAllSeries $query): Page
    {
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM series');

        // The window is applied to the SHOWS in a derived table, not to the
        // joined result: one show fans out into a row per episode, so limiting
        // the join would cut a show off mid-season and hand back a series whose
        // last episodes are silently missing.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.id AS series_id, s.title AS series_title, s.created_at AS series_created_at, s.rating_value AS series_rating,
                    s.cover_url AS series_cover_url, s.year AS series_year, s.status AS series_status, s.description AS series_description,
                    se.id AS season_id, se.number AS season_number, se.rating_value AS season_rating,
                    e.id AS episode_id, e.title AS episode_title, e.number AS episode_number, e.rating_value AS episode_rating,
                    e.watched AS episode_watched, e.watched_at AS episode_watched_at
             FROM (SELECT id, created_at FROM series ORDER BY created_at DESC, id ASC LIMIT :limit OFFSET :offset) p
             JOIN series s ON s.id = p.id
             LEFT JOIN series_seasons se ON se.series_id = s.id
             LEFT JOIN series_episodes e ON e.season_id = se.id
             ORDER BY s.created_at DESC, s.id ASC, se.number ASC, e.number ASC',
            ['limit' => $query->page->perPage, 'offset' => $query->page->offset()],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return Page::of($this->hydrator->hydrate($rows), $total, $query->page);
    }
}
