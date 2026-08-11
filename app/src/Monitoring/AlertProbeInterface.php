<?php

declare(strict_types=1);

namespace App\Monitoring;

use DateTimeImmutable;

/**
 * One source of operational truth: it looks at something and says what is
 * currently wrong with it.
 *
 * A probe reports **state, not events** — everything that is wrong right now,
 * every time it is asked. It carries no memory of what it said last time, which
 * is what keeps deduplication and recovery in one place ({@see SystemMonitor})
 * instead of re-implemented per source.
 *
 * Implementations answer for a reference moment rather than reading a clock, so
 * "is this stale yet" is testable without waiting.
 */
interface AlertProbeInterface
{
    /**
     * A short, stable namespace for this probe's alert keys ("health", "backup").
     *
     * It is also the unit of silence: when a probe throws, the monitor holds on
     * to that namespace's stored alerts rather than reporting them recovered.
     */
    public function name(): string;

    /**
     * @return list<Alert> everything wrong at $at; empty means healthy
     */
    public function probe(DateTimeImmutable $at): array;
}
