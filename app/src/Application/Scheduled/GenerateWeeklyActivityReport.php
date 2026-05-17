<?php

declare(strict_types=1);

namespace App\Application\Scheduled;

/**
 * HMAI-35: Fired by Symfony Scheduler every Monday at 08:00.
 *
 * Aggregates the previous 7 days of activity (rated episodes, read articles,
 * pages read, completed tasks) and logs it to Graylog so the user can scan
 * "what did I actually do last week" without opening four module views.
 */
final readonly class GenerateWeeklyActivityReport
{
}
