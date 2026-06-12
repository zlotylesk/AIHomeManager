<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Service;

use App\Module\Series\Application\DTO\EpisodeDTO;
use App\Module\Series\Application\DTO\SeasonDTO;
use App\Module\Series\Application\DTO\SeriesDetailDTO;

/**
 * Hydrates the canonical series/season/episode JOIN result-set into a list of
 * `SeriesDetailDTO`. Shared by `GetAllSeriesHandler` and `GetSeriesDetailHandler`
 * to keep the row → DTO mapping in a single place.
 *
 * Expected row shape (column aliases come from the SELECT list in both handlers):
 *   series_id, series_title, series_created_at, series_rating,
 *   season_id, season_number, season_rating,
 *   episode_id, episode_title, episode_rating
 *
 * `series_rating` / `season_rating` are the user's own (manual) scores — kept
 * separate from the average the controller derives from `episode_rating`.
 */
final readonly class SeriesRowHydrator
{
    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<SeriesDetailDTO>
     */
    public function hydrate(array $rows): array
    {
        /** @var array<string, array{id: string, title: string, createdAt: string, rating: int|null}> $seriesMap */
        $seriesMap = [];
        /** @var array<string, array<string, array{id: string, number: int, rating: int|null}>> $seasonMap */
        $seasonMap = [];
        /** @var array<string, list<EpisodeDTO>> $episodeMap */
        $episodeMap = [];

        foreach ($rows as $row) {
            $seriesId = (string) $row['series_id'];
            $seasonId = null !== $row['season_id'] ? (string) $row['season_id'] : null;

            if (!isset($seriesMap[$seriesId])) {
                $seriesMap[$seriesId] = [
                    'id' => $seriesId,
                    'title' => (string) $row['series_title'],
                    'createdAt' => (string) $row['series_created_at'],
                    'rating' => isset($row['series_rating']) ? (int) $row['series_rating'] : null,
                ];
                $seasonMap[$seriesId] = [];
            }

            if (null !== $seasonId && !isset($seasonMap[$seriesId][$seasonId])) {
                $seasonMap[$seriesId][$seasonId] = [
                    'id' => $seasonId,
                    'number' => (int) $row['season_number'],
                    'rating' => isset($row['season_rating']) ? (int) $row['season_rating'] : null,
                ];
                $episodeMap[$seasonId] = [];
            }

            if (null !== $seasonId && null !== $row['episode_id']) {
                $episodeMap[$seasonId][] = new EpisodeDTO(
                    id: (string) $row['episode_id'],
                    title: (string) $row['episode_title'],
                    number: (int) $row['episode_number'],
                    rating: null !== $row['episode_rating'] ? (int) $row['episode_rating'] : null,
                    watched: (bool) ($row['episode_watched'] ?? false),
                    watchedAt: isset($row['episode_watched_at']) ? (string) $row['episode_watched_at'] : null,
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
                    rating: $season['rating'],
                );
            }
            $result[] = new SeriesDetailDTO(
                id: $s['id'],
                title: $s['title'],
                createdAt: $s['createdAt'],
                seasons: $seasonDTOs,
                rating: $s['rating'],
            );
        }

        return $result;
    }
}
