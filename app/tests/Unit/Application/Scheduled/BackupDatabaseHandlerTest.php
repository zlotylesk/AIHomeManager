<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Scheduled;

use App\Application\Scheduled\BackupDatabase;
use App\Application\Scheduled\BackupDatabaseHandler;
use App\Infrastructure\Backup\DatabaseBackupService;
use PHPUnit\Framework\TestCase;

final class BackupDatabaseHandlerTest extends TestCase
{
    public function testHandlerDelegatesBackupAndCleanup(): void
    {
        $service = $this->createMock(DatabaseBackupService::class);
        $service->expects(self::once())->method('backup');
        $service->expects(self::once())->method('cleanup');

        $handler = new BackupDatabaseHandler($service);
        $handler(new BackupDatabase());
    }
}
