<?php

declare(strict_types=1);

namespace App\Module\Goals\Domain\Enum;

/**
 * The activity a goal (and its streak) measures across the existing modules.
 * Backed values are the stable serialization/persistence contract.
 */
enum GoalType: string
{
    case BOOK_PAGES = 'book_pages';
    case SERIES_EPISODES = 'series_episodes';
    case ARTICLES_READ = 'articles_read';
    case MUSIC_ALBUMS = 'music_albums';
    case YOUTUBE_VIDEOS = 'youtube_videos';
}
