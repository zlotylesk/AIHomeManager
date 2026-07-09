<?php

declare(strict_types=1);

namespace App\Module\Goals\Domain\Entity;

use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Enum\Period;
use App\Module\Goals\Domain\ValueObject\GoalTarget;
use InvalidArgumentException;

/**
 * A user-defined goal: reach a target amount of a given activity within a
 * rolling period. The measured activity ($type) is fixed for the life of the
 * goal; the target and period can be adjusted.
 */
final class Goal
{
    public function __construct(
        private readonly string $id,
        private readonly GoalType $type,
        private GoalTarget $target,
        private Period $period,
    ) {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('Goal id cannot be empty.');
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

    public function target(): GoalTarget
    {
        return $this->target;
    }

    public function period(): Period
    {
        return $this->period;
    }

    public function changeTarget(GoalTarget $target): void
    {
        $this->target = $target;
    }

    public function reschedule(Period $period): void
    {
        $this->period = $period;
    }
}
