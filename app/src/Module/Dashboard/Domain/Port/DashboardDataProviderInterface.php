<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Domain\Port;

use App\Module\Dashboard\Domain\ReadModel\DailyArticle;
use App\Module\Dashboard\Domain\ReadModel\GoalSnapshot;
use App\Module\Dashboard\Domain\ReadModel\RecentTrack;
use App\Module\Dashboard\Domain\ReadModel\Recommendation;
use App\Module\Dashboard\Domain\ReadModel\TodayTask;
use DateTimeImmutable;

/**
 * Reads the product-wide "picture of the day" that the cockpit assembles, so the
 * Dashboard module never couples to any source module's Domain or Persistence.
 * Infrastructure adapters back each fragment per source module; a composite wires
 * them behind this single port (the Goals ActivityProviderInterface pattern).
 */
interface DashboardDataProviderInterface
{
    /**
     * Pending tasks scheduled within the given day, earliest first.
     *
     * @return TodayTask[]
     */
    public function todaysTasks(DateTimeImmutable $day): array;

    /**
     * The article picked for the given day, or null when none was picked.
     */
    public function dailyArticle(DateTimeImmutable $day): ?DailyArticle;

    /**
     * Every defined goal with its persisted streak, by goal type.
     *
     * @return GoalSnapshot[]
     */
    public function goalSnapshots(): array;

    /**
     * Up to $limit ongoing shows and $limit currently-read books to continue.
     *
     * @return Recommendation[]
     */
    public function recommendations(int $limit): array;

    /**
     * The $limit most recently played tracks, newest first.
     *
     * @return RecentTrack[]
     */
    public function recentTracks(int $limit): array;
}
