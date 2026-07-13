<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Application\QueryHandler;

use App\Module\Dashboard\Application\DTO\DashboardDTO;
use App\Module\Dashboard\Application\Query\GetDashboard;
use App\Module\Dashboard\Domain\Port\DashboardDataProviderInterface;
use App\Module\Dashboard\Domain\ReadModel\DailyArticle;
use App\Module\Dashboard\Domain\ReadModel\GoalSnapshot;
use App\Module\Dashboard\Domain\ReadModel\RecentTrack;
use App\Module\Dashboard\Domain\ReadModel\Recommendation;
use App\Module\Dashboard\Domain\ReadModel\TodayTask;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Composes every "today" widget into a single {@see DashboardDTO}. Each widget is
 * loaded independently and defensively: if one source fails, that section
 * degrades to empty/null and is logged, so a single broken widget never takes the
 * whole cockpit down (the acceptance criterion "pusta sekcja zamiast błędu całości").
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetDashboardHandler
{
    /** How many ongoing shows / currently-read books to suggest. */
    private const int RECOMMENDATION_LIMIT = 6;

    /** How many recently-played tracks to surface. */
    private const int RECENT_TRACKS_LIMIT = 8;

    public function __construct(
        private DashboardDataProviderInterface $provider,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(GetDashboard $query): DashboardDTO
    {
        $day = $query->day;

        return new DashboardDTO(
            $day->format('Y-m-d'),
            $this->tasks($day),
            $this->article($day),
            $this->goals(),
            $this->recommendations(),
            $this->recentTracks(),
        );
    }

    /**
     * @return list<TodayTask>
     */
    private function tasks(DateTimeImmutable $day): array
    {
        try {
            return array_values($this->provider->todaysTasks($day));
        } catch (Throwable $e) {
            $this->logWidgetFailure('tasks', $e);

            return [];
        }
    }

    private function article(DateTimeImmutable $day): ?DailyArticle
    {
        try {
            return $this->provider->dailyArticle($day);
        } catch (Throwable $e) {
            $this->logWidgetFailure('article', $e);

            return null;
        }
    }

    /**
     * @return list<GoalSnapshot>
     */
    private function goals(): array
    {
        try {
            return array_values($this->provider->goalSnapshots());
        } catch (Throwable $e) {
            $this->logWidgetFailure('goals', $e);

            return [];
        }
    }

    /**
     * @return list<Recommendation>
     */
    private function recommendations(): array
    {
        try {
            return array_values($this->provider->recommendations(self::RECOMMENDATION_LIMIT));
        } catch (Throwable $e) {
            $this->logWidgetFailure('recommendations', $e);

            return [];
        }
    }

    /**
     * @return list<RecentTrack>
     */
    private function recentTracks(): array
    {
        try {
            return array_values($this->provider->recentTracks(self::RECENT_TRACKS_LIMIT));
        } catch (Throwable $e) {
            $this->logWidgetFailure('recentTracks', $e);

            return [];
        }
    }

    private function logWidgetFailure(string $widget, Throwable $e): void
    {
        $this->logger->warning('Dashboard widget failed to load', [
            'widget' => $widget,
            'exception' => $e,
        ]);
    }
}
