<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Application\DTO;

/**
 * One episode in a show's detail view, carrying the furthest progress ever
 * recorded for it rather than the latest session's — an episode listened to
 * across several days should read as "finished", not as whatever the last
 * partial sitting happened to leave behind.
 */
final readonly class PodcastEpisodeDTO
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $publishedAt,
        public ?int $durationMs,
        public bool $listened,
        public int $resumePositionMs,
        public bool $fullyPlayed,
    ) {
    }
}
