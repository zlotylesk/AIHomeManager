<?php

declare(strict_types=1);

namespace App\Module\Insights\Application\DTO;

/**
 * One plotted bucket: the bucket's start as a plain `Y-m-d` (it is a date, not a
 * moment — the time-of-day of a week is meaningless) and the measured value.
 */
final readonly class TrendPointDTO
{
    public function __construct(
        public string $bucketStart,
        public float $value,
    ) {
    }
}
