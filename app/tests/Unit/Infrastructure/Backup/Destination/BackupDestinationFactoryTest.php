<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Backup\Destination;

use App\Infrastructure\Backup\Destination\BackupDestinationFactory;
use App\Infrastructure\Backup\Destination\CommandRunnerInterface;
use App\Infrastructure\Backup\Destination\LocalDirectoryBackupDestination;
use App\Infrastructure\Backup\Destination\NullBackupDestination;
use App\Infrastructure\Backup\Destination\RcloneBackupDestination;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BackupDestinationFactoryTest extends TestCase
{
    public function testSelectsTheDirectoryBackend(): void
    {
        self::assertInstanceOf(
            LocalDirectoryBackupDestination::class,
            $this->factory('directory', remoteDir: '/mnt/offsite')->create(),
        );
    }

    public function testSelectsTheRcloneBackend(): void
    {
        self::assertInstanceOf(
            RcloneBackupDestination::class,
            $this->factory('rclone', rcloneTarget: 'b2:aihm-backups')->create(),
        );
    }

    public function testSelectsNoDestinationWhenExplicitlyDisabled(): void
    {
        $destination = $this->factory('none')->create();

        self::assertInstanceOf(NullBackupDestination::class, $destination);
        self::assertFalse($destination->isConfigured());
    }

    /**
     * The point of the factory. A typo here must not quietly become "no off-host
     * backups": that instance would look configured, report nothing wrong and
     * copy nothing anywhere — which is the exact failure this work exists to
     * remove, reintroduced by the setting meant to prevent it.
     */
    public function testRejectsAnUnknownBackendRatherThanDisablingOffHostBackups(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown BACKUP_REMOTE_BACKEND "s3"/');

        $this->factory('s3')->create();
    }

    public function testDirectoryBackendWithoutADirectoryIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/BACKUP_REMOTE_DIR/');

        $this->factory('directory')->create();
    }

    public function testRcloneBackendWithoutATargetIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/BACKUP_REMOTE_TARGET/');

        $this->factory('rclone')->create();
    }

    private function factory(string $backend, string $remoteDir = '', string $rcloneTarget = ''): BackupDestinationFactory
    {
        return new BackupDestinationFactory(
            $this->createStub(CommandRunnerInterface::class),
            $backend,
            $remoteDir,
            $rcloneTarget,
            '/backups',
            90,
        );
    }
}
