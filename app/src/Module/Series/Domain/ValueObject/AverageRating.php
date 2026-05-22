<?php

declare(strict_types=1);

namespace App\Module\Series\Domain\ValueObject;

final readonly class AverageRating
{
    private float $value;

    /** @param Rating[] $ratings */
    public function __construct(array $ratings)
    {
        $this->value = empty($ratings)
            ? 0.0
            : round(
                array_sum(array_map(fn (Rating $r) => $r->value(), $ratings)) / count($ratings),
                2
            );
    }

    public function value(): float
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        // Float === is safe here because both sides come from the same
        // round(…, 2) pipeline — no transcendental functions, no accumulated
        // FP drift to worry about.
        return $this->value === $other->value;
    }
}
