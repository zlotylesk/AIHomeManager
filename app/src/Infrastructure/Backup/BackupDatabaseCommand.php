<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'app:backup-database',
    description: 'Run an encrypted MySQL backup, copy it off-host, and apply retention',
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
            $output->writeln(sprintf('Backup created (encrypted): %s', $filepath));

            $deleted = $this->backupService->cleanup();
            $output->writeln(sprintf('Retention cleanup: %d old backup(s) removed.', $deleted));
        } catch (RuntimeException $e) {
            $output->writeln(sprintf('<error>Backup failed: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        $destination = $this->backupService->destination();

        if (!$destination->isConfigured()) {
            // Said out loud rather than passed over. An operator who believes
            // backups are going off-host and is looking at a run that says
            // nothing about it has no way to discover otherwise.
            $output->writeln('<comment>No off-host copy: BACKUP_REMOTE_BACKEND=none. This backup exists only on this machine.</comment>');

            return Command::SUCCESS;
        }

        try {
            $this->backupService->pushOffsite($filepath);
            $output->writeln(sprintf('Copied off-host to %s (%s).', $destination->describe(), $destination->name()));
        } catch (Throwable $e) {
            // A distinct, non-zero outcome. The local backup is intact and the
            // message says so, but the run as a whole did not do what it exists
            // to do, and an exit code of 0 here is how "we have off-host backups"
            // becomes something an instance believes rather than something it does.
            $output->writeln(sprintf('<error>Off-host copy failed: %s</error>', $e->getMessage()));
            $output->writeln(sprintf('<comment>The local backup is intact at %s. Fix the destination and re-run.</comment>', $filepath));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
