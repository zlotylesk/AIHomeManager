<?php

declare(strict_types=1);

namespace App\Tests\Integration\Backup;

use App\Infrastructure\Backup\BackupCipher;
use Override;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BackupCommandTest extends KernelTestCase
{
    private const string BACKUP_DIR = '/tmp/aihm_backup_test';

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        if (!is_dir(self::BACKUP_DIR)) {
            mkdir(self::BACKUP_DIR, 0o755, true);
        }

        $this->clearBackupDir();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->clearBackupDir();

        parent::tearDown();
    }

    public function testBackupCommandCreatesAnEncryptedDump(): void
    {
        $tester = $this->runBackup();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Backup created (encrypted):', $tester->getDisplay());

        $files = $this->encryptedBackups();
        self::assertCount(1, $files);
        self::assertGreaterThan(0, filesize($files[0]));
    }

    /**
     * The artifact must not be a readable dump. Asserting the absence of gzip's
     * magic bytes is the cheap way to prove that: a plaintext `.sql.gz` starts
     * with 1f 8b, and this one has to start with the cipher's own header instead.
     */
    public function testTheStoredArtifactIsNotAReadableDump(): void
    {
        $this->runBackup();

        $content = file_get_contents($this->encryptedBackups()[0]);
        self::assertIsString($content);

        self::assertNotSame("\x1f\x8b", substr($content, 0, 2), 'the backup on disk must not be a plain gzip stream');
        self::assertStringStartsWith('AIHMBK', $content);
        self::assertStringNotContainsString('CREATE TABLE', $content);
    }

    /**
     * The whole point, end to end: what is on disk decrypts back to exactly the
     * dump mysqldump produced. Every other check in the backup chain — size, age,
     * freshness — can pass on a file that no longer restores, which is how six
     * days of empty dumps once went unnoticed.
     *
     * It asserts the dump's header and its completion marker rather than
     * `CREATE TABLE`, and the distinction is not cosmetic. The service dumps
     * whatever `DATABASE_URL` names, which under `when@test` is the base schema —
     * not the `homemanager_test{token}` database the suite actually populates. On
     * CI that schema is empty, so a `CREATE TABLE` assertion tests how the fixture
     * database happens to be provisioned rather than anything about the backup.
     *
     * `Dump completed on` is the assertion worth having, and the only portable
     * one: mysqldump writes it last, so it is present only if the dump ran to the
     * end — exactly the property a backup needs and a truncated one lacks. The
     * banner above it is not portable, because the two environments do not run
     * the same client: the Alpine image carries MariaDB's mysqldump while the CI
     * runner has MySQL's, and they disagree about what to call themselves.
     */
    public function testTheEncryptedBackupDecryptsBackToACompleteDump(): void
    {
        $this->runBackup();

        $cipher = self::getContainer()->get(BackupCipher::class);

        $out = fopen('php://memory', 'w+b');
        self::assertIsResource($out);
        $cipher->decryptToStream($this->encryptedBackups()[0], $out);
        rewind($out);
        $gzipped = stream_get_contents($out);
        fclose($out);

        self::assertIsString($gzipped);
        self::assertSame("\x1f\x8b", substr($gzipped, 0, 2), 'the decrypted payload must be the gzip stream');

        $sql = gzdecode($gzipped);
        self::assertIsString($sql);
        self::assertStringContainsString('Dump completed on', $sql);
    }

    /**
     * mysqldump cannot encrypt, so a plaintext dump exists for as long as it
     * takes to read it back through the cipher. Nothing may be left of it: a
     * forgotten temporary file is an unencrypted copy of the entire database
     * sitting in the directory whose contents get copied off the machine.
     */
    public function testNoPlaintextDumpIsLeftBehind(): void
    {
        $this->runBackup();

        $leftovers = glob(self::BACKUP_DIR.'/{,.}*.plain', \GLOB_BRACE);
        self::assertNotFalse($leftovers);
        self::assertSame([], $leftovers);

        $plain = glob(self::BACKUP_DIR.'/homemanager-*.sql.gz');
        self::assertNotFalse($plain);
        self::assertSame([], $plain, 'only the encrypted artifact may survive a backup run');
    }

    private function runBackup(): CommandTester
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester(new Application(self::$kernel)->find('app:backup-database'));
        $tester->execute([]);

        return $tester;
    }

    /** @return list<string> */
    private function encryptedBackups(): array
    {
        $files = glob(self::BACKUP_DIR.'/homemanager-*.sql.gz.enc');
        self::assertNotFalse($files);
        self::assertNotEmpty($files);

        return $files;
    }

    private function clearBackupDir(): void
    {
        $files = glob(self::BACKUP_DIR.'/{,.}homemanager-*', \GLOB_BRACE);
        if (false !== $files) {
            array_map(unlink(...), $files);
        }
    }
}
