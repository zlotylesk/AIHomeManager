<?php

declare(strict_types=1);

namespace App\Module\Goals\Application\Command;

/**
 * Recompute and persist the day-continuity streaks for every activity type that
 * has a goal. Single-user, so it carries no payload. Routed to the async
 * transport (see messenger.yaml) and fired nightly by the Scheduler so a streak
 * broken at midnight is reflected without waiting for the next read.
 */
final readonly class RecalculateStreaks
{
}
