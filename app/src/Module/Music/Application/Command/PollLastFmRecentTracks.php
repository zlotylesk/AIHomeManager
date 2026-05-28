<?php

declare(strict_types=1);

namespace App\Module\Music\Application\Command;

final readonly class PollLastFmRecentTracks
{
    public function __construct(
        public string $username,
        public int $limit = 50,
    ) {
    }
}
