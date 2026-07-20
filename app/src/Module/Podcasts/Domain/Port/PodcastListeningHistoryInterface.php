<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Domain\Port;

use App\Module\Podcasts\Domain\ReadModel\ListenedEpisode;
use RuntimeException;

/**
 * The module's single window onto the outside world: whatever the user has been
 * listening to, normalized into Domain read models (HMAI-233 — a port returns a
 * Domain\ReadModel, never an Application DTO). Mirrors the Music
 * MusicListeningHistoryInterface → RecentTrack shape.
 *
 * Implementations are expected to be idempotent readers — the session-logging
 * layer (HMAI-324) deduplicates what comes back, so returning the same episode
 * on consecutive polls is normal and harmless.
 */
interface PodcastListeningHistoryInterface
{
    /**
     * Every episode the source currently reports as listened to (started or
     * finished), newest information first where the source allows ordering.
     *
     * @return list<ListenedEpisode>
     *
     * @throws RuntimeException when the source is unreachable or not connected
     */
    public function fetchListenedEpisodes(): array;
}
