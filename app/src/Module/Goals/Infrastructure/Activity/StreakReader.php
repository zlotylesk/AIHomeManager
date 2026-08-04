<?php

declare(strict_types=1);

namespace App\Module\Goals\Infrastructure\Activity;

use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Port\ActivityProviderInterface;
use App\Module\Goals\Domain\Repository\StreakRepositoryInterface;
use App\Module\Goals\Domain\Service\GoalProgressCalculator;
use App\Shared\Activity\ActivityStreak;
use App\Shared\Activity\StreakReaderInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * The one place a streak is worked out (HMAI-412).
 *
 * Before this there were two, and they disagreed: `/api/goals/streaks` computed
 * live on every read, while `/api/dashboard` read the `streaks` table that only
 * the nightly recompute writes. Today's activity therefore showed on `/goals`
 * and not in the cockpit, and a goal created today read as all zeroes there
 * until 01:00 — because the cockpit's LEFT JOIN found no row at all.
 *
 * ## Strategy: computed live, with the all-time longest merged in
 *
 * Neither half of the ticket's either/or works alone.
 *
 * *Purely persisted* would leave the cockpit a night behind. The ticket suggests
 * refreshing on activity registration instead, but that would have to ride an
 * event rail, and five of the source modules (Articles, Music, Movies, Podcasts,
 * YouTube) emit no domain events at all — so it would cover some activity and
 * silently miss the rest, which is the exact class of failure this epic exists
 * to remove.
 *
 * *Purely live* loses the record: the read window is 365 days, so a longest run
 * older than that vanishes. That is a real divergence the ticket does not name —
 * on `longestLength` it is the cockpit that has been right and `/goals` wrong,
 * because {@see \App\Module\Goals\Application\CommandHandler\RecalculateStreaksHandler}
 * preserves the all-time value with a `max()` while the live read recomputed it
 * from the window alone.
 *
 * So the current run is computed live — today's activity counts immediately, on
 * both surfaces — and `longestLength` is the greater of the computed run and the
 * stored one. The nightly recompute keeps its job and gains a stated purpose: it
 * is what carries the record forward once it falls out of the read window.
 */
final readonly class StreakReader implements StreakReaderInterface
{
    /** Matches the window the nightly recompute reads, so the two cannot disagree. */
    private const int LOOKBACK_DAYS = 365;

    public function __construct(
        private Connection $connection,
        private ActivityProviderInterface $activityProvider,
        private GoalProgressCalculator $calculator,
        private StreakRepositoryInterface $streaks,
    ) {
    }

    /**
     * @return array<string, ActivityStreak>
     */
    public function streaksByType(): array
    {
        $types = $this->connection->fetchFirstColumn('SELECT DISTINCT type FROM goals ORDER BY type');

        if ([] === $types) {
            return [];
        }

        $now = new DateTimeImmutable();
        $from = $now->modify('-'.self::LOOKBACK_DAYS.' days')->setTime(0, 0);
        $events = $this->activityProvider->activityBetween($from, $now);

        $result = [];
        foreach ($types as $type) {
            $goalType = GoalType::from((string) $type);
            $state = $this->calculator->streak($goalType, $events, $now);
            $stored = $this->streaks->findByType($goalType);

            $result[$goalType->value] = new ActivityStreak(
                type: $goalType->value,
                currentLength: $state->currentLength,
                // The stored value is the only carrier of a run that predates the
                // window; the computed one is the only carrier of a run set today.
                longestLength: max($state->longestLength, $stored?->longestLength() ?? 0),
                // Same rule the nightly recompute uses when it writes this: the
                // computed date wins whenever there is one, and the stored date
                // carries the answer once the last activity drops out of the
                // window. Falling back to null there would report "never active"
                // for a library that simply has not been touched in a year.
                lastActivityDate: $state->lastActivityDate ?? $stored?->lastActivityDate(),
            );
        }

        return $result;
    }
}
