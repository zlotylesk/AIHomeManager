<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Application\DTO;

/**
 * A show with its episodes and the listening behind it.
 *
 * Composes PodcastDTO rather than restating its fields, so the list and the
 * detail cannot drift apart; the normalizer delegates to the PodcastDTO
 * normalizer for that half (the BookDetailDTO precedent).
 */
final readonly class PodcastDetailDTO
{
    /**
     * @param list<PodcastEpisodeDTO>          $episodes
     * @param list<PodcastListeningSessionDTO> $sessions
     */
    public function __construct(
        public PodcastDTO $podcast,
        public array $episodes,
        public array $sessions,
    ) {
    }
}
