<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use App\Health\HealthChecker;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Redis;
use RedisException;
use RuntimeException;

final class HealthCheckerTest extends TestCase
{
    public function testReportsMysqlDownWhenConnectionThrows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willThrowException(new class('mysql gone') extends RuntimeException implements DriverException {
            public function getSQLState(): ?string
            {
                return null;
            }
        });

        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willReturn('+PONG');

        $checker = new HealthChecker($connection, $redis, 'amqp://guest:guest@127.0.0.1:1/%2f/messages', new NullLogger());

        $result = $checker->check();

        self::assertSame('down', $result['mysql']);
        self::assertSame('up', $result['redis']);
    }

    public function testReportsRedisDownWhenPingThrows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->createStub(\Doctrine\DBAL\Result::class));

        $redis = $this->createMock(Redis::class);
        $redis->method('ping')->willThrowException(new RedisException('lost connection'));

        $checker = new HealthChecker($connection, $redis, 'amqp://guest:guest@127.0.0.1:1/%2f/messages', new NullLogger());

        $result = $checker->check();

        self::assertSame('up', $result['mysql']);
        self::assertSame('down', $result['redis']);
    }

    public function testReportsRedisDownWhenPingReturnsFalse(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->createStub(\Doctrine\DBAL\Result::class));

        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willReturn(false);

        $checker = new HealthChecker($connection, $redis, 'amqp://guest:guest@127.0.0.1:1/%2f/messages', new NullLogger());

        $result = $checker->check();

        self::assertSame('down', $result['redis']);
    }

    public function testReportsRabbitMqDownWhenSocketUnreachable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->createStub(\Doctrine\DBAL\Result::class));

        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willReturn('+PONG');

        $checker = new HealthChecker($connection, $redis, 'amqp://guest:guest@127.0.0.1:1/%2f/messages', new NullLogger());

        $result = $checker->check();

        self::assertSame('down', $result['rabbitmq']);
    }

    public function testReportsRabbitMqDownWhenDsnHasNoHost(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->createStub(\Doctrine\DBAL\Result::class));

        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willReturn('+PONG');

        $checker = new HealthChecker($connection, $redis, 'not-a-valid-dsn', new NullLogger());

        $result = $checker->check();

        self::assertSame('down', $result['rabbitmq']);
    }

    public function testCheckDiskReturnsOneOfThreeKnownStates(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->createStub(\Doctrine\DBAL\Result::class));

        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willReturn('+PONG');

        $checker = new HealthChecker($connection, $redis, 'amqp://guest:guest@127.0.0.1:1/%2f/messages', new NullLogger());

        self::assertContains($checker->checkDisk(), ['up', 'degraded', 'down']);
    }

    public function testCheckIncludesDiskComponent(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->createStub(\Doctrine\DBAL\Result::class));

        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willReturn('+PONG');

        $checker = new HealthChecker($connection, $redis, 'amqp://guest:guest@127.0.0.1:1/%2f/messages', new NullLogger());

        self::assertArrayHasKey('disk', $checker->check());
    }
}
