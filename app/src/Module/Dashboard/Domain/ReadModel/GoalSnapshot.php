<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Domain\ReadModel;

use DateTimeImmutable;

/**
 * A goal + its persisted streak, normalized from the Goals module. `type` and
 * `period` carry the source module's stable serialized enum values as plain
 * strings — the Dashboard does not couple to the Goals enums. `currentStreak`
 * and `longestStreak` default to 0 when no streak has been recorded yet.
 */
final readonly class GoalSnapshot
{
    public function __construct(
        public string $type,
        public int $target,
        public string $period,
        public int $currentStreak,
        public int $longestStreak,
        public ?DateTimeImmutable $lastActivityDate,
    ) {
    }
}
