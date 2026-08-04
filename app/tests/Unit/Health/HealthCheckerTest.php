<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use App\Health\HealthChecker;
use App\Health\WorkerHeartbeat;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Result;
use OpenSearch\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Redis;
use RedisException;
use RuntimeException;

final class HealthCheckerTest extends TestCase
{
    private const string DSN = 'amqp://guest:guest@127.0.0.1:1/%2f/messages';

    public function testReportsMysqlDownWhenConnectionThrows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willThrowException(new class('mysql gone') extends RuntimeException implements DriverException {
            public function getSQLState(): ?string
            {
                return null;
            }
        });

        $checker = new HealthChecker($connection, $this->upRedis(), self::DSN, $this->reachableSearch(), $this->liveWorkers(), new NullLogger());

        $result = $checker->check();

        self::assertSame('down', $result['mysql']);
        self::assertSame('up', $result['redis']);
    }

    public function testReportsRedisDownWhenPingThrows(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('ping')->willThrowException(new RedisException('lost connection'));

        $checker = new HealthChecker($this->upConnection(), $redis, self::DSN, $this->reachableSearch(), $this->liveWorkers(), new NullLogger());

        $result = $checker->check();

        self::assertSame('up', $result['mysql']);
        self::assertSame('down', $result['redis']);
    }

    public function testReportsRedisDownWhenPingReturnsFalse(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willReturn(false);

        $checker = new HealthChecker($this->upConnection(), $redis, self::DSN, $this->reachableSearch(), $this->liveWorkers(), new NullLogger());

        self::assertSame('down', $checker->check()['redis']);
    }

    public function testReportsRabbitMqDownWhenSocketUnreachable(): void
    {
        $checker = new HealthChecker($this->upConnection(), $this->upRedis(), self::DSN, $this->reachableSearch(), $this->liveWorkers(), new NullLogger());

        self::assertSame('down', $checker->check()['rabbitmq']);
    }

    public function testReportsRabbitMqDownWhenDsnHasNoHost(): void
    {
        $checker = new HealthChecker($this->upConnection(), $this->upRedis(), 'not-a-valid-dsn', $this->reachableSearch(), $this->liveWorkers(), new NullLogger());

        self::assertSame('down', $checker->check()['rabbitmq']);
    }

    public function testReportsSearchUpWhenPingSucceeds(): void
    {
        $checker = new HealthChecker($this->upConnection(), $this->upRedis(), self::DSN, $this->reachableSearch(), $this->liveWorkers(), new NullLogger());

        self::assertSame('up', $checker->check()['search']);
    }

    public function testReportsSearchDegradedWhenPingReturnsFalse(): void
    {
        $search = $this->createStub(Client::class);
        $search->method('ping')->willReturn(false);

        $checker = new HealthChecker($this->upConnection(), $this->upRedis(), self::DSN, $search, $this->liveWorkers(), new NullLogger());

        // 'degraded', never 'down': search falls back to the FULLTEXT adapter, so
        // an engine outage must not fail the whole readiness probe.
        self::assertSame('degraded', $checker->check()['search']);
    }

    public function testReportsSearchDegradedWhenPingThrows(): void
    {
        $search = $this->createStub(Client::class);
        $search->method('ping')->willThrowException(new RuntimeException('no nodes available'));

        $checker = new HealthChecker($this->upConnection(), $this->upRedis(), self::DSN, $search, $this->liveWorkers(), new NullLogger());

        self::assertSame('degraded', $checker->check()['search']);
    }

    public function testCheckDiskReturnsOneOfThreeKnownStates(): void
    {
        $checker = new HealthChecker($this->upConnection(), $this->upRedis(), self::DSN, $this->reachableSearch(), $this->liveWorkers(), new NullLogger());

        self::assertContains($checker->checkDisk(), ['up', 'degraded', 'down']);
    }

    public function testCheckIncludesDiskAndSearchComponents(): void
    {
        $checker = new HealthChecker($this->upConnection(), $this->upRedis(), self::DSN, $this->reachableSearch(), $this->liveWorkers(), new NullLogger());

        $result = $checker->check();
        self::assertArrayHasKey('disk', $result);
        self::assertArrayHasKey('search', $result);
    }

    public function testReportsWorkerUpWhenBothTransportsBeatRecently(): void
    {
        $checker = new HealthChecker($this->upConnection(), $this->upRedis(), self::DSN, $this->reachableSearch(), $this->liveWorkers(), new NullLogger());

        self::assertSame('up', $checker->check()['worker']);
    }

    public function testReportsWorkerDegradedWhenTheSchedulerHasNotBeatenInTooLong(): void
    {
        // The async worker is fine; only the scheduler has gone quiet. This is
        // the shape of the failure that stopped the nightly backup, and it is
        // invisible to every other probe: the broker is reachable, and the
        // scheduler transport never touches the broker in the first place.
        $checker = new HealthChecker(
            $this->upConnection(),
            $this->upRedis(),
            self::DSN,
            $this->reachableSearch(),
            $this->workersLastSeen(['async' => time(), 'scheduler_default' => time() - 3600]),
            new NullLogger(),
        );

        self::assertSame('degraded', $checker->check()['worker']);
    }

    public function testReportsWorkerDegradedWhenNothingHasEverBeaten(): void
    {
        $checker = new HealthChecker(
            $this->upConnection(),
            $this->upRedis(),
            self::DSN,
            $this->reachableSearch(),
            $this->workersLastSeen([]),
            new NullLogger(),
        );

        self::assertSame('degraded', $checker->check()['worker']);
    }

    public function testWorkerIsNeverReportedDown(): void
    {
        // Same rule the 'search' component set: the instance still serves every
        // request it is asked to, so a dead worker must not 503 it out of
        // rotation — that would remove the half that works without restoring
        // the half that does not.
        $checker = new HealthChecker(
            $this->upConnection(),
            $this->upRedis(),
            self::DSN,
            $this->reachableSearch(),
            $this->workersLastSeen([]),
            new NullLogger(),
        );

        self::assertNotSame('down', $checker->check()['worker']);
    }

    public function testAWorkerStillBusyOnOneLongMessageIsNotYetCalledDead(): void
    {
        // A beat is recorded when a message is picked up, so a job running for
        // four minutes is still inside the window. Pins the tolerance the
        // threshold was chosen for, not just the threshold itself.
        $justInside = time() - 240;

        $checker = new HealthChecker(
            $this->upConnection(),
            $this->upRedis(),
            self::DSN,
            $this->reachableSearch(),
            $this->workersLastSeen(['async' => $justInside, 'scheduler_default' => $justInside]),
            new NullLogger(),
        );

        self::assertSame('up', $checker->check()['worker']);
    }

    private function upConnection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->createStub(Result::class));

        return $connection;
    }

    private function upRedis(): Redis
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willReturn('+PONG');

        return $redis;
    }

    private function reachableSearch(): Client
    {
        $search = $this->createStub(Client::class);
        $search->method('ping')->willReturn(true);

        return $search;
    }

    private function liveWorkers(): WorkerHeartbeat
    {
        $now = time();

        return $this->workersLastSeen(['async' => $now, 'scheduler_default' => $now]);
    }

    /** @param array<string, int> $lastSeen transport => unix time, absent = never seen */
    private function workersLastSeen(array $lastSeen): WorkerHeartbeat
    {
        $heartbeat = $this->createStub(WorkerHeartbeat::class);
        $heartbeat->method('lastSeen')->willReturnCallback(
            static fn (string $transport): ?int => $lastSeen[$transport] ?? null,
        );

        return $heartbeat;
    }
}
