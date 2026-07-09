<?php

declare(strict_types=1);

namespace App\Module\Goals\Domain\Entity;

use App\Module\Goals\Domain\Enum\GoalType;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Consecutive-activity streak for a given activity type. Holds the current run
 * length, the longest run ever achieved, and the date of the last activity.
 *
 * The aggregate owns only the primitive state transitions (extend / reset); the
 * policy that decides WHEN a streak continues or breaks (based on the gap since
 * the last activity) lives in the progress engine — see the follow-up tasks.
 */
final class Streak
{
    public function __construct(
        private readonly string $id,
        private readonly GoalType $type,
        private int $currentLength = 0,
        private int $longestLength = 0,
        private ?DateTimeImmutable $lastActivityDate = null,
    ) {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('Streak id cannot be empty.');
        }

        if ($currentLength < 0) {
            throw new InvalidArgumentException('Current streak length cannot be negative.');
        }

        if ($longestLength < 0) {
            throw new InvalidArgumentException('Longest streak length cannot be negative.');
        }

        if ($longestLength < $currentLength) {
            throw new InvalidArgumentException('Longest streak length cannot be smaller than the current length.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): GoalType
    {
        return $this->type;
    }

    public function currentLength(): int
    {
        return $this->currentLength;
    }

    public function longestLength(): int
    {
        return $this->longestLength;
    }

    public function lastActivityDate(): ?DateTimeImmutable
    {
        return $this->lastActivityDate;
    }

    /**
     * Continue the streak by one day of activity, promoting the longest run when
     * the current run overtakes it.
     */
    public function extend(DateTimeImmutable $activityDate): void
    {
        ++$this->currentLength;

        if ($this->currentLength > $this->longestLength) {
            $this->longestLength = $this->currentLength;
        }

        $this->lastActivityDate = $activityDate;
    }

    /**
     * Break the streak. The longest run and last activity date are preserved.
     */
    public function reset(): void
    {
        $this->currentLength = 0;
    }
}
