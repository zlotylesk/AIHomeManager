<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\Command;

/**
 * HMAI-35: Fired by Symfony Scheduler at 00:00 every day to purge the
 * "article of the day" cache so the next request picks a fresh candidate.
 *
 * Also trims `article_daily_picks` rows older than 7 days to keep the
 * "recently picked" exclusion window bounded.
 */
final readonly class ResetDailyArticleCache
{
}
