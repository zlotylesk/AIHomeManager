<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use App\Health\DiskUsageReaderInterface;
use App\Health\HealthChecker;
use App\Health\WorkerHeartbeat;
use App\Tests\Support\SpyLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Result;
use OpenSearch\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Redis;
use RedisException;
use RuntimeException;

final class HealthCheckerTest extends TestCase
{
    private const string DSN = 'amqp://guest:guest@127.0.0.1:1/%2f/messages';
    private const string DB_DIR = '/var/lib/mysql';
    private const string BACKUP_DIR = '/backups';

    public function testReportsMysqlDownWhenConnectionThrows(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException(new class('mysql gone') extends RuntimeException implements DriverException {
            public function getSQLState(): ?string
            {
                return null;
            }
        });

        $result = $this->checker(connection: $connection)->check();

        self::assertSame('down', $result['mysql']);
        self::assertSame('up', $result['redis']);
    }

    public function testReportsRedisDownWhenPingThrows(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willThrowException(new RedisException('lost connection'));

        $result = $this->checker(redis: $redis)->check();

        self::assertSame('up', $result['mysql']);
        self::assertSame('down', $result['redis']);
    }

    public function testReportsRedisDownWhenPingReturnsFalse(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willReturn(false);

        self::assertSame('down', $this->checker(redis: $redis)->check()['redis']);
    }

    public function testReportsRabbitMqDownWhenSocketUnreachable(): void
    {
        self::assertSame('down', $this->checker()->check()['rabbitmq']);
    }

    public function testReportsRabbitMqDownWhenDsnHasNoHost(): void
    {
        self::assertSame('down', $this->checker(messengerDsn: 'not-a-valid-dsn')->check()['rabbitmq']);
    }

    public function testReportsSearchUpWhenPingSucceeds(): void
    {
        self::assertSame('up', $this->checker()->check()['search']);
    }

    public function testReportsSearchDegradedWhenPingReturnsFalse(): void
    {
        $search = $this->createStub(Client::class);
        $search->method('ping')->willReturn(false);

        // 'degraded', never 'down': search falls back to the FULLTEXT adapter, so
        // an engine outage must not fail the whole readiness probe.
        self::assertSame('degraded', $this->checker(search: $search)->check()['search']);
    }

    public function testReportsSearchDegradedWhenPingThrows(): void
    {
        $search = $this->createStub(Client::class);
        $search->method('ping')->willThrowException(new RuntimeException('no nodes available'));

        self::assertSame('degraded', $this->checker(search: $search)->check()['search']);
    }

    /**
     * The two filesystems whose exhaustion actually stops something, measured
     * separately — the PHP container's own root layer, which this used to
     * measure, is neither of them.
     */
    public function testTheDiskProbeMeasuresTheDatabaseDirectoryAndTheBackupDirectory(): void
    {
        $measured = [];
        $reader = $this->createStub(DiskUsageReaderInterface::class);
        $reader->method('usedRatio')->willReturnCallback(
            static function (string $path) use (&$measured): float {
                $measured[] = $path;

                return 0.1;
            },
        );

        $this->checker(disk: $reader)->check();

        self::assertSame([self::DB_DIR, self::BACKUP_DIR], $measured);
    }

    public function testCheckReportsTheTwoDiskLocationsSeparately(): void
    {
        $result = $this->checker(disk: $this->diskAt([self::DB_DIR => 0.96, self::BACKUP_DIR => 0.10]))->check();

        // Which one filled up is the whole point of splitting them: a single
        // reading would say "the disk is full" while the operator had no way to
        // tell whether pruning old dumps would help.
        self::assertSame('down', $result['disk_database']);
        self::assertSame('up', $result['disk_backups']);
    }

    public function testAFullBackupDirectoryDoesNotIncriminateTheDatabaseVolume(): void
    {
        $result = $this->checker(disk: $this->diskAt([self::DB_DIR => 0.10, self::BACKUP_DIR => 0.96]))->check();

        self::assertSame('up', $result['disk_database']);
        self::assertSame('degraded', $result['disk_backups']);
    }

