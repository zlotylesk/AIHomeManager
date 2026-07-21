<?php

declare(strict_types=1);

namespace App\Module\Insights\Application\QueryHandler;

use App\Module\Insights\Application\DTO\TrendPointDTO;
use App\Module\Insights\Application\DTO\TrendsDTO;
use App\Module\Insights\Application\DTO\TrendSeriesDTO;
use App\Module\Insights\Application\Query\GetTrends;
use App\Module\Insights\Domain\Enum\MetricType;
use App\Module\Insights\Domain\Port\TrendDataProviderInterface;
use App\Module\Insights\Domain\ValueObject\TrendSeries;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Composes every habit metric's series into one {@see TrendsDTO}.
 *
 * Each metric is read independently and defensively: a source that throws — or
 * one with no wired adapter — degrades to an empty series and is logged, so a
 * single broken table never blanks the whole dashboard (the ticket's "pusta
 * seria zamiast błędu całości"). This mirrors the Dashboard cockpit's
 * per-widget isolation (HMAI-259).
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetTrendsHandler
{
    public function __construct(
        private TrendDataProviderInterface $provider,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(GetTrends $query): TrendsDTO
    {
        $series = [];
        foreach (MetricType::cases() as $metric) {
            $series[] = $this->seriesFor($query, $metric);
        }

        return new TrendsDTO(
            $query->from->format('Y-m-d'),
            $query->to->format('Y-m-d'),
            $query->granularity->value,
            $series,
        );
    }

    private function seriesFor(GetTrends $query, MetricType $metric): TrendSeriesDTO
    {
        try {
            return self::toDTO($metric, $this->provider->seriesFor(
                $metric,
                $query->granularity,
                $query->from,
                $query->to,
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Insights trend metric unavailable.', [
                'metric' => $metric->value,
                'granularity' => $query->granularity->value,
                'exception' => $e,
            ]);

            return new TrendSeriesDTO($metric->value, $metric->unit()->value, 0.0, 0.0, 0.0, []);
        }
    }

    private static function toDTO(MetricType $metric, TrendSeries $series): TrendSeriesDTO
    {
        return new TrendSeriesDTO(
            $metric->value,
            $metric->unit()->value,
            $series->total(),
            $series->average(),
            $series->headline(),
            array_map(
                static fn ($point): TrendPointDTO => new TrendPointDTO(
                    $point->bucketStart->format('Y-m-d'),
                    $point->value,
                ),
                $series->points,
            ),
        );
    }
}
