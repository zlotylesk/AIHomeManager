<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Domain\Entity;

use App\Module\YouTubeProgress\Domain\ValueObject\ChannelName;
use App\Module\YouTubeProgress\Domain\ValueObject\VideoDuration;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use DateTimeImmutable;

final class Video
{
    private function __construct(
        private readonly YoutubeVideoId $id,
        private string $title,
        private readonly ChannelName $channel,
        private VideoDuration $duration,
        private readonly DateTimeImmutable $addedAt,
        private ?DateTimeImmutable $startedAt = null,
        private ?DateTimeImmutable $watchedAt = null,
    ) {
    }

    public static function fromYouTube(
        YoutubeVideoId $id,
        string $title,
        ChannelName $channel,
        VideoDuration $duration,
        DateTimeImmutable $addedAt,
    ): self {
        return new self($id, $title, $channel, $duration, $addedAt);
    }

    public function markStarted(DateTimeImmutable $at): void
    {
        // Idempotent: once a video leaves the split pool (either started or
        // watched), markStarted is a no-op. Prevents double-click in the UI
        // and any retry from overwriting the original engagement timestamp.
        if (null !== $this->startedAt || null !== $this->watchedAt) {
            return;
        }

        $this->startedAt = $at;
    }

    public function markWatched(DateTimeImmutable $at): void
    {
        // Idempotent: re-marking a watched video does not bump the timestamp.
        // markStarted may have run first — that's fine, we keep startedAt and
        // just add watchedAt.
        if (null !== $this->watchedAt) {
            return;
        }

        $this->watchedAt = $at;
    }

    public function isInSplitPool(): bool
    {
        return null === $this->startedAt && null === $this->watchedAt;
    }

    public function updateMetadata(string $title, VideoDuration $duration): void
    {
        $this->title = $title;
        $this->duration = $duration;
    }

    public function id(): YoutubeVideoId
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function channel(): ChannelName
    {
        return $this->channel;
    }

    public function duration(): VideoDuration
    {
        return $this->duration;
    }

    public function addedAt(): DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function watchedAt(): ?DateTimeImmutable
    {
        return $this->watchedAt;
    }
}
