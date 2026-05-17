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
        // Symfony Connection::executeQuery throws a wrapped DBAL Exception when
        // the driver fails. The checker must catch any Throwable so a transient
        // DB failure does not propagate out of the readiness endpoint.
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
        // RedisException can fire on a dropped socket or auth failure. Either
        // way the checker should treat the component as down without crashing
        // the entire health response.
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
        // phpredis returns `false` instead of throwing for some connection
        // states (e.g. server returned an unexpected payload). Guard for it.
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
        // Port 1 on localhost is virtually guaranteed to be closed — fsockopen
        // returns false. The checker must surface that as `down` without
        // breaking other component reports.
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
        // Defensive: a misconfigured DSN must surface as `down`, not a fatal
        // parse error escaping the controller.
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->createStub(\Doctrine\DBAL\Result::class));

        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willReturn('+PONG');

        $checker = new HealthChecker($connection, $redis, 'not-a-valid-dsn', new NullLogger());

        $result = $checker->check();

        self::assertSame('down', $result['rabbitmq']);
    }
}