    /**
     * The backup filesystem follows the rule `search` and `worker` set: the
     * instance is still serving every request it is asked to, and a 503 would
     * take it out of rotation without freeing a byte. What a full backup
     * filesystem actually costs — a dump that goes missing, short or stale — is
     * announced as critical by the `backup:*` probes, which measure the outcome
     * rather than the cause.
     */
    public function testAFullBackupFilesystemIsNeverReportedDown(): void
    {
        foreach ([0.95, 0.99, 1.0] as $ratio) {
            $result = $this->checker(disk: $this->diskAt([self::DB_DIR => 0.10, self::BACKUP_DIR => $ratio]))->check();

            self::assertSame('degraded', $result['disk_backups'], sprintf('A backup filesystem at %s must not 503 an instance that is serving.', $ratio));
        }
    }

    /**
     * The database's own volume keeps `down`, and that asymmetry is the point:
     * with no headroom there MySQL cannot flush or write binlogs, so the
     * instance is about to stop working rather than merely about to miss a
     * backup.
     */
    public function testAFullDatabaseVolumeStillTakesTheInstanceOutOfRotation(): void
    {
        $result = $this->checker(disk: $this->diskAt([self::DB_DIR => 0.96, self::BACKUP_DIR => 0.10]))->check();

        self::assertSame('down', $result['disk_database']);
    }

    /**
     * The 80 % / 95 % thresholds, unchanged, pinned on both sides of each
     * boundary rather than only inside the bands.
     *
     * @param float  $usedRatio        how full the filesystem is
     * @param string $expectedDatabase 'up' | 'degraded' | 'down'
     * @param string $expectedBackups  'up' | 'degraded' — never 'down'
     */
    #[DataProvider('diskThresholds')]
    public function testTheDiskThresholdsAreEightyAndNinetyFivePercent(float $usedRatio, string $expectedDatabase, string $expectedBackups): void
    {
        $checker = $this->checker(disk: $this->diskAt([self::DB_DIR => $usedRatio, self::BACKUP_DIR => $usedRatio]));

        $result = $checker->check();

        self::assertSame($expectedDatabase, $result['disk_database']);
        self::assertSame($expectedBackups, $result['disk_backups']);
    }

    /** @return iterable<string, array{float, string, string}> */
    public static function diskThresholds(): iterable
    {
        yield 'empty' => [0.0, 'up', 'up'];
        yield 'just below the warning' => [0.7999, 'up', 'up'];
        yield 'exactly at the warning' => [0.80, 'degraded', 'degraded'];
        yield 'between the two' => [0.94, 'degraded', 'degraded'];
        yield 'exactly at the critical mark' => [0.95, 'down', 'degraded'];
        yield 'nearly full' => [0.99, 'down', 'degraded'];
    }

    /**
     * A measurement that failed and a filesystem that is full are different
     * situations, and the old code reported both as 'down' — a 503 taking an
     * instance out of rotation because a path was missing.
     */
    public function testAFailedMeasurementIsDegradedRatherThanDown(): void
    {
        $result = $this->checker(disk: $this->diskAt([self::DB_DIR => null, self::BACKUP_DIR => null]))->check();

        self::assertSame('degraded', $result['disk_database']);
        self::assertSame('degraded', $result['disk_backups']);
    }

    public function testAFailedMeasurementIsDistinguishedFromAFullFilesystem(): void
    {
        // Read on the database volume, the location where the two states are
        // actually different values: 'down' for a filesystem measured and found
        // full, 'degraded' for one that could not be measured at all.
        $full = $this->checker(disk: $this->diskAt([self::DB_DIR => 0.96, self::BACKUP_DIR => 0.10]))->check();
        $unmeasurable = $this->checker(disk: $this->diskAt([self::DB_DIR => null, self::BACKUP_DIR => 0.10]))->check();

        self::assertSame('down', $full['disk_database'], 'A full filesystem is still reported as full.');
        self::assertSame('degraded', $unmeasurable['disk_database'], 'An unmeasurable filesystem is not a full one.');
    }

