<?php

declare(strict_types=1);

namespace App\Module\Music\Application\Command;

use App\Module\Music\Domain\Enum\ListeningSource;
use DateTimeImmutable;

final readonly class LogListeningSession
{
    public function __construct(
        public string $artist,
        public string $title,
        public DateTimeImmutable $playedAt,
        public ListeningSource $source,
        public ?int $playCount = null,
    ) {
    }
}
