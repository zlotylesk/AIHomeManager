<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Application\DTO;

use App\Module\Dashboard\Domain\ReadModel\DailyArticle;
use App\Module\Dashboard\Domain\ReadModel\GoalSnapshot;
use App\Module\Dashboard\Domain\ReadModel\RecentTrack;
use App\Module\Dashboard\Domain\ReadModel\Recommendation;
use App\Module\Dashboard\Domain\ReadModel\TodayTask;

/**
 * The cockpit read-model: one section per widget composed from the source
 * modules. Composes the Domain read models directly (Application → Domain is
 * allowed — the MusicComparisonDTO precedent, HMAI-233); the DashboardDTONormalizer
 * serializes them. Any section may be empty/null when its widget has no data (or
 * failed to load) — the cockpit degrades gracefully rather than erroring wholesale.
 */
final readonly class DashboardDTO
{
    /**
     * @param TodayTask[]      $tasks
     * @param GoalSnapshot[]   $goals
     * @param Recommendation[] $recommendations
     * @param RecentTrack[]    $recentTracks
     */
    public function __construct(
        public string $date,
        public array $tasks,
        public ?DailyArticle $article,
        public array $goals,
        public array $recommendations,
        public array $recentTracks,
    ) {
    }
}
