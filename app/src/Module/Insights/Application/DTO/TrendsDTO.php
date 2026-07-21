<?php

declare(strict_types=1);

namespace App\Module\Insights\Application\DTO;

/**
 * The whole trends dashboard for one window: the range that was asked for, the
 * bucket size, and one series per habit metric.
 */
final readonly class TrendsDTO
{
    /**
     * @param list<TrendSeriesDTO> $series
     */
    public function __construct(
        public string $from,
        public string $to,
        public string $granularity,
        public array $series,
    ) {
    }
}
