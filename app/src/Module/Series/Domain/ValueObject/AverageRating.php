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
}
