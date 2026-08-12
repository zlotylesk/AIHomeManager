<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Backup\Destination;

use App\Infrastructure\Backup\Destination\RcloneBackupDestination;
use App\Tests\Support\Backup\RecordingCommandRunner;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The arguments this class builds and what it makes of rclone's answers.
 *
 * All of it would otherwise be reachable only on a machine with rclone installed
 * and a real remote configured — which is to say never, in CI or anywhere else —
 * and it is the code path that decides whether backups leave the machine.
 */
final class RcloneBackupDestinationTest extends TestCase
{
    private RecordingCommandRunner $runner;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new RecordingCommandRunner();
    }

    /**
     * `copyto`, not `copy`: the latter drops the source into a directory, and the
     * remote has to keep the exact filename because every reader — the probe, the
     * status command, pruning — reads the date back out of it.
     */
    public function testPushNamesTheDestinationFileExplicitly(): void
    {
        $this->destination()->push('/backups/homemanager-2026-08-12.sql.gz.enc');

        self::assertSame([[
            'rclone',
            'copyto',
            '/backups/homemanager-2026-08-12.sql.gz.enc',
            'b2:aihm/homemanager-2026-08-12.sql.gz.enc',
        ]], $this->runner->commands);
    }

    public function testPushFailureTravelsAsAFailure(): void
    {
        $this->runner->fail = new RuntimeException('rclone exited with 1: quota exceeded');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/quota exceeded/');

        $this->destination()->push('/backups/homemanager-2026-08-12.sql.gz.enc');
    }

    public function testListsOnlyRecognisableBackups(): void
    {
        $this->runner->output = json_encode([
            ['Name' => 'homemanager-2026-08-12.sql.gz.enc', 'Size' => 354333],
            ['Name' => 'homemanager-2026-08-10.sql.gz.enc', 'Size' => 350000],
            // Not ours, an impossible date, and a pre-encryption dump: none of
            // them is something a restore could run from.
            ['Name' => 'notes.txt', 'Size' => 10],
            ['Name' => 'homemanager-2026-02-31.sql.gz.enc', 'Size' => 10],
            ['Name' => 'homemanager-2026-08-11.sql.gz', 'Size' => 10],
        ], \JSON_THROW_ON_ERROR);

        $backups = $this->destination()->listBackups();

        self::assertCount(2, $backups);
        self::assertSame('homemanager-2026-08-12.sql.gz.enc', $backups[0]->name);
        self::assertSame(354333, $backups[0]->bytes);
    }

    /**
     * Some backends report no size. It has to arrive as "unknown" rather than as
     * zero, or the probe would hold a "too small" alert open forever on those
     * remotes — and an alert that can never clear devalues the ones that can.
     */
    public function testAMissingSizeBecomesUnknownRatherThanZero(): void
    {
        $this->runner->output = json_encode([
            ['Name' => 'homemanager-2026-08-12.sql.gz.enc'],
        ], \JSON_THROW_ON_ERROR);

        self::assertSame(-1, $this->destination()->listBackups()[0]->bytes);
    }

    /**
     * An unreadable listing must not look like an empty remote — that would
     * report a broken connection as "no backups there" and send the operator
     * after the wrong problem.
     */
    public function testUnparseableOutputIsAFailureNotAnEmptyRemote(): void
    {
        $this->runner->output = 'Failed to create file system';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no usable JSON/');

        $this->destination()->listBackups();
    }

    public function testPruneDeletesOnlyWhatIsPastTheWindow(): void
    {
        $this->runner->output = json_encode([
            ['Name' => 'homemanager-2026-08-12.sql.gz.enc', 'Size' => 1],
            ['Name' => 'homemanager-2026-01-01.sql.gz.enc', 'Size' => 1],
        ], \JSON_THROW_ON_ERROR);

        $deleted = $this->destination()->prune(new DateTimeImmutable('2026-08-12'));

        self::assertSame(1, $deleted);
        self::assertContains(
            ['rclone', 'deletefile', 'b2:aihm/homemanager-2026-01-01.sql.gz.enc'],
            $this->runner->commands,
        );
        self::assertNotContains(
            ['rclone', 'deletefile', 'b2:aihm/homemanager-2026-08-12.sql.gz.enc'],
            $this->runner->commands,
        );
    }

    private function destination(): RcloneBackupDestination
    {
        return new RcloneBackupDestination($this->runner, 'b2:aihm', 90);
    }
}
