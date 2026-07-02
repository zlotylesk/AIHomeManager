<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Command;

use App\Module\Series\Domain\Enum\SeriesStatus;
use App\Shared\Domain\ValueObject\CoverUrl;

final readonly class CreateSeries
{
    public function __construct(
        public string $title,
        public ?CoverUrl $coverUrl = null,
        public ?int $year = null,
        public ?SeriesStatus $status = null,
        public ?string $description = null,
    ) {
    }
}
