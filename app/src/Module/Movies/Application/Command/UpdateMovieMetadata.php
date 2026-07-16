<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\Command;

/**
 * Replace a film's catalog metadata (cover/year/status/description). A full
 * replace — a null field clears that value. Kept separate from UpdateMovie
 * (rename-only) so a bare title edit never wipes the metadata (the Series
 * RenameSeries / UpdateSeriesMetadata split). Raw inputs are validated in the
 * handler via MovieMetadata.
 */
final readonly class UpdateMovieMetadata
{
    public function __construct(
        public string $id,
        public ?string $coverUrl,
        public ?int $year,
        public ?string $status,
        public ?string $description,
    ) {
    }
}
