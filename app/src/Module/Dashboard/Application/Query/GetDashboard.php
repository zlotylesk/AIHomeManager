<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Application\Query;

use DateTimeImmutable;

/**
 * Read the cockpit's "picture of the day" for the given reference day. The day
 * is supplied by the caller (the controller passes "today") so the handler stays
 * pure and deterministic under test.
 */
final readonly class GetDashboard
{
    public function __construct(public DateTimeImmutable $day)
    {
    }
}
