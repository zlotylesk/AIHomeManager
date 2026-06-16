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
        if (null !== $this->startedAt || null !== $this->watchedAt) {
            return;
        }

        $this->startedAt = $at;
    }

    public function markWatched(DateTimeImmutable $at): void
    {
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
