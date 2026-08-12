<?php

declare(strict_types=1);

namespace App\Application\Scheduled;

use App\Infrastructure\Backup\DatabaseBackupService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final readonly class BackupDatabaseHandler
{
    public function __construct(
        private DatabaseBackupService $backupService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BackupDatabase $message): void
    {
        $path = $this->backupService->backup();
        $this->backupService->cleanup();

        try {
            $this->backupService->pushOffsite($path);
        } catch (Throwable $e) {
            // Swallowed on purpose, and it is the one place in this module where
            // that is the right call. The dump succeeded and is on disk; letting
            // this propagate would send the message back through the retry chain
            // and re-dump the entire database three more times over a failure
            // that had nothing to do with the database.
            //
            // It is not swallowed silently, which is the distinction that
            // matters: BackupOffsiteProbe reads the destination on every
            // monitoring sweep, so an upload that keeps failing stops being a log
            // line nobody reads and becomes mail to the owner — the same route a
            // failed local backup already takes.
            $this->logger->error('Off-host backup copy failed', [
                'scheduled_task' => 'database_backup',
                'file' => basename($path),
                'destination' => $this->backupService->destination()->name(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
