<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\DTO;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Read model for a single video, used both in the watchlist and nested inside a
 * watch session. Every field except the id is nullable so the session view can
 * carry a placeholder for a referenced video that is no longer in the watchlist
 * (the watchlist read always populates them in full).
 */
final readonly class VideoDTO
{
    public function __construct(
        public string $youtubeId,
        public ?string $title,
        public ?string $channel,
        public ?int $durationSeconds,
        public ?string $status,
        public ?string $startedAt,
        public ?string $watchedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row a video row with youtube_id, title,
     *                                  channel, duration_seconds, started_at,
     *                                  watched_at
     */
    public static function fromRow(array $row): self
    {
        $startedAt = null !== $row['started_at'] ? (string) $row['started_at'] : null;
        $watchedAt = null !== $row['watched_at'] ? (string) $row['watched_at'] : null;

        return new self(
            youtubeId: (string) $row['youtube_id'],
            title: (string) $row['title'],
            channel: (string) $row['channel'],
            durationSeconds: (int) $row['duration_seconds'],
            status: self::status($startedAt, $watchedAt),
            startedAt: null !== $startedAt ? self::atom($startedAt) : null,
            watchedAt: null !== $watchedAt ? self::atom($watchedAt) : null,
        );
    }

    /**
     * Placeholder for a session video whose source row is gone from the watchlist.
     */
    public static function missing(string $youtubeId): self
    {
        return new self($youtubeId, null, null, null, null, null, null);
    }

    private static function status(?string $startedAt, ?string $watchedAt): string
    {
        if (null !== $watchedAt) {
            return 'watched';
        }

        if (null !== $startedAt) {
            return 'started';
        }

        return 'split-pool';
    }

    private static function atom(string $dbDateTime): string
    {
        return new DateTimeImmutable($dbDateTime)->format(DateTimeInterface::ATOM);
    }
}
