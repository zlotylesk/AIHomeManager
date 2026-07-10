<?php

declare(strict_types=1);

namespace App\Module\Goals\Domain\ReadModel;

use App\Module\Goals\Domain\Enum\GoalType;
use DateTimeImmutable;

/**
 * The computed day-continuity streak for an activity type: the length of the
 * run of consecutive active days ending at (or one day before) today, the
 * longest such run observed, and the most recent active day.
 */
final readonly class StreakState
{
    public function __construct(
        public GoalType $type,
        public int $currentLength,
        public int $longestLength,
        public ?DateTimeImmutable $lastActivityDate,
    ) {
    }
}
