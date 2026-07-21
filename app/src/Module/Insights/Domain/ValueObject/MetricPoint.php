<?php

declare(strict_types=1);

namespace App\Module\Insights\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One bucket of a trend: the inclusive start of the period and the value
 * measured within it. A bucket with no activity is a real zero, never an
 * absent point — see {@see TrendSeries} for why the gap matters.
 */
final readonly class MetricPoint
{
    public function __construct(
        public DateTimeImmutable $bucketStart,
        public float $value,
    ) {
        if (!is_finite($value)) {
            throw new InvalidArgumentException('Metric value must be a finite number.');
        }
        if ($value < 0.0) {
            throw new InvalidArgumentException(sprintf('Metric value must not be negative, %s given.', (string) $value));
        }
    }

    public function equals(self $other): bool
    {
        return $this->bucketStart->getTimestamp() === $other->bucketStart->getTimestamp()
            && $this->value === $other->value;
    }
}
