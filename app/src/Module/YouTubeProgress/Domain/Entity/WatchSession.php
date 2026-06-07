<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Domain\Entity;

use App\Module\YouTubeProgress\Domain\ValueObject\WatchSessionId;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use DateTimeImmutable;
use InvalidArgumentException;

final class WatchSession
{
    private readonly string $id;
    /** @var YoutubeVideoId[] */
    private readonly array $videoIds;
    private ?string $youtubePlaylistId = null;

    /**
     * @param YoutubeVideoId[] $videoIds
     */
    private function __construct(
        WatchSessionId $id,
        array $videoIds,
        private readonly int $totalDurationSeconds,
        private readonly DateTimeImmutable $createdAt,
    ) {
        if ([] === $videoIds) {
            throw new InvalidArgumentException('WatchSession cannot be empty.');
        }

        if ($totalDurationSeconds < 0) {
            throw new InvalidArgumentException(sprintf('Total duration must be non-negative, %d given.', $totalDurationSeconds));
        }

        $this->id = $id->value;
        // Re-index defensively so external callers can't sneak in an array with
        // gaps or non-sequential keys that would surprise downstream consumers.
        $this->videoIds = array_values($videoIds);
    }

    /**
     * @param YoutubeVideoId[] $videoIds
     */
    public static function create(array $videoIds, int $totalDurationSeconds, DateTimeImmutable $createdAt): self
    {
        return new self(WatchSessionId::generate(), $videoIds, $totalDurationSeconds, $createdAt);
    }

    /**
     * Restore from persistence — bypasses ID generation but keeps invariant checks.
     *
     * @param YoutubeVideoId[] $videoIds
     */
    public static function reconstitute(
        string $id,
        array $videoIds,
        int $totalDurationSeconds,
        DateTimeImmutable $createdAt,
        ?string $youtubePlaylistId,
    ): self {
        $session = new self(WatchSessionId::fromString($id), $videoIds, $totalDurationSeconds, $createdAt);
        $session->youtubePlaylistId = $youtubePlaylistId;

        return $session;
    }

    public function markPushedToYouTube(string $playlistId): void
    {
        // Idempotent: once we record a successful push, a retry must not bump
        // the playlist ID. The first push is the authoritative one — the second
        // would either be a no-op success or a duplicate playlist on YT (caller's
        // problem, not the aggregate's).
        if (null !== $this->youtubePlaylistId) {
            return;
        }

        $this->youtubePlaylistId = $playlistId;
    }

    public function id(): WatchSessionId
    {
        return WatchSessionId::fromString($this->id);
    }

    /** @return YoutubeVideoId[] */
    public function videoIds(): array
    {
        return $this->videoIds;
    }

    public function totalDurationSeconds(): int
    {
        return $this->totalDurationSeconds;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function youtubePlaylistId(): ?string
    {
        return $this->youtubePlaylistId;
    }

    public function isPushedToYouTube(): bool
    {
        return null !== $this->youtubePlaylistId;
    }
}
