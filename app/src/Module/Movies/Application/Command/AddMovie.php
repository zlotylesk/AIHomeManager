<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\Command;

/**
 * Add a film to the collection. Only the title is manually supplied here;
 * catalog metadata, the watched flag and the rating arrive with the tickets
 * that introduce their behavior. Validation of the raw input happens in the
 * handler (through the Title value object).
 */
final readonly class AddMovie
{
    public function __construct(public string $title)
    {
    }
}
