<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Articles;

use App\Module\Articles\Application\Command\ResetDailyArticleCache;
use App\Module\Articles\Application\Handler\ResetDailyArticleCacheHandler;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Redis;

final class ResetDailyArticleCacheHandlerTest extends TestCase
{
    public function testDeletesTodayCacheKeyAndPrunesOldPicks(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())
            ->method('del')
            ->with('articles:today');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('article_daily_picks'),
                ['days' => 7],
            )
            ->willReturn(3);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('Scheduled task completed', self::callback(
                static fn (array $ctx): bool => 'reset_daily_article_cache' === $ctx['scheduled_task']
                    && 'articles:today' === $ctx['cache_key_deleted']
                    && 3 === $ctx['picks_pruned'],
            ));

        $handler = new ResetDailyArticleCacheHandler($redis, $connection, $logger);
        $handler(new ResetDailyArticleCache());
    }

    public function testLogsZeroPrunedWhenNoOldPicks(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('del')->willReturn(1);

        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturn(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('Scheduled task completed', self::callback(
                static fn (array $ctx): bool => 0 === $ctx['picks_pruned'],
            ));

        $handler = new ResetDailyArticleCacheHandler($redis, $connection, $logger);
        $handler(new ResetDailyArticleCache());
    }
}
