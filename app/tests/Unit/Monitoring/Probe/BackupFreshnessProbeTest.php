<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitoring\Probe;

use App\Monitoring\Alert;
use App\Monitoring\AlertSeverity;
use App\Monitoring\Probe\BackupFreshnessProbe;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BackupFreshnessProbeTest extends TestCase
{
    /** The same threshold `scripts/doctor.sh` uses, and for the same reason. */
    private const int MAX_AGE_HOURS = 48;
    private const int MIN_BYTES = 1024;
    private const string NOW = '2026-08-11 09:00:00';

    private string $backupDir;

    protected function setUp(): void
    {
        $this->backupDir = sys_get_temp_dir().'/aihm-backup-probe-'.bin2hex(random_bytes(6));
        mkdir($this->backupDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->backupDir)) {
            rmdir($this->backupDir);
        }
    }

    public function testAFreshAndPlausibleDumpIsNothingToSay(): void
    {
        $this->writeBackup('2026-08-11', bytes: 400_000);

        self::assertSame([], $this->probe());
    }

    public function testNoBackupDirectoryAtAllIsCritical(): void
    {
        rmdir($this->backupDir);

        $alerts = $this->probe();

        self::assertCount(1, $alerts);
        self::assertSame('missing', $alerts[0]->key);
        self::assertSame(AlertSeverity::CRITICAL, $alerts[0]->severity);
    }

    public function testAnEmptyBackupDirectoryIsCritical(): void
    {
        self::assertSame('missing', $this->probe()[0]->key);
    }

    public function testADumpOlderThanTheLimitIsReportedAsStale(): void
    {
        $this->writeBackup('2026-08-04', bytes: 400_000);

        $alerts = $this->probe();

        self::assertSame('stale', $alerts[0]->key);
        self::assertSame(AlertSeverity::CRITICAL, $alerts[0]->severity);
        self::assertStringContainsString('177 h old', $alerts[0]->title);
    }

    public function testYesterdaysDumpIsStillAcceptable(): void
    {
        $this->writeBackup('2026-08-10', bytes: 400_000);

        self::assertSame([], $this->probe(), 'The 03:00 job fires late on a host that sleeps; a check that cries wolf on an ordinary day stops being read.');
    }

    /**
     * The failure that cost six days of backups: `mysqldump` died, `gzip`
     * succeeded on its empty input, and a twenty-byte file sat on disk looking
     * exactly like a backup.
     */
    public function testAFreshDumpTooSmallToContainAnythingIsCritical(): void
    {
        $this->writeBackup('2026-08-11', bytes: 20);

        $alerts = $this->probe();

        self::assertSame('empty', $alerts[0]->key);
        self::assertSame(AlertSeverity::CRITICAL, $alerts[0]->severity);
        self::assertStringContainsString('20 bytes', $alerts[0]->title);
    }

    /**
     * Age comes from the filename, exactly as `scripts/doctor.sh` reads it.
     * Copying, restoring or syncing the backup directory stamps every file with
     * "now", so an mtime check would call a months-old set perfectly fresh.
     */
    public function testAgeComesFromTheFilenameNotFromMtime(): void
    {
        $path = $this->writeBackup('2026-08-04', bytes: 400_000);
        touch($path, new DateTimeImmutable(self::NOW)->getTimestamp());

        self::assertSame('stale', $this->probe()[0]->key, 'A freshly copied old dump is still an old dump.');
    }

    public function testTheNewestDumpIsTheOneWithTheLatestDate(): void
    {
        $this->writeBackup('2026-08-04', bytes: 400_000);
        $this->writeBackup('2026-08-11', bytes: 400_000);

        self::assertSame([], $this->probe());
    }

    public function testOnlyOneReasonIsReportedAtATime(): void
    {
        $this->writeBackup('2026-08-01', bytes: 20);

        $alerts = $this->probe();

        self::assertCount(1, $alerts);
        self::assertSame('stale', $alerts[0]->key, 'A dump that is both old and empty is first of all old.');
    }

    public function testFilesThatAreNotBackupsAreIgnored(): void
    {
        file_put_contents($this->backupDir.'/notes.txt', str_repeat('x', 400_000));
        file_put_contents($this->backupDir.'/homemanager-latest.sql.gz.enc', str_repeat('x', 400_000));

        self::assertSame('missing', $this->probe()[0]->key);
    }

    /**
     * `createFromFormat` rolls over rather than refusing: `2026-13-01` becomes
     * January 2027. Accepted, that file would be the newest thing in the
     * directory by a wide margin and would hide a genuinely stale backup behind
     * a date that never existed.
     */
    public function testAnImpossibleDateInAFilenameIsNotTreatedAsABackup(): void
    {
        $this->writeBackup('2026-13-01', bytes: 400_000);
        $this->writeBackup('2026-08-04', bytes: 400_000);

        self::assertSame('stale', $this->probe()[0]->key);
    }

    private function writeBackup(string $date, int $bytes): string
    {
        $path = sprintf('%s/homemanager-%s.sql.gz.enc', $this->backupDir, $date);
        file_put_contents($path, str_repeat('x', $bytes));

        return $path;
    }

    /** @return list<Alert> */
    private function probe(): array
    {
        return new BackupFreshnessProbe($this->backupDir, self::MAX_AGE_HOURS, self::MIN_BYTES)
            ->probe(new DateTimeImmutable(self::NOW));
    }
}
