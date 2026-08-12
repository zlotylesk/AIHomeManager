<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitoring;

use App\Health\DiskUsageReaderInterface;
use App\Health\HealthChecker;
use App\Health\WorkerHeartbeat;
use App\Monitoring\EmailAlertChannel;
use App\Monitoring\FileAlertStateStore;
use App\Monitoring\Probe\BackupFreshnessProbe;
use App\Monitoring\Probe\HealthComponentProbe;
use App\Monitoring\SystemMonitor;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use OpenSearch\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Redis;
use RedisException;
use RuntimeException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * The acceptance criterion this whole path exists for: an alert about an
 * unavailable database has to arrive while the database is unavailable.
 *
 * That is why operational alerting does not go through the Notifications
 * module's dispatch engine. That engine reads a preference row and writes a
 * notification row before sending — correct for user notifications, and
 * unable to announce the very outage that stops it working. Here everything is
 * wired for real and every shared dependency is broken at once; the e-mails
 * still have to come out, and the dedup state still has to be recorded.
 *
 * A test that can go red: give {@see FileAlertStateStore} a
 * database-backed sibling, or route these alerts through `DispatchNotification`,
 * and it fails here rather than during the next real outage.
 */
final class AlertDeliveryIndependenceTest extends TestCase
{
    /** Nothing is listening on port 1, so the broker probe fails fast. */
    private const string UNREACHABLE_BROKER = 'amqp://guest:guest@127.0.0.1:1/%2f/messages';

    private string $backupDir;
    private string $stateFile;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir().'/aihm-independence-'.bin2hex(random_bytes(6));
        $this->backupDir = $root.'/backups';
        $this->stateFile = $root.'/alert-state.json';
        mkdir($this->backupDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->stateFile] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach ([$this->backupDir, \dirname($this->stateFile)] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testAlertsGoOutWhileMysqlRedisAndTheBrokerAreAllUnreachable(): void
    {
        /** @var list<Email> $sent */
        $sent = [];
        $monitor = $this->monitorSendingInto($sent);

        $monitor->run(new DateTimeImmutable('2026-08-11 09:00:00'));

        $subjects = array_map(static fn (Email $email): string => (string) $email->getSubject(), $sent);

        self::assertContains('[AIHM] CRITICAL — Health component "mysql" is down', $subjects, 'The one alert that must survive its own subject being broken.');
        self::assertContains('[AIHM] CRITICAL — Health component "redis" is down', $subjects);
        self::assertContains('[AIHM] CRITICAL — Health component "rabbitmq" is down', $subjects);
        self::assertContains('[AIHM] CRITICAL — There is no database backup at all', $subjects);
    }

    public function testDeduplicationStillWorksWithEveryStoreDependencyDown(): void
    {
        /** @var list<Email> $sent */
        $sent = [];
        $monitor = $this->monitorSendingInto($sent);

        $monitor->run(new DateTimeImmutable('2026-08-11 09:00:00'));
        $announcedFirst = \count($sent);
        $monitor->run(new DateTimeImmutable('2026-08-11 09:05:00'));

        self::assertGreaterThan(0, $announcedFirst);
        self::assertCount($announcedFirst, $sent, 'The second sweep must add nothing — the "already announced" set lives on local disk precisely so an outage cannot erase it.');
        self::assertFileExists($this->stateFile);
    }

    /**
     * @param list<Email> $sent
     */
    private function monitorSendingInto(array &$sent): SystemMonitor
    {
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(static function (RawMessage $message) use (&$sent): void {
            if ($message instanceof Email) {
                $sent[] = $message;
            }
        });

        return new SystemMonitor(
            probes: [
                new HealthComponentProbe($this->healthCheckerWithEverythingBroken()),
                new BackupFreshnessProbe($this->backupDir, 26, 1024),
            ],
            channels: [new EmailAlertChannel($mailer, 'ops@aihm.test', 'owner@aihm.test')],
            state: new FileAlertStateStore($this->stateFile, new NullLogger()),
            logger: new NullLogger(),
        );
    }

    private function healthCheckerWithEverythingBroken(): HealthChecker
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException(new class('mysql gone') extends RuntimeException implements DriverException {
            public function getSQLState(): ?string
            {
                return null;
            }
        });

        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willThrowException(new RedisException('lost connection'));

        $search = $this->createStub(Client::class);
        $search->method('ping')->willThrowException(new RuntimeException('search gone'));

        $heartbeat = $this->createStub(WorkerHeartbeat::class);
        $heartbeat->method('lastSeen')->willReturn(null);

        // Both measured filesystems unreadable too — "everything broken" has to
        // include the disk probe, or the sweep would be carrying one component
        // that still answers.
        $disk = $this->createStub(DiskUsageReaderInterface::class);
        $disk->method('usedRatio')->willReturn(null);

        return new HealthChecker($connection, $redis, self::UNREACHABLE_BROKER, $search, $heartbeat, $disk, '/var/lib/mysql', '/backups', new NullLogger(), 0.2);
    }
}
