<?php

declare(strict_types=1);

namespace App\Shared\Activity;

/**
 * Shared-kernel contract for reading activity streaks.
 *
 * A streak is a number the user compares between screens — the `/goals` page
 * and the `/` cockpit both show it — so two independent computations of it make
 * both untrustworthy. Goals owns the concept; this read-only port is how a
 * second bounded context reads the same answer instead of deriving its own, the
 * same shape as the Google/Trakt token ports (one owner, several readers).
 *
 * Implemented in Goals Infrastructure, so a consumer depends on Shared and never
 * on Goals — Infrastructure → Shared, never cross-module.
 */
interface StreakReaderInterface
{
    /**
     * Streaks for every activity type that currently has a goal, keyed by the
     * type's serialized value.
     *
     * Keyed rather than a list because both callers look a type up: the cockpit
     * joins a streak onto each goal row, and a list would make them re-index it
     * themselves — twice, differently, which is how this drifted in the first
     * place.
     *
     * @return array<string, ActivityStreak>
     */
    public function streaksByType(): array;
}
