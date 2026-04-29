<?php

declare(strict_types=1);

namespace App\Module\Music\Application\Query;

final readonly class GetMusicComparison
{
    public function __construct(
        public string $period = '1month',
        public int $limit = 50,
    ) {}
}
