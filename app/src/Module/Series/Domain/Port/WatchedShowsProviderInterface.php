<?php

declare(strict_types=1);

namespace App\Module\Series\Domain\Port;

use RuntimeException;

/**
 * Reads the user's watched shows from an upstream tracker (Trakt) as a tree of
 * plain primitives — the import (HMAI-183) maps this onto the Series aggregate.
 *
 * Kept array-shaped (no DTOs) so the Domain port stays free of Application types;
 * the adapter lives in Infrastructure and is rate-limited + I/O bound, so callers
 * must run it from a background worker, never inline in a request.
 *
 * @phpstan-type WatchedEpisode array{number: int, lastWatchedAt: string|null}
 * @phpstan-type WatchedSeason array{number: int, episodes: list<WatchedEpisode>}
 * @phpstan-type WatchedShow array{traktId: int, title: string, year: int|null, seasons: list<WatchedSeason>}
 */
interface WatchedShowsProviderInterface
{
    /**
     * @return list<WatchedShow>
     *
     * @throws RuntimeException when the tracker is not connected/configured or unreachable
     */
    public function fetchWatchedShows(): array;
}
