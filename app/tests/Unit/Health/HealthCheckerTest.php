<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use App\Health\HealthChecker;
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

        $checker = new HealthChecker($connection, $this->upRedis(), self::DSN, $this->reachableSearch(), new NullLogger());

        $result = $checker->check();

        self::assertSame('down', $result['mysql']);
        self::assertSame('up', $result['redis']);
    }

    public function testReportsRedisDownWhenPingThrows(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('ping')->willThrowException(new RedisException('lost connection'));

        $checker = new HealthChecker($this->upConnection(), $redis, self::DSN, $this->reachableSearch(), new NullLogger());

        $result = $checker->check();

        self::assertSame('up', $result['mysql']);
        self::assertSame('down', $result['redis']);
    }

    public function testReportsRedisDownWhenPingReturnsFalse(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willReturn(false);

        $checker = new HealthChecker($this->upConnection(), $redis, self::DSN, $this->reachableSearch(), new NullLogger());

        self::assertSame('down', $checker->check()['redis']);
    }

    public function testReportsRabbitMqDownWhenSocketUnreachable(): void
    {
        $checker = new HealthChecker($this->upConnection(), $this->upRedis(), self::DSN, $this->reachableSearch(), new NullLogger());

        self::assertSame('down', $checker->check()['rabbitmq']);
    }

    public function testReportsRabbitMqDownWhenDsnHasNoHost(): void
    {
        $checker = new HealthChecker($this->upConnection(), $this->upRedis(), 'not-a-valid-dsn', $this->reachableSearch(), new NullLogger());

        self::assertSame('down', $checker->check()['rabbitmq']);
    }

    public function testReportsSearchUpWhenPingSucceeds(): void
    {
        $checker = new HealthChecker($this->upConnection(), $this->upRedis(), self::DSN, $this->reachableSearch(), new NullLogger());

        self::assertSame('up', $checker->check()['search']);
    }

    public function testReportsSearchDegradedWhenPingReturnsFalse(): void
    {
        $search = $this->createStub(Client::class);
        $search->method('ping')->willReturn(false);

        $checker = new HealthChecker($this->upConnection(), $this->upRedis(), self::DSN, $search, new NullLogger());

        // 'degraded', never 'down': search falls back to the FULLTEXT adapter, so
        // an engine outage must not fail the whole readiness probe.
        self::assertSame('degraded', $checker->check()['search']);
    }

    public function testReportsSearchDegradedWhenPingThrows(): void
    {
        $search = $this->createStub(Client::class);
        $search->method('ping')->willThrowException(new RuntimeException('no nodes available'));

        $checker = new HealthChecker($this->upConnection(), $this->upRedis(), self::DSN, $search, new NullLogger());

        self::assertSame('degraded', $checker->check()['search']);
    }

    public function testCheckDiskReturnsOneOfThreeKnownStates(): void
    {
        $checker = new HealthChecker($this->upConnection(), $this->upRedis(), self::DSN, $this->reachableSearch(), new NullLogger());

        self::assertContains($checker->checkDisk(), ['up', 'degraded', 'down']);
    }

    public function testCheckIncludesDiskAndSearchComponents(): void
    {
        $checker = new HealthChecker($this->upConnection(), $this->upRedis(), self::DSN, $this->reachableSearch(), new NullLogger());

        $result = $checker->check();
        self::assertArrayHasKey('disk', $result);
        self::assertArrayHasKey('search', $result);
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
}
