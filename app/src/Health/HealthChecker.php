<?php

declare(strict_types=1);

namespace App\Health;

use Doctrine\DBAL\Connection;
use OpenSearch\Client;
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
    /**
     * Applied per measured location, not to the two of them together: a
     * database volume at 96 % is critical however much room the backup
     * directory still has, and averaging them would hide exactly that.
     */
    private const float DISK_DEGRADED_RATIO = 0.80;
    private const float DISK_DOWN_RATIO = 0.95;

    /**
     * How stale a worker's last heartbeat may be before it counts as gone.
     *
     * Five minutes rather than one: an idle worker beats every few seconds, so
     * anything under a minute would be ample — but a worker handling a single
     * slow message (a full Trakt import) stops beating for the duration, and a
     * probe that reports a busy worker as dead is worse than one that takes a
     * few minutes to notice a real death. The failure this exists to catch was
     * measured in days.
     */
    private const int WORKER_HEARTBEAT_MAX_AGE_SECONDS = 300;

    /**
     * The workers that are expected to be running — the two `docker compose`
     * services, named by the transport each consumes.
     *
     * `scheduler_default` is the important one and the reason this probe is not
     * a broker question: it is a Symfony Scheduler transport, invisible to
     * RabbitMQ, and it is the worker whose silence stopped the nightly backup.
     */
    private const array WORKER_TRANSPORTS = ['async', 'scheduler_default'];

    public function __construct(
        private Connection $connection,
        #[Autowire(service: 'app.redis')]
        private Redis $redis,
        #[Autowire(env: 'MESSENGER_TRANSPORT_DSN')]
        private string $messengerDsn,
        #[Autowire(service: 'app.search_client')]
        private Client $searchClient,
        private WorkerHeartbeat $workerHeartbeat,
        private DiskUsageReaderInterface $diskUsage,
        #[Autowire(env: 'DATABASE_DATA_DIR')]
        private string $databaseDataDir,
        #[Autowire(env: 'BACKUP_DIR')]
        private string $backupDir,
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
            'search' => $this->checkSearch(),
            'worker' => $this->checkWorker(),
            'disk_database' => $this->checkDatabaseDisk(),
            'disk_backups' => $this->checkBackupDisk(),
        ];
    }

    /**
     * Asynchronous processing (HMAI-418).
     *
     * The `rabbitmq` probe above opens a socket to the broker, which answers
     * exactly the same whether or not anything is consuming — so an instance
     * with both workers dead reported five green components while every import,
     * notification, search index update and the nightly backup silently stopped.
     * This reads the heartbeat the workers write for themselves.
     *
     * `degraded`, never `down`, matching the rule the `search` component
     * established: the instance still serves every request it is asked to, and
     * taking it out of rotation would remove the half that still works without
     * bringing back the half that does not. What is broken here is fixed by
     * restarting a worker, not by shifting traffic.
     */
    public function checkWorker(): string
    {
        $now = time();

        foreach (self::WORKER_TRANSPORTS as $transport) {
            $lastSeen = $this->workerHeartbeat->lastSeen($transport);

            if (null === $lastSeen || ($now - $lastSeen) > self::WORKER_HEARTBEAT_MAX_AGE_SECONDS) {
                $this->logger->warning('Health check degraded', [
                    'component' => 'worker',
                    'transport' => $transport,
                    'last_seen' => $lastSeen,
                    'max_age_seconds' => self::WORKER_HEARTBEAT_MAX_AGE_SECONDS,
                ]);

                return 'degraded';
            }
        }

        return 'up';
    }

    /**
     * Search-engine readiness (HMAI-360, epic HMAI-359). Unlike the core
     * dependencies, an unreachable engine is reported as 'degraded' (HTTP 200),
     * never 'down': global search falls back to the MySQL FULLTEXT adapter, so a
     * search outage must not fail the whole readiness probe and take the app out
     * of rotation.
     */
    public function checkSearch(): string
    {
        try {
            return $this->searchClient->ping() ? 'up' : 'degraded';
        } catch (Throwable $e) {
            $this->logger->warning('Health check failed', [
                'component' => 'search',
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return 'degraded';
        }
    }

    /**
     * The filesystem holding the database's data directory — the one whose last
     * free byte stops MySQL flushing and writing binlogs, which is what this
     * probe has always claimed to be about.
     *
     * It is measured through a path rather than assumed, because the data lives
     * in a named volume: the PHP container's own root filesystem is its image
     * layer and has nothing to do with it. On a single-machine install the two
     * happen to sit on the same device, which is precisely why measuring the
     * wrong one went unnoticed — and stops being true the moment the database
     * gets a volume or a partition of its own, which is a normal step in
     * standing production up.
     */
    public function checkDatabaseDisk(): string
    {
        return $this->checkDiskAt($this->databaseDataDir, 'disk_database', 'down');
    }

    /**
     * The backup directory, reported separately from the database's own volume
     * because the answer to "which one filled up" decides what to do: pruning
     * old dumps fixes one of them and does nothing for the other. Off-host
     * copies are a different matter again and belong to BackupOffsiteProbe.
     *
     * **`degraded`, never `down`** — the third component to follow the rule
     * `search` and `worker` set. A full backup filesystem stops tonight's dump;
     * it stops nothing the instance is being asked to do right now, and a 503
     * would take a perfectly working instance out of rotation without freeing a
     * single byte. It is a warning that arrives *before* the failure rather
     * than instead of it: when a dump does go missing, short or stale, the
     * `backup:*` probes announce that as critical.
     */
    public function checkBackupDisk(): string
    {
        return $this->checkDiskAt($this->backupDir, 'disk_backups', 'degraded');
    }

    /**
     * A failed measurement is 'degraded', never 'down'.
     *
     * Not knowing how much space is left is not the same as knowing there is
     * none: an unreadable path is a missing mount or a configuration mistake,
     * and answering it with 'down' 503s an instance that is serving every
     * request perfectly well. It still has to be said out loud, though — a
     * silent 'up' would be a probe reporting on a filesystem it never looked
     * at — so it degrades and logs.
     *
     * @param string $whenFull what crossing DISK_DOWN_RATIO means for THIS
     *                         location — 'down' where the instance stops
     *                         working, 'degraded' where only something later
     *                         does
     */
    private function checkDiskAt(string $path, string $component, string $whenFull): string
    {
        $usedRatio = $this->diskUsage->usedRatio($path);

        if (null === $usedRatio) {
            $this->logger->warning('Health check degraded', [
                'component' => $component,
                'path' => $path,
                'reason' => 'free space could not be measured',
            ]);

            return 'degraded';
        }

        if ($usedRatio >= self::DISK_DOWN_RATIO) {
            return $whenFull;
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
