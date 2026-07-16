<?php

declare(strict_types=1);

namespace App\Module\Movies\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The user's own 1–10 rating of a film. Mirrors the Series Rating VO (a movie is
 * a single aggregate, so there is only one rating — no season/episode split).
 */
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

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
