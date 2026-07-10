<?php

declare(strict_types=1);

namespace App;

use App\Application\Scheduled\BackupDatabase;
use App\Application\Scheduled\GenerateWeeklyActivityReport;
use App\Module\Articles\Application\Command\ResetDailyArticleCache;
use App\Module\Goals\Application\Command\RecalculateStreaks;
use App\Module\Music\Application\Command\PollLastFmRecentTracks;
use App\Module\Music\Application\Command\RefreshDiscogsCollection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * HMAI-35: Four recurring jobs running in the `scheduler_worker` container.
 *
 *  - Daily 00:00 — purge the "article of the day" cache + prune picks > 7d.
 *  - Mon  08:00 — write the weekly activity report to Graylog.
 *  - Every 6h   — refresh the Discogs collection cache before its 6h TTL
 *                 lapses, so the read-path never sees a cold cache.
 *  - Every 30m  — poll Last.fm recent tracks into the local listening history
 *                 (HMAI-144); the dedup hash makes re-polls idempotent.
 *
 * `stateful($cache)` means a worker restart replays any missed window once
 * (we accept the occasional duplicate; the handlers are idempotent).
 */
#[AsSchedule]
final readonly class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        #[Autowire(env: 'DISCOGS_USERNAME')]
        private string $discogsUsername,
        #[Autowire(env: 'LASTFM_USERNAME')]
        private string $lastfmUsername,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return new SymfonySchedule()
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(
                RecurringMessage::cron('0 0 * * *', new ResetDailyArticleCache()),
                RecurringMessage::cron('0 3 * * *', new BackupDatabase()),
                RecurringMessage::cron('0 8 * * 1', new GenerateWeeklyActivityReport()),
                RecurringMessage::cron('0 */6 * * *', new RefreshDiscogsCollection($this->discogsUsername)),
                RecurringMessage::cron('*/30 * * * *', new PollLastFmRecentTracks($this->lastfmUsername)),
                RecurringMessage::cron('0 1 * * *', new RecalculateStreaks()),
            );
    }
}
