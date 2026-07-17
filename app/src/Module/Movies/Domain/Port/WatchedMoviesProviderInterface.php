<?php

declare(strict_types=1);

namespace App\Module\Movies\Domain\Port;

use RuntimeException;

/**
 * Reads the user's watched movies from an upstream tracker (Trakt) as a list of
 * plain primitives — the import (HMAI-290) maps this onto the Movie aggregate.
 *
 * Kept array-shaped (no DTOs) so the Domain port stays free of Application types,
 * exactly like the Series WatchedShowsProviderInterface. A movie is flat (no
 * season/episode tree), so each entry is a single watched film. The adapter lives
 * in Infrastructure and is rate-limited + I/O bound, so callers must run it from a
 * background worker, never inline in a request.
 *
 * @phpstan-type WatchedMovie array{traktId: int, title: string, year: int|null, lastWatchedAt: string|null}
 */
interface WatchedMoviesProviderInterface
{
    /**
     * @return list<WatchedMovie>
     *
     * @throws RuntimeException when the tracker is not connected/configured or unreachable
     */
    public function fetchWatchedMovies(): array;
}
