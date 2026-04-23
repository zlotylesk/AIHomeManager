<?php

declare(strict_types=1);

namespace App\Module\Series\Domain\Event;

final class EpisodeRated
{
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly string $seriesId,
        public readonly string $seasonId,
        public readonly string $episodeId,
        public readonly int $rating,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }
}