<?php

declare(strict_types=1);

namespace App\Module\Series\Domain\Enum;

/**
 * Whether a series is still airing or has finished (HMAI-190). Optional on the
 * aggregate — a `null` status means "unknown / not set", distinct from either
 * case here.
 */
enum SeriesStatus: string
{
    case ONGOING = 'ongoing';
    case ENDED = 'ended';
}
