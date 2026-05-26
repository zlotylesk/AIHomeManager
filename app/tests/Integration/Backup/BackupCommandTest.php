<?php

declare(strict_types=1);

namespace App\Tests\Integration\Backup;

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

        $files = glob(self::BACKUP_DIR.'/homemanager-*.sql.gz');
        if (false !== $files) {
            array_map(unlink(...), $files);
        }
    }

    #[Override]
    protected function tearDown(): void
    {
        $files = glob(self::BACKUP_DIR.'/homemanager-*.sql.gz');
        if (false !== $files) {
            array_map(unlink(...), $files);
        }

        parent::tearDown();
    }

    public function testBackupCommandCreatesGzippedDump(): void
    {
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);
        $command = $application->find('app:backup-database');
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Backup created:', $tester->getDisplay());

        $files = glob(self::BACKUP_DIR.'/homemanager-*.sql.gz');
        self::assertNotFalse($files);
        self::assertCount(1, $files);
        self::assertGreaterThan(0, filesize($files[0]));
    }

    public function testBackupFileIsValidGzip(): void
    {
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);
        $command = $application->find('app:backup-database');
        $tester = new CommandTester($command);

        $tester->execute([]);

        $files = glob(self::BACKUP_DIR.'/homemanager-*.sql.gz');
        self::assertNotFalse($files);
        self::assertNotEmpty($files);

        $content = file_get_contents($files[0]);
        self::assertNotFalse($content);
        self::assertSame("\x1f\x8b", substr($content, 0, 2));
    }
}
