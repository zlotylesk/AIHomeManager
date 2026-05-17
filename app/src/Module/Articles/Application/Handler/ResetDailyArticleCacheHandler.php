<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\Handler;

use App\Module\Articles\Application\Command\ResetDailyArticleCache;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Redis;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * HMAI-35: Midnight reset for the "article of the day" cache.
 *
 * Two side effects, both idempotent:
 *  - Delete Redis key `articles:today` so the next /api/articles/today picks
 *    fresh instead of returning yesterday's cached choice.
 *  - DELETE rows from `article_daily_picks` older than 7 days — the exclusion
 *    window for "recently picked" is one week, anything older is dead weight.
 */
#[AsMessageHandler]
final readonly class ResetDailyArticleCacheHandler
{
    private const string CACHE_KEY = 'articles:today';
    private const int RETENTION_DAYS = 7;

    public function __construct(
        #[Autowire(service: 'app.redis')]
        private Redis $redis,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResetDailyArticleCache $command): void
    {
        $this->redis->del(self::CACHE_KEY);

        $deleted = (int) $this->connection->executeStatement(
            'DELETE FROM article_daily_picks WHERE picked_date < CURDATE() - INTERVAL :days DAY',
            ['days' => self::RETENTION_DAYS],
        );

        $this->logger->info('Scheduled task completed', [
            'scheduled_task' => 'reset_daily_article_cache',
            'cache_key_deleted' => self::CACHE_KEY,
            'picks_pruned' => $deleted,
        ]);
    }
}
