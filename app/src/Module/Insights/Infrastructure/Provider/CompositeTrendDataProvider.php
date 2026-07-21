<?php

declare(strict_types=1);

namespace App\Module\Insights\Infrastructure\Provider;

use App\Module\Insights\Domain\Enum\Granularity;
use App\Module\Insights\Domain\Enum\MetricType;
use App\Module\Insights\Domain\Port\TrendDataProviderInterface;
use App\Module\Insights\Domain\ValueObject\TrendSeries;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Routes a metric to the one adapter that reads it, and backs the
 * {@see TrendDataProviderInterface} port. Unlike the Goals activity composite
 * this does not fan out and concatenate — every metric has exactly one source,
 * so asking all five and merging would only hide a misrouted read.
 *
 * The adapters are tagged explicitly in `services.yaml` rather than by an
 * `_instanceof` rule, so this composite — which implements the same interface —
 * cannot end up iterating over itself (the Notifications HMAI-282 precedent).
 */
final readonly class CompositeTrendDataProvider implements TrendDataProviderInterface
{
    /**
     * @param iterable<TrendDataProviderInterface> $providers
     */
    public function __construct(private iterable $providers)
    {
    }

    public function supports(MetricType $metric): bool
    {
        return null !== $this->providerFor($metric);
    }

    public function seriesFor(
        MetricType $metric,
        Granularity $granularity,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): TrendSeries {
        $provider = $this->providerFor($metric);

        if (null === $provider) {
            throw new InvalidArgumentException(sprintf('No trend provider reads the %s metric.', $metric->value));
        }

        return $provider->seriesFor($metric, $granularity, $from, $to);
    }

    private function providerFor(MetricType $metric): ?TrendDataProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($metric)) {
                return $provider;
            }
        }

        return null;
    }
}
