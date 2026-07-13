<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Infrastructure\Provider;

use App\Module\Dashboard\Domain\Port\DashboardDataProviderInterface;
use App\Module\Dashboard\Domain\ReadModel\DailyArticle;
use DateTimeImmutable;

/**
 * Assembles the cockpit's "picture of the day" by delegating each fragment to
 * its per-widget DBAL adapter. Wired as the DashboardDataProviderInterface
 * implementation — adding or removing a widget stays a matter of one adapter,
 * without the Query layer touching source modules.
 */
final readonly class CompositeDashboardDataProvider implements DashboardDataProviderInterface
{
    public function __construct(
        private TasksTodayAdapter $tasks,
        private DailyArticleAdapter $article,
        private GoalsSnapshotAdapter $goals,
        private RecommendationsAdapter $recommendations,
        private RecentMusicAdapter $music,
    ) {
    }

    public function todaysTasks(DateTimeImmutable $day): array
    {
        return $this->tasks->todaysTasks($day);
    }

    public function dailyArticle(DateTimeImmutable $day): ?DailyArticle
    {
        return $this->article->dailyArticle($day);
    }

    public function goalSnapshots(): array
    {
        return $this->goals->goalSnapshots();
    }

    public function recommendations(int $limit): array
    {
        return $this->recommendations->recommendations($limit);
    }

    public function recentTracks(int $limit): array
    {
        return $this->music->recentTracks($limit);
    }
}
