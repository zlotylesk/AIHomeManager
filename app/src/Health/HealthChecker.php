<?php

declare(strict_types=1);

namespace App\Health;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Redis;
use RedisException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * HMAI-37: collects up/down status for every infrastructure dependency.
 *
 * Each probe is bounded: MySQL uses a real round-trip via SELECT 1, Redis
 * via PING, RabbitMQ via a TCP connection to the AMQP port parsed from the
 * Messenger transport DSN. The latter avoids pulling in php-amqplib just to
 * answer "is the broker reachable" — the wire-level open is enough for a
 * liveness check.
 */
readonly class HealthChecker
{
    // HMAI-155: disk usage thresholds. 95% = down because MySQL needs headroom
    // for buffer pool flush + binlog before write failures cascade.
    private const float DISK_DEGRADED_RATIO = 0.80;
    private const float DISK_DOWN_RATIO = 0.95;

    public function __construct(
        private Connection $connection,
        #[Autowire(service: 'app.redis')]
        private Redis $redis,
        #[Autowire(env: 'MESSENGER_TRANSPORT_DSN')]
        private string $messengerDsn,
        #[Autowire(service: 'monolog.logger')]
        private LoggerInterface $logger = new NullLogger(),
        private float $rabbitMqTimeoutSeconds = 1.0,
    ) {
    }

    /**
     * @return array<string, string> component => 'up' | 'degraded' | 'down'
     */
    public function check(): array
    {
        return [
            'mysql' => $this->probe(fn () => $this->connection->executeQuery('SELECT 1'), 'mysql'),
            'redis' => $this->probe(fn () => $this->pingRedis(), 'redis'),
            'rabbitmq' => $this->probe(fn () => $this->openRabbitMqSocket(), 'rabbitmq'),
            'disk' => $this->checkDisk(),
        ];
    }

    public function checkDisk(): string
    {
        $free = @disk_free_space('/');
        $total = @disk_total_space('/');
        if (false === $free || false === $total || $total <= 0.0) {
            return 'down';
        }

        $usedRatio = 1.0 - ($free / $total);
        if ($usedRatio >= self::DISK_DOWN_RATIO) {
            return 'down';
        }
        if ($usedRatio >= self::DISK_DEGRADED_RATIO) {
            return 'degraded';
        }

        return 'up';
    }

    private function probe(callable $check, string $component): string
    {
        try {
            $check();

            return 'up';
        } catch (Throwable $e) {
            $this->logger->warning('Health check failed', [
                'component' => $component,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return 'down';
        }
    }

    private function pingRedis(): void
    {
        $result = $this->redis->ping();

        // `PING` returns string '+PONG' or bool true depending on phpredis mode.
        // Anything falsy means the connection round-trip did not complete.
        if (false === $result || '' === $result) {
            throw new RedisException('Redis PING returned no response');
        }
    }

    private function openRabbitMqSocket(): void
    {
        $parts = parse_url($this->messengerDsn);
        if (!is_array($parts) || !isset($parts['host'])) {
            throw new RuntimeException(sprintf('Cannot parse host from MESSENGER_TRANSPORT_DSN: %s', $this->messengerDsn));
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? 5672;

        $socket = @fsockopen($host, $port, $errno, $errstr, $this->rabbitMqTimeoutSeconds);
        if (false === $socket) {
            throw new RuntimeException(sprintf('RabbitMQ unreachable at %s:%d (%d %s)', $host, $port, $errno, $errstr));
        }

        fclose($socket);
    }
}
