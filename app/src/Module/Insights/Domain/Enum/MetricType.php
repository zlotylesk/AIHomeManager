<?php

declare(strict_types=1);

namespace App\Module\Insights\Domain\Enum;

/**
 * A habit the trends dashboard plots over time — the normalized source module
 * plus the quantity read from it, à la the Goals {@see \App\Module\Goals\Domain\Enum\GoalType}.
 * Backed values are the stable serialization/API contract.
 */
enum MetricType: string
{
    case BOOKS_PAGES_READ = 'books_pages_read';
    case SERIES_EPISODES_WATCHED = 'series_episodes_watched';
    case YOUTUBE_MINUTES_WATCHED = 'youtube_minutes_watched';
    case MUSIC_TRACKS_PLAYED = 'music_tracks_played';
    case TASKS_COMPLETION_RATE = 'tasks_completion_rate';

    public function unit(): MetricUnit
    {
        return match ($this) {
            self::BOOKS_PAGES_READ,
            self::SERIES_EPISODES_WATCHED,
            self::MUSIC_TRACKS_PLAYED => MetricUnit::COUNT,
            self::YOUTUBE_MINUTES_WATCHED => MetricUnit::MINUTES,
            self::TASKS_COMPLETION_RATE => MetricUnit::PERCENT,
        };
    }
}
