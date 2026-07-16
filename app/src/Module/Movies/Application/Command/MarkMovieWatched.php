<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\Command;

/**
 * Mark a film as watched. The watch timestamp is stamped by the aggregate ("now"
 * for a manual mark); the Trakt import (HMAI-290) sets a real watched date by
 * driving the aggregate directly.
 */
final readonly class MarkMovieWatched
{
    public function __construct(public string $id)
    {
    }
}
