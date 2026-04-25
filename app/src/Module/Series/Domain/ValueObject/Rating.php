<?php

declare(strict_types=1);

namespace App\Module\Series\Domain\ValueObject;

final class Rating
{
    public function __construct(private readonly int $value)
    {
        if ($value < 1 || $value > 10) {
            throw new \InvalidArgumentException(
                sprintf('Rating must be between 1 and 10, %d given.', $value)
            );
        }
    }

    public function value(): int
    {
        return $this->value;
    }
}