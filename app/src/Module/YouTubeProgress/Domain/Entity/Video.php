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
        private readonly string $id,
        private string $title,
        private readonly string $channel,
        private int $durationSeconds,
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
        // Store value-object primitives directly so Doctrine ORM can persist
        // them as scalar columns without embeddables. Getters below rehydrate
        // the VOs on read — domain code never sees the raw scalars.
        return new self(
            $id->value(),
            $title,
            $channel->value(),
            $duration->toSeconds(),
            $addedAt,
        );
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
        $this->durationSeconds = $duration->toSeconds();
    }

    public function id(): YoutubeVideoId
    {
        return new YoutubeVideoId($this->id);
    }

    public function title(): string
    {
        return $this->title;
    }

    public function channel(): ChannelName
    {
        return new ChannelName($this->channel);
    }

    public function duration(): VideoDuration
    {
        return new VideoDuration($this->durationSeconds);
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
