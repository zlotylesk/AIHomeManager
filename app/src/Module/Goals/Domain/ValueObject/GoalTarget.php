<?php

declare(strict_types=1);

namespace App\Module\Goals\Domain\ValueObject;

use InvalidArgumentException;

final readonly class GoalTarget
{
    public function __construct(private int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException('Goal target must be a positive number.');
        }
    }

    public function value(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
