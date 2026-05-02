<?php

declare(strict_types=1);

namespace App\Module\Series\Domain\ValueObject;

use InvalidArgumentException;

final readonly class Rating
{
    public function __construct(private int $value)
    {
        if ($value < 1 || $value > 10) {
            throw new InvalidArgumentException(sprintf('Rating must be between 1 and 10, %d given.', $value));
        }
    }

    public function value(): int
    {
        return $this->value;
    }
}
