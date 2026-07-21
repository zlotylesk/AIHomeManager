<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Domain\Entity;

use App\Module\Podcasts\Domain\ValueObject\Title;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One episode of a podcast. Its own aggregate keyed to the show by a plain
 * string FK (ADR-007) — the show can hold thousands of episodes, so loading it
 * as a collection to touch a single episode would be the wrong shape.
 *
 * Listening history is deliberately NOT here: an episode may be listened to more
 * than once, so the sessions live in their own record (HMAI-324) and this stays
 * the catalog entry.
 */
final class Episode
{
    private ?string $externalId = null;

    private ?DateTimeImmutable $publishedAt = null;

    private ?int $durationMs = null;

    public function __construct(
        private readonly string $id,
        private readonly string $podcastId,
        private Title $title,
        private readonly DateTimeImmutable $createdAt,
    ) {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('Episode id cannot be empty.');
        }

        if ('' === trim($podcastId)) {
            throw new InvalidArgumentException('Episode must belong to a podcast.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function podcastId(): string
    {
        return $this->podcastId;
    }

    public function title(): Title
    {
        return $this->title;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** The episode's id at the source it came from — see Podcast::externalId(). */
    public function externalId(): ?string
    {
        return $this->externalId;
    }

    public function linkExternal(string $externalId): void
    {
        if ('' === trim($externalId)) {
            throw new InvalidArgumentException('External id cannot be empty.');
        }

        $this->externalId = $externalId;
    }

    public function publishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function durationMs(): ?int
    {
        return $this->durationMs;
    }

    public function rename(Title $title): void
    {
        $this->title = $title;
    }

    public function updateMetadata(?DateTimeImmutable $publishedAt, ?int $durationMs): void
    {
        if (null !== $durationMs && $durationMs < 0) {
            throw new InvalidArgumentException('Episode duration must not be negative.');
        }

        $this->publishedAt = $publishedAt;
        $this->durationMs = $durationMs;
    }
}
