<?php

declare(strict_types=1);

namespace App\Module\Insights\Application\DTO;

/**
 * One metric's line on the dashboard.
 *
 * The folds are computed here in the read layer rather than at serialize time
 * (HMAI-242), and {@see $headline} is the one the metric's unit says is
 * meaningful — a total for a count, an average for a rate — so a renderer that
 * shows "one big number" cannot pick the wrong one.
 *
 * An **empty** {@see $points} list means the metric could not be read at all.
 * A healthy metric always fills its window, zero buckets included, so no points
 * is a failure signal rather than a quiet stretch.
 */
final readonly class TrendSeriesDTO
{
    /**
     * @param list<TrendPointDTO> $points
     */
    public function __construct(
        public string $metric,
        public string $unit,
        public float $total,
        public float $average,
        public float $headline,
        public array $points,
    ) {
    }
}
