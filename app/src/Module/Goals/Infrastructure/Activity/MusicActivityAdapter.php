<?php

declare(strict_types=1);

namespace App\Module\Goals\Infrastructure\Activity;

use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Port\ActivityProviderInterface;
use App\Module\Goals\Domain\ReadModel\ActivityEvent;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Reads Music listening activity straight from the `music_listening_sessions`
 * table via DBAL — no import of any Music class, keeping the Goals ← Music
 * boundary deptrac-clean (the YouTube/Articles adapter pattern).
 *
 * Semantic decision (HMAI-401): a `music_albums` goal counts album LISTENS —
 * one `ActivityEvent` per row of `music_listening_sessions` in the window, the
 * same "1 unit per activity row" rule the YouTube/Articles/Series adapters
 * already use for their own goal types. It deliberately does NOT count unique
 * albums (e.g. via `GROUP BY artist, title` the way Search's
 * `MusicSearchableProvider` dedupes its catalog documents) — a goal like
 * "listen to 3 albums a day" is meant to track listening sessions, not shrink
 * every re-listen of an already-owned album down to zero extra progress.
 * Same-day dedup for the *streak* (not the progress sum) is handled by the
 * engine (`GoalProgressCalculator::streak()`), exactly as for every other
 * activity type.
 */
final readonly class MusicActivityAdapter implements ActivityProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function activityBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT played_at FROM music_listening_sessions WHERE played_at BETWEEN :from AND :to',
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
        );

        return array_map(
            static fn (array $row): ActivityEvent => new ActivityEvent(
                GoalType::MUSIC_ALBUMS,
                1,
                new DateTimeImmutable((string) $row['played_at']),
            ),
            $rows,
        );
    }
}
