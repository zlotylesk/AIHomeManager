<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:backup-database',
    description: 'Run a MySQL backup with retention cleanup',
)]
final class BackupDatabaseCommand extends Command
{
    public function __construct(private readonly DatabaseBackupService $backupService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Starting database backup...');

        try {
            $filepath = $this->backupService->backup();
            $output->writeln(sprintf('Backup created: %s', $filepath));

            $deleted = $this->backupService->cleanup();
            $output->writeln(sprintf('Retention cleanup: %d old backup(s) removed.', $deleted));
        } catch (RuntimeException $e) {
            $output->writeln(sprintf('<error>Backup failed: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
