<?php

declare(strict_types=1);

namespace App\Module\Music\Application\Command;

final readonly class RefreshDiscogsCollection
{
    public function __construct(
        public string $username,
    ) {
    }
}