    /**
     * Degrading quietly would be its own version of the bug this closes: a
     * probe reporting on a filesystem it never managed to look at.
     */
    public function testAFailedMeasurementIsLoggedWithThePathItCouldNotRead(): void
    {
        $logger = new SpyLogger();

        $this->checker(disk: $this->diskAt([self::DB_DIR => null, self::BACKUP_DIR => 0.10]), logger: $logger)->check();

        $record = $logger->findByMessage('Health check degraded');
        self::assertNotNull($record, 'A measurement that could not be taken must say so.');
        self::assertSame('warning', $record['level']);
        self::assertSame('disk_database', $record['context']['component']);
        self::assertSame(self::DB_DIR, $record['context']['path']);
    }

    public function testASuccessfulMeasurementIsNotLogged(): void
    {
        $logger = new SpyLogger();

        $this->checker(disk: $this->diskAt([self::DB_DIR => 0.96, self::BACKUP_DIR => 0.85]), logger: $logger)->check();

        self::assertNull($logger->findByMessage('Health check degraded'), 'A filesystem that was measured and found full is not a failed measurement.');
    }

    public function testReportsWorkerUpWhenBothTransportsBeatRecently(): void
    {
        self::assertSame('up', $this->checker()->check()['worker']);
    }

    public function testReportsWorkerDegradedWhenTheSchedulerHasNotBeatenInTooLong(): void
    {
        // The async worker is fine; only the scheduler has gone quiet. This is
        // the shape of the failure that stopped the nightly backup, and it is
        // invisible to every other probe: the broker is reachable, and the
        // scheduler transport never touches the broker in the first place.
        $checker = $this->checker(workers: $this->workersLastSeen(['async' => time(), 'scheduler_default' => time() - 3600]));

        self::assertSame('degraded', $checker->check()['worker']);
    }

    public function testReportsWorkerDegradedWhenNothingHasEverBeaten(): void
    {
        self::assertSame('degraded', $this->checker(workers: $this->workersLastSeen([]))->check()['worker']);
    }

    public function testWorkerIsNeverReportedDown(): void
    {
        // Same rule the 'search' component set: the instance still serves every
        // request it is asked to, so a dead worker must not 503 it out of
        // rotation — that would remove the half that works without restoring
        // the half that does not.
        self::assertNotSame('down', $this->checker(workers: $this->workersLastSeen([]))->check()['worker']);
    }

    public function testAWorkerStillBusyOnOneLongMessageIsNotYetCalledDead(): void
    {
        // A beat is recorded when a message is picked up, so a job running for
        // four minutes is still inside the window. Pins the tolerance the
        // threshold was chosen for, not just the threshold itself.
        $justInside = time() - 240;

        $checker = $this->checker(workers: $this->workersLastSeen(['async' => $justInside, 'scheduler_default' => $justInside]));

        self::assertSame('up', $checker->check()['worker']);
    }

    private function checker(
        ?Connection $connection = null,
        ?Redis $redis = null,
        string $messengerDsn = self::DSN,
        ?Client $search = null,
        ?WorkerHeartbeat $workers = null,
        ?DiskUsageReaderInterface $disk = null,
        ?LoggerInterface $logger = null,
    ): HealthChecker {
        return new HealthChecker(
            $connection ?? $this->upConnection(),
            $redis ?? $this->upRedis(),
            $messengerDsn,
            $search ?? $this->reachableSearch(),
            $workers ?? $this->liveWorkers(),
            $disk ?? $this->diskAt([self::DB_DIR => 0.10, self::BACKUP_DIR => 0.10]),
            self::DB_DIR,
            self::BACKUP_DIR,
            $logger ?? new NullLogger(),
        );
    }

    /** @param array<string, float|null> $ratios path => used fraction, null = could not be measured */
    private function diskAt(array $ratios): DiskUsageReaderInterface
    {
        $reader = $this->createStub(DiskUsageReaderInterface::class);
        $reader->method('usedRatio')->willReturnCallback(
            static fn (string $path): ?float => $ratios[$path] ?? null,
        );

        return $reader;
    }

    private function upConnection(): Connection
    {
        $connection = $this->createStub(Connection::class);
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
