<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Domain\ReadModel;

use App\Module\Podcasts\Domain\ValueObject\ListeningProgress;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One episode the source reports the user has listened to, normalized away from
 * whatever shape the provider returned. This is what the
 * PodcastListeningHistoryInterface port hands back (HMAI-323) — a Domain read
 * model, never an Application DTO (HMAI-233).
 *
 * `listenedAt` deserves a word: the chosen source (Spotify) exposes no listen
 * timestamp for podcast episodes — its recently-played endpoint covers tracks
 * only. The adapter therefore reports when the listen was first observed, and
 * upgrades it to a real moment only when the currently-playing endpoint catches
 * the user mid-episode. Consumers must treat it as "no later than", not as the
 * exact moment of listening.
 */
final readonly class ListenedEpisode
{
    public function __construct(
        public string $podcastExternalId,
        public string $podcastTitle,
        public string $episodeExternalId,
        public string $episodeTitle,
        public DateTimeImmutable $listenedAt,
        public ListeningProgress $progress,
        public ?string $publisher = null,
        public ?string $coverUrl = null,
        public ?DateTimeImmutable $publishedAt = null,
        public ?int $durationMs = null,
    ) {
        if ('' === trim($podcastExternalId)) {
            throw new InvalidArgumentException('Listened episode must carry a podcast id.');
        }

        if ('' === trim($episodeExternalId)) {
            throw new InvalidArgumentException('Listened episode must carry an episode id.');
        }

        if ('' === trim($podcastTitle)) {
            throw new InvalidArgumentException('Listened episode must carry a podcast title.');
        }

        if ('' === trim($episodeTitle)) {
            throw new InvalidArgumentException('Listened episode must carry an episode title.');
        }

        if (null !== $durationMs && $durationMs < 0) {
            throw new InvalidArgumentException('Episode duration must not be negative.');
        }
    }
}
