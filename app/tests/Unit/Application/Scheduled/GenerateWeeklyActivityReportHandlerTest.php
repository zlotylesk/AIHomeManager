<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Scheduled;

use App\Application\Scheduled\GenerateWeeklyActivityReport;
use App\Application\Scheduled\GenerateWeeklyActivityReportHandler;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GenerateWeeklyActivityReportHandlerTest extends TestCase
{
    public function testLogsAggregatedCountsToScheduledTaskChannel(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(
            5,
            420,
            12,
            73,
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('Scheduled task completed', self::callback(
                static fn (array $ctx): bool => 'weekly_report' === $ctx['scheduled_task']
                    && 5 === $ctx['read_articles']
                    && 420 === $ctx['pages_read']
                    && 12 === $ctx['completed_tasks']
                    && 73 === $ctx['rated_episodes_total']
                    && isset($ctx['window_start']),
            ));

        $handler = new GenerateWeeklyActivityReportHandler($connection, $logger);
        $handler(new GenerateWeeklyActivityReport());
    }

    public function testReportsZeroWhenNoActivity(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('Scheduled task completed', self::callback(
                static fn (array $ctx): bool => 0 === $ctx['read_articles']
                    && 0 === $ctx['pages_read']
                    && 0 === $ctx['completed_tasks'],
            ));

        $handler = new GenerateWeeklyActivityReportHandler($connection, $logger);
        $handler(new GenerateWeeklyActivityReport());
    }
}
