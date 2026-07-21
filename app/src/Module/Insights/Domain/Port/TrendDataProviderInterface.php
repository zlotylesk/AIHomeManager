<?php

declare(strict_types=1);

namespace App\Module\Insights\Domain\Port;

use App\Module\Insights\Domain\Enum\Granularity;
use App\Module\Insights\Domain\Enum\MetricType;
use App\Module\Insights\Domain\ValueObject\TrendSeries;
use DateTimeImmutable;

/**
 * Reads one habit metric as a time series, without Insights coupling to any
 * source module's Domain or persistence — the Goals
 * {@see \App\Module\Goals\Domain\Port\ActivityProviderInterface} shape. One
 * Infrastructure adapter per source module backs it, aggregated by a composite
 * that delegates to whichever adapter {@see supports()} the metric.
 */
interface TrendDataProviderInterface
{
    /**
     * Whether this provider can read the given metric. A composite uses it to
     * route; asking a provider for a metric it does not support is a caller
     * error, not a silent empty series.
     */
    public function supports(MetricType $metric): bool;

    /**
     * The metric's series across the inclusive [$from, $to] window, bucketed by
     * $granularity.
     *
     * Implementations MUST return one point per bucket the window spans,
     * including buckets with no activity (value 0.0) — a trend with holes in it
     * misreports both the chart line and every fold over the series.
     */
    public function seriesFor(
        MetricType $metric,
        Granularity $granularity,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): TrendSeries;
}
