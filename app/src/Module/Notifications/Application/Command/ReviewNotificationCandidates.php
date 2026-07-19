<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\Command;

/**
 * Run the periodic review that finds occurrences no single event announces —
 * a deadline arriving, a streak about to lapse, the day's article pick.
 *
 * No payload: the sweep always reviews the current moment, and a single-user
 * system has nothing else to scope it by.
 */
final readonly class ReviewNotificationCandidates
{
}
