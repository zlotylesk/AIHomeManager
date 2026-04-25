<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Command;

final readonly class AddSeason
{
    public function __construct(
        public string $seriesId,
        public int $number,
    ) {}
}