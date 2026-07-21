<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Application\DTO;

/**
 * One recorded listen.
 *
 * `listenedAt` carries the caveat the whole module is built around: the source
 * reports no listen timestamp for podcast episodes, so this is when the listen
 * was first observed — "no later than", not the exact moment. Only an episode
 * caught mid-playback carries a real one.
 */
final readonly class PodcastListeningSessionDTO
{
    public function __construct(
        public string $id,
        public string $episodeId,
        public string $episodeTitle,
        public string $listenedAt,
        public int $resumePositionMs,
        public bool $fullyPlayed,
    ) {
    }
}
