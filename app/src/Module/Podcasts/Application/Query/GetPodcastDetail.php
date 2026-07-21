<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Application\Query;

final readonly class GetPodcastDetail
{
    public function __construct(
        public string $id,
    ) {
    }
}
