<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup;

use App\Infrastructure\Backup\Destination\BackupDestinationInterface;
use App\Infrastructure\Backup\Destination\RemoteBackup;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * What the off-host destination currently holds.
 *
 * Exists so `make doctor` can report on the remote copy without reimplementing
 * any of it in shell. The destination may be an rclone remote reachable only
 * with credentials the host script has no business reading, and its path is a
 * path *inside* the container — a shell check on the host would be inspecting a
 * different filesystem and quietly reporting on nothing.
 *
 * Machine-readable single-line output, because its only consumer parses it.
 */
#[AsCommand(
    name: 'app:backup:offsite-status',
    description: 'Report the state of the off-host backup destination (used by make doctor)',
)]
final class OffsiteStatusCommand extends Command
{
    public function __construct(
        private readonly BackupDestinationInterface $destination,
        private readonly int $maxAgeHours,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->destination->isConfigured()) {
            $output->writeln('backend=none');

            return Command::SUCCESS;
        }

        $prefix = sprintf('backend=%s target=%s', $this->destination->name(), $this->destination->describe());

        try {
            $backups = $this->destination->listBackups();
        } catch (Throwable $e) {
            $output->writeln(sprintf('%s state=unreachable error=%s', $prefix, str_replace("\n", ' ', $e->getMessage())));

            return Command::FAILURE;
        }

        $newest = null;
        foreach ($backups as $backup) {
            if (null === $newest || $backup->date > $newest->date) {
                $newest = $backup;
            }
        }

        if (!$newest instanceof RemoteBackup) {
            $output->writeln(sprintf('%s state=empty', $prefix));

            return Command::FAILURE;
        }

        $ageHours = (int) floor((new DateTimeImmutable()->getTimestamp() - $newest->date->getTimestamp()) / 3600);

        $output->writeln(sprintf(
            '%s state=%s count=%d newest=%s age_hours=%d bytes=%d limit_hours=%d',
            $prefix,
            $ageHours > $this->maxAgeHours ? 'stale' : 'ok',
            \count($backups),
            $newest->name,
            $ageHours,
            $newest->bytes,
            $this->maxAgeHours,
        ));

        return $ageHours > $this->maxAgeHours ? Command::FAILURE : Command::SUCCESS;
    }
}
