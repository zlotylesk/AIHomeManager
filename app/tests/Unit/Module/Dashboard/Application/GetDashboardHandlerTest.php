<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Dashboard\Application;

use App\Module\Dashboard\Application\Query\GetDashboard;
use App\Module\Dashboard\Application\QueryHandler\GetDashboardHandler;
use App\Module\Dashboard\Domain\Port\DashboardDataProviderInterface;
use App\Module\Dashboard\Domain\ReadModel\DailyArticle;
use App\Module\Dashboard\Domain\ReadModel\GoalSnapshot;
use App\Module\Dashboard\Domain\ReadModel\RecentTrack;
use App\Module\Dashboard\Domain\ReadModel\Recommendation;
use App\Module\Dashboard\Domain\ReadModel\TodayTask;
use App\Module\Dashboard\Infrastructure\Cache\RedisDashboardCache;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class GetDashboardHandlerTest extends TestCase
{
    private function day(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-13 12:00:00');
    }

    private function handler(DashboardDataProviderInterface $provider): GetDashboardHandler
    {
        return new GetDashboardHandler($provider, new RedisDashboardCache(new ArrayAdapter()), new NullLogger());
    }

    public function testComposesEveryWidgetSectionIntoOneReadModel(): void
    {
        $day = $this->day();
        $provider = $this->createStub(DashboardDataProviderInterface::class);
        $provider->method('todaysTasks')->willReturn([
            new TodayTask('t-1', 'Standup', $day, $day),
        ]);
        $provider->method('dailyArticle')->willReturn(
            new DailyArticle('Article', 'https://example.test/a', 'tech', 5, false),
        );
        $provider->method('goalSnapshots')->willReturn([
            new GoalSnapshot('book_pages', 50, 'daily', 3, 9, null),
        ]);
        $provider->method('recommendations')->willReturn([
            new Recommendation('series', 'Ongoing Show', null, '2020'),
        ]);
        $provider->method('recentTracks')->willReturn([
            new RecentTrack('Artist', 'Track', $day, 'manual'),
        ]);

        $dto = $this->handler($provider)(new GetDashboard($day));

        self::assertSame('2026-07-13', $dto->date);
        self::assertCount(1, $dto->tasks);
        self::assertSame('Standup', $dto->tasks[0]->title);
        self::assertNotNull($dto->article);
        self::assertSame('Article', $dto->article->title);
        self::assertCount(1, $dto->goals);
        self::assertCount(1, $dto->recommendations);
        self::assertCount(1, $dto->recentTracks);
    }

    public function testAFailingWidgetDegradesToAnEmptySectionWithoutBreakingTheRest(): void
    {
        $day = $this->day();
        $provider = $this->createStub(DashboardDataProviderInterface::class);
        // Two widgets blow up — the cockpit must still render the healthy ones.
        $provider->method('todaysTasks')->willThrowException(new RuntimeException('tasks source down'));
        $provider->method('dailyArticle')->willThrowException(new RuntimeException('article source down'));
        $provider->method('goalSnapshots')->willReturn([
            new GoalSnapshot('book_pages', 50, 'daily', 0, 0, null),
        ]);
        $provider->method('recommendations')->willReturn([]);
        $provider->method('recentTracks')->willReturn([
            new RecentTrack('Artist', 'Track', $day, 'manual'),
        ]);

        $dto = $this->handler($provider)(new GetDashboard($day));

        self::assertSame([], $dto->tasks);
        self::assertNull($dto->article);
        self::assertCount(1, $dto->goals);
        self::assertSame([], $dto->recommendations);
        self::assertCount(1, $dto->recentTracks);
    }

    public function testASecondDispatchOfTheSameDayIsServedFromCache(): void
    {
        $day = $this->day();
        // Every provider method must be hit exactly once across two dispatches —
        // the second read comes from the cache, not the adapters (HMAI-263).
        $provider = $this->createMock(DashboardDataProviderInterface::class);
        $provider->expects(self::once())->method('todaysTasks')->willReturn([]);
        $provider->expects(self::once())->method('dailyArticle')->willReturn(null);
        $provider->expects(self::once())->method('goalSnapshots')->willReturn([]);
        $provider->expects(self::once())->method('recommendations')->willReturn([]);
        $provider->expects(self::once())->method('recentTracks')->willReturn([]);

        $handler = $this->handler($provider);
        $handler(new GetDashboard($day));
        $handler(new GetDashboard($day));
    }

    public function testEmptyWidgetsYieldEmptySectionsNotErrors(): void
    {
        $day = $this->day();
        $provider = $this->createStub(DashboardDataProviderInterface::class);
        $provider->method('todaysTasks')->willReturn([]);
        $provider->method('dailyArticle')->willReturn(null);
        $provider->method('goalSnapshots')->willReturn([]);
        $provider->method('recommendations')->willReturn([]);
        $provider->method('recentTracks')->willReturn([]);

        $dto = $this->handler($provider)(new GetDashboard($day));

        self::assertSame('2026-07-13', $dto->date);
        self::assertSame([], $dto->tasks);
        self::assertNull($dto->article);
        self::assertSame([], $dto->goals);
        self::assertSame([], $dto->recommendations);
        self::assertSame([], $dto->recentTracks);
    }
}
