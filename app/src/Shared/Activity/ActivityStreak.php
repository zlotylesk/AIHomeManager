<?php

declare(strict_types=1);

namespace App\Shared\Activity;

use DateTimeImmutable;

/**
 * One activity type's streak, in primitives.
 *
 * `type` carries the Goals module's stable serialized enum value as a plain
 * string rather than the enum itself — the same rule the notification and
 * search-index contracts follow, so a consumer never has to import a Goals
 * class to read a streak.
 */
final readonly class ActivityStreak
{
    public function __construct(
        public string $type,
        public int $currentLength,
        public int $longestLength,
        public ?DateTimeImmutable $lastActivityDate,
    ) {
    }
}
