<?php

declare(strict_types=1);

namespace App\Module\Music\Application\Query;

use App\Module\Music\Domain\Enum\ListeningSource;
use DateTimeImmutable;

final readonly class GetListeningHistory
{
    public function __construct(
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public ?ListeningSource $source = null,
        public int $limit = 100,
    ) {
    }
}
