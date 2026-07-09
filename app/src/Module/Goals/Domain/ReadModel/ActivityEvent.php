<?php

declare(strict_types=1);

namespace App\Module\Goals\Domain\ReadModel;

use App\Module\Goals\Domain\Enum\GoalType;
use DateTimeImmutable;

/**
 * A single normalized unit of activity pulled from a source module. The
 * {@see GoalType} identifies both what was measured and which module it came
 * from (BOOK_PAGES ← Books, SERIES_EPISODES ← Series, …); `value` is the amount
 * (pages read, or 1 per watched episode/video/read article); `occurredAt` is
 * when it happened.
 */
final readonly class ActivityEvent
{
    public function __construct(
        public GoalType $type,
        public int $value,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
