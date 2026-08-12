<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Backup\Destination;

use App\Infrastructure\Backup\Destination\LocalDirectoryBackupDestination;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LocalDirectoryBackupDestinationTest extends TestCase
{
    private string $localDir;
    private string $remoteDir;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $root = sys_get_temp_dir().'/aihm_dest_test_'.uniqid();
        $this->localDir = $root.'/local';
        $this->remoteDir = $root.'/remote';
        mkdir($this->localDir, 0o755, true);
        mkdir($this->remoteDir, 0o755, true);
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ([$this->localDir, $this->remoteDir] as $dir) {
            $files = glob($dir.'/*');
            if (false !== $files) {
                array_map(unlink(...), $files);
            }
            rmdir($dir);
        }
        rmdir(\dirname($this->localDir));
    }

    public function testPushCopiesTheBackupToTheDestination(): void
    {
        $local = $this->writeLocalBackup('2026-08-12', 'ciphertext');

        $this->destination()->push($local);

        self::assertFileExists($this->remoteDir.'/homemanager-2026-08-12.sql.gz.enc');
        self::assertSame('ciphertext', file_get_contents($this->remoteDir.'/homemanager-2026-08-12.sql.gz.enc'));
    }

    /**
     * A transfer that dies partway must not leave a short file wearing tonight's
     * name — it would pass the freshness check and be found unusable only during
     * a restore. The staging name is what prevents that, so nothing may be left
     * behind under it either.
     */
    public function testPushLeavesNoStagingFileBehind(): void
    {
        $this->destination()->push($this->writeLocalBackup('2026-08-12', 'ciphertext'));

        self::assertFileDoesNotExist($this->remoteDir.'/homemanager-2026-08-12.sql.gz.enc.part');
    }

    public function testPushFailsWhenTheDestinationIsNotMounted(): void
    {
        $local = $this->writeLocalBackup('2026-08-12', 'ciphertext');
        $destination = new LocalDirectoryBackupDestination($this->remoteDir.'/gone', $this->localDir, 90, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/is the remote volume still mounted/');

        $destination->push($local);
    }

    /**
     * Two directories on one disk are not an off-host copy, however "remote" the
     * path is called: the failure that takes the database takes the backup with
     * it. Without the guard this configuration succeeds silently and protects
     * against nothing, which is the shape of failure this ticket exists to end.
     */
    public function testPushRefusesADestinationOnTheSameFilesystem(): void
    {
        $local = $this->writeLocalBackup('2026-08-12', 'ciphertext');
        $destination = new LocalDirectoryBackupDestination($this->remoteDir, $this->localDir, 90);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/same filesystem/');

        $destination->push($local);
    }

    public function testListsOnlyEncryptedBackupsWithAReadableDate(): void
    {
        $this->writeRemoteBackup('homemanager-2026-08-10.sql.gz.enc', 'a');
        $this->writeRemoteBackup('homemanager-2026-08-12.sql.gz.enc', 'bb');
        // A pre-encryption dump, a nonsense date and an unrelated file: none of
        // them is something a restore could be run from, so none may count as a
        // backup present at the destination.
        $this->writeRemoteBackup('homemanager-2026-08-11.sql.gz', 'plain');
        $this->writeRemoteBackup('homemanager-2026-02-31.sql.gz.enc', 'rollover');
        $this->writeRemoteBackup('notes.txt', 'x');

        $sizesByName = [];
        foreach ($this->destination()->listBackups() as $backup) {
            $sizesByName[$backup->name] = $backup->bytes;
        }
        ksort($sizesByName);

        self::assertSame([
            'homemanager-2026-08-10.sql.gz.enc' => 1,
            'homemanager-2026-08-12.sql.gz.enc' => 2,
        ], $sizesByName);
    }

    public function testPruneRemovesCopiesPastTheRetentionWindow(): void
    {
        $this->writeRemoteBackup('homemanager-2026-08-12.sql.gz.enc', 'fresh');
        $this->writeRemoteBackup('homemanager-2026-05-01.sql.gz.enc', 'old');
        $this->writeRemoteBackup('homemanager-2026-06-20.sql.gz.enc', 'borderline');

        $deleted = $this->destination()->prune(new DateTimeImmutable('2026-08-12'));

        self::assertSame(1, $deleted);
        self::assertFileDoesNotExist($this->remoteDir.'/homemanager-2026-05-01.sql.gz.enc');
        self::assertFileExists($this->remoteDir.'/homemanager-2026-06-20.sql.gz.enc');
        self::assertFileExists($this->remoteDir.'/homemanager-2026-08-12.sql.gz.enc');
    }

    /**
     * Retention is a window, not a deletion switch. A misconfigured 0 would
     * otherwise put every copy — including last night's — past the cutoff, and
     * the off-host destination would empty itself on the next nightly run.
     */
    public function testPruneRefusesToEmptyTheDestinationOnANonsenseWindow(): void
    {
        $this->writeRemoteBackup('homemanager-2026-08-12.sql.gz.enc', 'fresh');
        $this->writeRemoteBackup('homemanager-2020-01-01.sql.gz.enc', 'ancient');

        $destination = new LocalDirectoryBackupDestination($this->remoteDir, $this->localDir, 0, true);

        self::assertSame(0, $destination->prune(new DateTimeImmutable('2026-08-12')));
        self::assertFileExists($this->remoteDir.'/homemanager-2026-08-12.sql.gz.enc');
        self::assertFileExists($this->remoteDir.'/homemanager-2020-01-01.sql.gz.enc');
    }

    private function destination(): LocalDirectoryBackupDestination
    {
        // Same filesystem allowed: a unit test cannot mount a second device, and
        // the refusal itself has its own test above.
        return new LocalDirectoryBackupDestination($this->remoteDir, $this->localDir, 90, true);
    }

    private function writeLocalBackup(string $date, string $content): string
    {
        $path = $this->localDir.'/homemanager-'.$date.'.sql.gz.enc';
        file_put_contents($path, $content);

        return $path;
    }

    private function writeRemoteBackup(string $name, string $content): void
    {
        file_put_contents($this->remoteDir.'/'.$name, $content);
    }
}
