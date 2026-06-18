<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Backup;

use App\Infrastructure\Backup\DatabaseBackupService;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class DatabaseBackupServiceTest extends TestCase
{
    private string $tmpDir;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/aihm_backup_test_'.uniqid();
        mkdir($this->tmpDir, 0o755, true);
    }

    #[Override]
    protected function tearDown(): void
    {
        $files = glob($this->tmpDir.'/*');
        if (false !== $files) {
            array_map(unlink(...), $files);
        }
        rmdir($this->tmpDir);
    }

    public function testCleanupKeepsRecentDailyBackups(): void
    {
        $today = new DateTimeImmutable('2026-06-15');

        for ($i = 34; $i >= 0; --$i) {
            $date = $today->modify(sprintf('-%d days', $i));
            touch($this->tmpDir.'/homemanager-'.$date->format('Y-m-d').'.sql.gz');
        }

        $service = new DatabaseBackupService(
            'mysql://u:p@localhost:3306/db',
            $this->tmpDir,
            new NullLogger(),
        );

        $deleted = $service->cleanup($today);

        self::assertSame(4, $deleted);

        self::assertFileExists($this->tmpDir.'/homemanager-2026-06-15.sql.gz');
        self::assertFileExists($this->tmpDir.'/homemanager-2026-05-16.sql.gz');

        self::assertFileDoesNotExist($this->tmpDir.'/homemanager-2026-05-12.sql.gz');
    }

    public function testCleanupKeepsFirstOfMonthBackups(): void
    {
        $today = new DateTimeImmutable('2026-12-15');

        for ($m = 14; $m >= 0; --$m) {
            $date = $today->modify(sprintf('-%d months', $m))->modify('first day of this month');
            touch($this->tmpDir.'/homemanager-'.$date->format('Y-m-d').'.sql.gz');
        }

        touch($this->tmpDir.'/homemanager-2025-10-15.sql.gz');

        $service = new DatabaseBackupService(
            'mysql://u:p@localhost:3306/db',
            $this->tmpDir,
            new NullLogger(),
        );

        $deleted = $service->cleanup($today);

        self::assertSame(3, $deleted);

        self::assertFileDoesNotExist($this->tmpDir.'/homemanager-2025-10-01.sql.gz');
        self::assertFileDoesNotExist($this->tmpDir.'/homemanager-2025-10-15.sql.gz');
    }

    public function testCleanupIgnoresNonMatchingFiles(): void
    {
        $today = new DateTimeImmutable('2026-06-15');

        touch($this->tmpDir.'/random-file.txt');
        touch($this->tmpDir.'/homemanager-2026-06-14.sql.gz');

        $service = new DatabaseBackupService(
            'mysql://u:p@localhost:3306/db',
            $this->tmpDir,
            new NullLogger(),
        );

        $deleted = $service->cleanup($today);

        self::assertSame(0, $deleted);
        self::assertFileExists($this->tmpDir.'/random-file.txt');
        self::assertFileExists($this->tmpDir.'/homemanager-2026-06-14.sql.gz');
    }

    public function testCleanupReturnsZeroWhenNoFiles(): void
    {
        $service = new DatabaseBackupService(
            'mysql://u:p@localhost:3306/db',
            $this->tmpDir,
            new NullLogger(),
        );

        self::assertSame(0, $service->cleanup());
    }

    public function testParseDatabaseUrlThrowsOnInvalidFormat(): void
    {
        $service = new DatabaseBackupService(
            'not-a-valid-url',
            $this->tmpDir,
            new NullLogger(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid DATABASE_URL format');

        $service->backup();
    }
}
