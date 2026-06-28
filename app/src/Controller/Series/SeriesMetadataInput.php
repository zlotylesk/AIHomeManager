<?php

declare(strict_types=1);

namespace App\Controller\Series;

use App\Module\Series\Domain\Enum\SeriesStatus;
use App\Shared\Domain\ValueObject\CoverUrl;

/**
 * Validated catalog-metadata bag parsed from a Series request body (HMAI-239).
 *
 * Each field is null when absent or explicitly cleared. `hasAnyField` records
 * whether the client sent *any* metadata key at all — the PATCH path uses it to
 * stay partial-safe: a bare `{title}` (inline edit) must NOT zero out the
 * existing metadata, only a body that carries ≥1 metadata key dispatches
 * UpdateSeriesMetadata.
 */
final readonly class SeriesMetadataInput
{
    public function __construct(
        public ?CoverUrl $coverUrl,
        public ?int $year,
        public ?SeriesStatus $status,
        public ?string $description,
        public bool $hasAnyField,
    ) {
    }
}
