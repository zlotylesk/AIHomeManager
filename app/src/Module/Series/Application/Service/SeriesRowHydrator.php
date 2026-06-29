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
 * separate from `averageRating`, the mean this hydrator derives from
 * `episode_rating` per season and per show (HMAI-242). The watched/episode
 * counters are computed here too, so the serializer is a pure field map.
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
        /** @var array<string, array{id: string, title: string, createdAt: string, rating: int|null, coverUrl: string|null, year: int|null, status: string|null, description: string|null}> $seriesMap */
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
                    'coverUrl' => isset($row['series_cover_url']) ? (string) $row['series_cover_url'] : null,
                    'year' => isset($row['series_year']) ? (int) $row['series_year'] : null,
                    'status' => isset($row['series_status']) ? (string) $row['series_status'] : null,
                    'description' => isset($row['series_description']) ? (string) $row['series_description'] : null,
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
            /** @var list<EpisodeDTO> $allEpisodes */
            $allEpisodes = [];
            foreach ($seasonMap[$seriesId] ?? [] as $seasonId => $season) {
                $episodes = $episodeMap[$seasonId] ?? [];
                $allEpisodes = array_merge($allEpisodes, $episodes);
                $seasonDTOs[] = new SeasonDTO(
                    id: $season['id'],
                    number: $season['number'],
                    episodes: $episodes,
                    rating: $season['rating'],
                    averageRating: $this->averageRating($episodes),
                    watchedCount: $this->watchedCount($episodes),
                    episodeCount: count($episodes),
                );
            }
            $result[] = new SeriesDetailDTO(
                id: $s['id'],
                title: $s['title'],
                createdAt: $s['createdAt'],
                seasons: $seasonDTOs,
                rating: $s['rating'],
                coverUrl: $s['coverUrl'],
                year: $s['year'],
                status: $s['status'],
                description: $s['description'],
                averageRating: $this->averageRating($allEpisodes),
                watchedCount: $this->watchedCount($allEpisodes),
                episodeCount: count($allEpisodes),
            );
        }

        return $result;
    }

    /**
     * Mean of the episodes' own ratings, rounded to 2 decimals — `null` when none
     * are rated (a partially-rated show still averages only its rated episodes).
     * Disjoint from the user's manual `rating` on the show/season.
     *
     * @param list<EpisodeDTO> $episodes
     */
    private function averageRating(array $episodes): ?float
    {
        $ratings = [];
        foreach ($episodes as $episode) {
            if (null !== $episode->rating) {
                $ratings[] = $episode->rating;
            }
        }

        if ([] === $ratings) {
            return null;
        }

        return round(array_sum($ratings) / count($ratings), 2);
    }

    /** @param list<EpisodeDTO> $episodes */
    private function watchedCount(array $episodes): int
    {
        return count(array_filter($episodes, static fn (EpisodeDTO $episode): bool => $episode->watched));
    }
}
