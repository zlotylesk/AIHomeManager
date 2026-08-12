<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitoring\Probe;

use App\Infrastructure\Backup\Destination\BackupDestinationInterface;
use App\Infrastructure\Backup\Destination\RemoteBackup;
use App\Monitoring\AlertSeverity;
use App\Monitoring\Probe\BackupOffsiteProbe;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackupOffsiteProbeTest extends TestCase
{
    private const string NOW = '2026-08-12 06:00:00';

    public function testSaysNothingWhenTheNewestCopyIsRecentAndPlausible(): void
    {
        $probe = $this->probe($this->destinationHolding(
            new RemoteBackup('homemanager-2026-08-12.sql.gz.enc', 400000, new DateTimeImmutable('2026-08-12')),
        ));

        self::assertSame([], $probe->probe(new DateTimeImmutable(self::NOW)));
    }

    public function testReportsAnEmptyDestination(): void
    {
        $alerts = $this->probe($this->destinationHolding())->probe(new DateTimeImmutable(self::NOW));

        self::assertCount(1, $alerts);
        self::assertSame('missing', $alerts[0]->key);
        self::assertSame(AlertSeverity::CRITICAL, $alerts[0]->severity);
    }

    public function testReportsACopyThatHasStoppedArriving(): void
    {
        $alerts = $this->probe($this->destinationHolding(
            new RemoteBackup('homemanager-2026-08-01.sql.gz.enc', 400000, new DateTimeImmutable('2026-08-01')),
        ))->probe(new DateTimeImmutable(self::NOW));

        self::assertCount(1, $alerts);
        self::assertSame('stale', $alerts[0]->key);
        self::assertStringContainsString('270 h old', $alerts[0]->title);
    }

    public function testReportsATruncatedUpload(): void
    {
        $alerts = $this->probe($this->destinationHolding(
            new RemoteBackup('homemanager-2026-08-12.sql.gz.enc', 20, new DateTimeImmutable('2026-08-12')),
        ))->probe(new DateTimeImmutable(self::NOW));

        self::assertCount(1, $alerts);
        self::assertSame('empty', $alerts[0]->key);
    }

    /**
     * A destination that cannot be read is its own state, not "no backups
     * there". Folding the two together would report an unreachable NAS as an
     * empty one and send the operator to look for a missing upload instead of a
     * dropped mount.
     */
    public function testReportsADestinationThatCannotBeRead(): void
    {
        $destination = $this->createStub(BackupDestinationInterface::class);
        $destination->method('isConfigured')->willReturn(true);
        $destination->method('name')->willReturn('directory');
        $destination->method('describe')->willReturn('/mnt/offsite');
        $destination->method('listBackups')->willThrowException(new RuntimeException('mount is gone'));

        $alerts = $this->probe($destination)->probe(new DateTimeImmutable(self::NOW));

        self::assertCount(1, $alerts);
        self::assertSame('unreachable', $alerts[0]->key);
        self::assertStringContainsString('mount is gone', $alerts[0]->detail);
    }

    /**
     * Off-host backups being switched off is a configuration, not a fault. If it
     * alerted, every development instance would sit permanently in alarm — and an
     * alert that is always firing is one people stop reading, which costs the
     * alerts that matter.
     */
    public function testSaysNothingWhenOffHostCopiesAreDeliberatelyDisabled(): void
    {
        $destination = $this->createStub(BackupDestinationInterface::class);
        $destination->method('isConfigured')->willReturn(false);

        self::assertSame([], $this->probe($destination)->probe(new DateTimeImmutable(self::NOW)));
    }

    /**
     * Some rclone backends report no size at all. Reading that as zero would hold
     * the "too small" alert open forever on those remotes, and an alert that can
     * never clear devalues every alert beside it.
     */
    public function testTreatsAnUnknownSizeAsUnknownRatherThanEmpty(): void
    {
        $alerts = $this->probe($this->destinationHolding(
            new RemoteBackup('homemanager-2026-08-12.sql.gz.enc', -1, new DateTimeImmutable('2026-08-12')),
        ))->probe(new DateTimeImmutable(self::NOW));

        self::assertSame([], $alerts);
    }

    public function testJudgesTheNewestCopyRatherThanTheFirstListed(): void
    {
        $alerts = $this->probe($this->destinationHolding(
            new RemoteBackup('homemanager-2026-08-12.sql.gz.enc', 400000, new DateTimeImmutable('2026-08-12')),
            new RemoteBackup('homemanager-2026-01-01.sql.gz.enc', 20, new DateTimeImmutable('2026-01-01')),
        ))->probe(new DateTimeImmutable(self::NOW));

        self::assertSame([], $alerts);
    }

    private function probe(BackupDestinationInterface&Stub $destination): BackupOffsiteProbe
    {
        return new BackupOffsiteProbe($destination, maxAgeHours: 48, minBytes: 1024);
    }

    private function destinationHolding(RemoteBackup ...$backups): BackupDestinationInterface&Stub
    {
        $destination = $this->createStub(BackupDestinationInterface::class);
        $destination->method('isConfigured')->willReturn(true);
        $destination->method('name')->willReturn('directory');
        $destination->method('describe')->willReturn('/mnt/offsite');
        $destination->method('listBackups')->willReturn(array_values($backups));

        return $destination;
    }
}
