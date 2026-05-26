<?php

declare(strict_types=1);

namespace App\Application\Scheduled;

use App\Infrastructure\Backup\DatabaseBackupService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class BackupDatabaseHandler
{
    public function __construct(private DatabaseBackupService $backupService)
    {
    }

    public function __invoke(BackupDatabase $message): void
    {
        $this->backupService->backup();
        $this->backupService->cleanup();
    }
}
