<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Domain\ValueObject;

use InvalidArgumentException;

/**
 * How far into an episode the listener got.
 *
 * The two facts travel together because neither is meaningful alone: a zero
 * position with `fullyPlayed` set means "finished and rewound to the start",
 * while a zero position without it means "never opened". Keeping them in one VO
 * stops that pair from drifting apart across the port, the aggregate and the
 * dedup key.
 */
final readonly class ListeningProgress
{
    public function __construct(
        private int $resumePositionMs,
        private bool $fullyPlayed,
    ) {
        if ($resumePositionMs < 0) {
            throw new InvalidArgumentException('Resume position must not be negative.');
        }
    }

    public static function notStarted(): self
    {
        return new self(0, false);
    }

    public static function completed(int $resumePositionMs = 0): self
    {
        return new self($resumePositionMs, true);
    }

    public function resumePositionMs(): int
    {
        return $this->resumePositionMs;
    }

    public function fullyPlayed(): bool
    {
        return $this->fullyPlayed;
    }

    /**
     * Whether this counts as "listened to" at all — the rule the poll uses to
     * decide an episode is worth recording. Anything the listener opened counts,
     * because Spotify reports no listen timestamp of its own (see the module
     * notes in CLAUDE.md): progress is the only evidence a listen happened.
     */
    public function isStarted(): bool
    {
        return $this->fullyPlayed || $this->resumePositionMs > 0;
    }

    public function equals(self $other): bool
    {
        return $this->resumePositionMs === $other->resumePositionMs
            && $this->fullyPlayed === $other->fullyPlayed;
    }
}
