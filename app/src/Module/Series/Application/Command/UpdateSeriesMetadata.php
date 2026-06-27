<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Command;

use App\Module\Series\Domain\Enum\SeriesStatus;
use App\Shared\Domain\ValueObject\CoverUrl;

/**
 * Full replace of a series' optional catalog metadata (HMAI-190). Every field
 * is set outright — a `null` clears it — so the edit endpoint must send the
 * complete desired state, not a partial patch.
 */
final readonly class UpdateSeriesMetadata
{
    public function __construct(
        public string $seriesId,
        public ?CoverUrl $coverUrl,
        public ?int $year,
        public ?SeriesStatus $status,
        public ?string $description,
    ) {
    }
}
