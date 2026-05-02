<?php

declare(strict_types=1);

namespace App\Module\Series\Domain\Event;

use DateTimeImmutable;

final readonly class EpisodeRated
{
    public DateTimeImmutable $occurredAt;

    public function __construct(
        public string $seriesId,
        public string $seasonId,
        public string $episodeId,
        public int $rating,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }
}
