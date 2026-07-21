<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Application\DTO;

/**
 * Read model for a followed show, as the list renders it. The counters are what
 * makes the list useful at a glance — a catalog entry with no listening behind
 * it says nothing — and they are computed in the read query rather than at
 * serialize time (the HMAI-242 rule the Series read model settled).
 */
final readonly class PodcastDTO
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $publisher,
        public ?string $coverUrl,
        public ?string $description,
        public int $episodeCount,
        public int $listenedEpisodeCount,
        public ?string $lastListenedAt,
        public string $createdAt,
    ) {
    }
}
