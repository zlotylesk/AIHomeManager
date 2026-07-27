<?php

declare(strict_types=1);

namespace App\Tests\Integration\Backup;

use App\Infrastructure\Backup\DatabaseBackupService;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * HMAI-397: `backup()` pipes `mysqldump | gzip > file` through a real shell —
 * this needs a real `mysqldump`/`bash` on PATH (present in the CI runner and
 * in the docker/php image that runs the production backup), which is why
 * this lives under Integration rather than Unit.
 *
 * Regression for the bug: POSIX `sh` reports the LAST pipeline member's exit
 * code (gzip), so a failed mysqldump used to exit 0 and leave a corrupted
 * "successful" .sql.gz on disk. mysqldump fails fast here because the host
 * cannot be resolved — gzip still runs and would still create a file, which
 * is exactly what must not survive.
 */
final class DatabaseBackupServiceFailureTest extends TestCase
{
    private string $tmpDir;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/aihm_backup_failure_test_'.uniqid();
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

    public function testBackupThrowsWhenMysqldumpFailsEvenThoughGzipSucceeds(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Database backup failed', self::callback(
                static fn (array $ctx): bool => 0 !== $ctx['exit_code'],
            ));

        $service = new DatabaseBackupService(
            // A host that cannot be resolved: mysqldump fails fast, gzip
            // still runs on its (empty) stdin and would happily exit 0.
            'mysql://u:p@nonexistent-host-xyz-hmai397:3306/db',
            $this->tmpDir,
            $logger,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database backup failed');

        try {
            $service->backup();
        } finally {
            // The bug this pins: without `pipefail`, this glob would find a
            // gzip-produced (empty/corrupted) .sql.gz even after the throw.
            $files = glob($this->tmpDir.'/homemanager-*.sql.gz');
            self::assertNotFalse($files);
            self::assertSame([], $files, 'a failed backup must not leave a partial .sql.gz behind');
        }
    }
}
