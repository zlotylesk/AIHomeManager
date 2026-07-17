<?php

declare(strict_types=1);

namespace App\Module\Movies\Domain\Port;

use RuntimeException;

/**
 * Reads the user's Trakt ratings (1–10) for movies as plain primitives — the
 * import (HMAI-290) maps them onto the Movie aggregate's own rating, separately
 * from the watched-movies import.
 *
 * Kept array-shaped (no DTOs) so the Domain port stays free of Application types,
 * exactly like the Series RatingsProviderInterface. The adapter lives in
 * Infrastructure and is rate-limited + I/O bound, so callers must run it from a
 * background worker.
 *
 * @phpstan-type MovieRating array{traktId: int, rating: int}
 */
interface MovieRatingsProviderInterface
{
    /**
     * @return list<MovieRating>
     *
     * @throws RuntimeException when the tracker is not connected/configured or unreachable
     */
    public function fetchMovieRatings(): array;
}
