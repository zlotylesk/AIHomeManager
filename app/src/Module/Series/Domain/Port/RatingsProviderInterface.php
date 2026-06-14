<?php

declare(strict_types=1);

namespace App\Module\Series\Domain\Port;

use RuntimeException;

/**
 * Reads the user's Trakt ratings (1–10) for shows, seasons and episodes as plain
 * primitives — the import (HMAI-220) maps them onto the Series aggregate's own
 * ratings, separately from the watched-shows import.
 *
 * Kept array-shaped (no DTOs) so the Domain port stays free of Application types,
 * exactly like WatchedShowsProviderInterface. The adapter lives in Infrastructure
 * and is rate-limited + I/O bound, so callers must run it from a background worker.
 *
 * @phpstan-type ShowRating array{traktId: int, rating: int}
 * @phpstan-type SeasonRating array{traktId: int, seasonNumber: int, rating: int}
 * @phpstan-type EpisodeRating array{traktId: int, seasonNumber: int, episodeNumber: int, rating: int}
 * @phpstan-type TraktRatings array{shows: list<ShowRating>, seasons: list<SeasonRating>, episodes: list<EpisodeRating>}
 */
interface RatingsProviderInterface
{
    /**
     * @return TraktRatings
     *
     * @throws RuntimeException when the tracker is not connected/configured or unreachable
     */
    public function fetchRatings(): array;
}
