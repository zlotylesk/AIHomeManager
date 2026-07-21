<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Insights\Application;

use App\Module\Insights\Application\DTO\TrendsDTO;
use App\Module\Insights\Application\DTO\TrendSeriesDTO;
use App\Module\Insights\Application\Query\GetTrends;
use App\Module\Insights\Application\QueryHandler\GetTrendsHandler;
use App\Module\Insights\Domain\Enum\Granularity;
use App\Module\Insights\Domain\Enum\MetricType;
use App\Module\Insights\Domain\Port\TrendDataProviderInterface;
use App\Module\Insights\Domain\ValueObject\MetricPoint;
use App\Module\Insights\Domain\ValueObject\TrendSeries;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class GetTrendsHandlerTest extends TestCase
{
    private const string FROM = '2026-07-06';
    private const string TO = '2026-07-19';

    public function testComposesOneSeriesPerMetric(): void
    {
        $dto = $this->handle($this->providerReturning(static fn (MetricType $metric): TrendSeries => new TrendSeries(
            $metric,
            Granularity::WEEK,
            [self::point('2026-07-06', 10.0), self::point('2026-07-13', 30.0)],
        )));

        self::assertSame(self::FROM, $dto->from);
        self::assertSame(self::TO, $dto->to);
        self::assertSame('week', $dto->granularity);
        self::assertCount(count(MetricType::cases()), $dto->series);
        self::assertSame(
            array_map(static fn (MetricType $m): string => $m->value, MetricType::cases()),
            array_map(static fn (TrendSeriesDTO $s): string => $s->metric, $dto->series),
        );
    }

    public function testCarriesThePrecomputedFoldsAndPoints(): void
    {
        $dto = $this->handle($this->providerReturning(static fn (MetricType $metric): TrendSeries => new TrendSeries(
            $metric,
            Granularity::WEEK,
            [self::point('2026-07-06', 10.0), self::point('2026-07-13', 30.0)],
        )));

        $pages = self::seriesOf($dto->series, MetricType::BOOKS_PAGES_READ);
        self::assertSame('count', $pages->unit);
        self::assertSame(40.0, $pages->total);
        self::assertSame(20.0, $pages->average);
        self::assertSame(40.0, $pages->headline, 'a count metric headlines its total');
        self::assertSame(['2026-07-06', '2026-07-13'], array_column($pages->points, 'bucketStart'));
        self::assertSame([10.0, 30.0], array_column($pages->points, 'value'));

        $rate = self::seriesOf($dto->series, MetricType::TASKS_COMPLETION_RATE);
        self::assertSame(20.0, $rate->headline, 'a rate metric headlines its average');
    }

    /**
     * The acceptance criterion "pusta seria zamiast błędu całości": one dead
     * source must not blank the other four metrics.
     */
    public function testOneFailingMetricDegradesToAnEmptySeriesAndIsLogged(): void
    {
        /** @var LoggerInterface&MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'Insights trend metric unavailable.',
                self::callback(static fn (array $context): bool => 'music_tracks_played' === $context['metric']),
            );

        $provider = $this->providerReturning(static function (MetricType $metric): TrendSeries {
            if (MetricType::MUSIC_TRACKS_PLAYED === $metric) {
                throw new RuntimeException('music table is on fire');
            }

            return new TrendSeries($metric, Granularity::WEEK, [self::point('2026-07-06', 7.0)]);
        });

        $dto = $this->handle($provider, $logger);

        $music = self::seriesOf($dto->series, MetricType::MUSIC_TRACKS_PLAYED);
        self::assertSame([], $music->points);
        self::assertSame(0.0, $music->total);
        self::assertSame('count', $music->unit, 'the unit is still known even when the read failed');

        $books = self::seriesOf($dto->series, MetricType::BOOKS_PAGES_READ);
        self::assertSame(7.0, $books->total, 'the other metrics are unaffected');
    }

    /**
     * A healthy metric always fills its window, so an idle stretch still carries
     * zero-valued points — which is what tells it apart from a failed read.
     */
    public function testAnIdleMetricStillCarriesPoints(): void
    {
        $dto = $this->handle($this->providerReturning(static fn (MetricType $metric): TrendSeries => new TrendSeries(
            $metric,
            Granularity::WEEK,
            [self::point('2026-07-06', 0.0), self::point('2026-07-13', 0.0)],
        )));

        $books = self::seriesOf($dto->series, MetricType::BOOKS_PAGES_READ);
        self::assertCount(2, $books->points);
        self::assertSame(0.0, $books->total);
    }

    private function handle(TrendDataProviderInterface $provider, ?LoggerInterface $logger = null): TrendsDTO
    {
        $handler = new GetTrendsHandler($provider, $logger ?? new NullLogger());

        return $handler(new GetTrends(
            Granularity::WEEK,
            new DateTimeImmutable(self::FROM),
            new DateTimeImmutable(self::TO),
        ));
    }

    /**
     * @param callable(MetricType): TrendSeries $series
     */
    private function providerReturning(callable $series): TrendDataProviderInterface
    {
        return new class($series) implements TrendDataProviderInterface {
            /** @param callable(MetricType): TrendSeries $series */
            public function __construct(private $series)
            {
            }

            public function supports(MetricType $metric): bool
            {
                return true;
            }

            public function seriesFor(
                MetricType $metric,
                Granularity $granularity,
                DateTimeImmutable $from,
                DateTimeImmutable $to,
            ): TrendSeries {
                return ($this->series)($metric);
            }
        };
    }

    /**
     * @param list<TrendSeriesDTO> $series
     */
    private static function seriesOf(array $series, MetricType $metric): TrendSeriesDTO
    {
        foreach ($series as $candidate) {
            if ($candidate->metric === $metric->value) {
                return $candidate;
            }
        }

        self::fail($metric->value.' is missing from the composed trends.');
    }

    private static function point(string $monday, float $value): MetricPoint
    {
        return new MetricPoint(new DateTimeImmutable($monday.' 00:00:00'), $value);
    }
}
