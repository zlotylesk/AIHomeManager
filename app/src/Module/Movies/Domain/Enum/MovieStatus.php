<?php

declare(strict_types=1);

namespace App\Module\Movies\Domain\Enum;

/**
 * A film's release status (HMAI-289). Optional on the aggregate — a `null`
 * status means "unknown / not set". Orthogonal to the `watched` flag (HMAI-288):
 * this is about the film's release, not whether the user has seen it. Mirrors the
 * Series `SeriesStatus` production-state enum.
 */
enum MovieStatus: string
{
    case RELEASED = 'released';
    case UPCOMING = 'upcoming';
}
