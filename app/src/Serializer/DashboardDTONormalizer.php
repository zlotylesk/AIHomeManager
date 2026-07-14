<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Module\Dashboard\Application\DTO\DashboardDTO;
use App\Module\Dashboard\Domain\ReadModel\DailyArticle;
use App\Module\Dashboard\Domain\ReadModel\GoalSnapshot;
use App\Module\Dashboard\Domain\ReadModel\RecentTrack;
use App\Module\Dashboard\Domain\ReadModel\Recommendation;
use App\Module\Dashboard\Domain\ReadModel\TodayTask;
use DateTimeInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a DashboardDTO to its API array shape (HMAI-240) — one section per
 * widget. Pure field mapping over the composed Domain read models; every datetime
 * (including the streak's last-activity date) is ISO-8601, matching the date-time
 * schema the contract documents for these DateTimeImmutable read-model fields.
 */
final class DashboardDTONormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof DashboardDTO);

        return [
            'date' => $data->date,
            'tasks' => array_map($this->task(...), $data->tasks),
            'article' => null !== $data->article ? $this->article($data->article) : null,
            'goals' => array_map($this->goal(...), $data->goals),
            'recommendations' => array_map($this->recommendation(...), $data->recommendations),
            'recentTracks' => array_map($this->track(...), $data->recentTracks),
        ];
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof DashboardDTO;
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return [DashboardDTO::class => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function task(TodayTask $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'startsAt' => $task->startsAt->format(DateTimeInterface::ATOM),
            'endsAt' => $task->endsAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function article(DailyArticle $article): array
    {
        return [
            'title' => $article->title,
            'url' => $article->url,
            'category' => $article->category,
            'estimatedReadTime' => $article->estimatedReadTime,
            'isRead' => $article->isRead,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function goal(GoalSnapshot $goal): array
    {
        return [
            'type' => $goal->type,
            'target' => $goal->target,
            'period' => $goal->period,
            'currentStreak' => $goal->currentStreak,
            'longestStreak' => $goal->longestStreak,
            'lastActivityDate' => $goal->lastActivityDate?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recommendation(Recommendation $recommendation): array
    {
        return [
            'kind' => $recommendation->kind,
            'title' => $recommendation->title,
            'coverUrl' => $recommendation->coverUrl,
            'detail' => $recommendation->detail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function track(RecentTrack $track): array
    {
        return [
            'artist' => $track->artist,
            'title' => $track->title,
            'playedAt' => $track->playedAt->format(DateTimeInterface::ATOM),
            'source' => $track->source,
        ];
    }
}
