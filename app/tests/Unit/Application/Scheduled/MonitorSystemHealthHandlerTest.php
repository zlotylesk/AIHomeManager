<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Scheduled;

use App\Application\Scheduled\MonitorSystemHealth;
use App\Application\Scheduled\MonitorSystemHealthHandler;
use App\Monitoring\Alert;
use App\Monitoring\AlertSeverity;
use App\Monitoring\SystemMonitor;
use App\Tests\Support\Monitoring\FakeAlertProbe;
use App\Tests\Support\Monitoring\InMemoryAlertStateStore;
use App\Tests\Support\Monitoring\RecordingAlertChannel;
use App\Tests\Support\SpyLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The monitor itself is exercised by {@see \App\Tests\Unit\Monitoring\SystemMonitorTest};
 * what belongs here is the scheduled frame around it — that a sweep happens at
 * all, and that the log records what was announced without narrating the
 * hundreds of sweeps a day that find nothing.
 */
final class MonitorSystemHealthHandlerTest extends TestCase
{
    private FakeAlertProbe $probe;
    private RecordingAlertChannel $channel;
    private SpyLogger $logger;
    private MonitorSystemHealthHandler $handler;

    protected function setUp(): void
    {
        $this->probe = new FakeAlertProbe('health');
        $this->channel = new RecordingAlertChannel();
        $this->logger = new SpyLogger();

        $monitor = new SystemMonitor(
            probes: [$this->probe],
            channels: [$this->channel],
            state: new InMemoryAlertStateStore(),
            logger: new NullLogger(),
        );

        $this->handler = new MonitorSystemHealthHandler($monitor, $this->logger);
    }

    public function testTheSweepRunsAndAnnouncesWhatItFound(): void
    {
        $this->probe->reports(new Alert('mysql', AlertSeverity::CRITICAL, 'mysql is down', ''));

        ($this->handler)(new MonitorSystemHealth());

        self::assertSame(['health:mysql'], $this->channel->sentKeys());
    }

    public function testAnAnnouncementLeavesATrailInTheLog(): void
    {
        $this->probe->reports(new Alert('mysql', AlertSeverity::CRITICAL, 'mysql is down', ''));

        ($this->handler)(new MonitorSystemHealth());

        $record = $this->logger->findByMessage('Operational alerts announced.');
        self::assertNotNull($record);
        self::assertSame('info', $record['level']);
        self::assertSame(['health:mysql'], $record['context']['announced']);
        self::assertSame(['health:mysql'], $record['context']['standing']);
    }

    public function testAQuietSweepSaysNothingAtAll(): void
    {
        ($this->handler)(new MonitorSystemHealth());

        self::assertSame([], $this->logger->records, 'A sweep every five minutes that logged "nothing wrong" would bury the lines that matter.');
    }

    public function testAStandingFailureIsNotRelogged(): void
    {
        $this->probe->reports(new Alert('mysql', AlertSeverity::CRITICAL, 'mysql is down', ''));

        ($this->handler)(new MonitorSystemHealth());
        $this->logger->reset();
        ($this->handler)(new MonitorSystemHealth());

        self::assertSame([], $this->logger->records);
        self::assertCount(1, $this->channel->sent);
    }
}
