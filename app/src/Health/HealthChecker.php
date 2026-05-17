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
     * @return array<string, string> component => 'up' | 'down'
     */
    public function check(): array
    {
        return [
            'mysql' => $this->probe(fn () => $this->connection->executeQuery('SELECT 1'), 'mysql'),
            'redis' => $this->probe(fn () => $this->pingRedis(), 'redis'),
            'rabbitmq' => $this->probe(fn () => $this->openRabbitMqSocket(), 'rabbitmq'),
        ];
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
        try {
            $result = $this->redis->ping();
        } catch (RedisException $e) {
            throw $e;
        }

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

        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? (int) $parts['port'] : 5672;

        $socket = @fsockopen($host, $port, $errno, $errstr, $this->rabbitMqTimeoutSeconds);
        if (false === $socket) {
            throw new RuntimeException(sprintf('RabbitMQ unreachable at %s:%d (%d %s)', $host, $port, $errno, $errstr));
        }

        fclose($socket);
    }
}
