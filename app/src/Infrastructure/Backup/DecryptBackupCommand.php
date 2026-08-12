<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Decrypts a backup to standard output — the first stage of a restore.
 *
 * Writing to stdout rather than to a file is the point. `make restore` pipes
 * this straight through `gunzip` into `mysql`, so the plaintext dump exists only
 * as bytes moving between processes and is never written to the disk we
 * encrypted it to stay off. It also means a restore needs no scratch space, which
 * matters on the occasion this is actually used: recovering onto a machine
 * that may have very little of it.
 *
 * Errors go to stderr and every one of them is a non-zero exit, so a failure
 * here cannot be piped into `mysql` as if it were SQL.
 */
#[AsCommand(
    name: 'app:backup:decrypt',
    description: 'Decrypt an encrypted backup to stdout (pipe into gunzip | mysql)',
)]
final class DecryptBackupCommand extends Command
{
    public function __construct(
        private readonly BackupCipher $cipher,
        private readonly string $backupDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'file',
            InputArgument::REQUIRED,
            'Backup to decrypt: a path, or just the filename to take it from BACKUP_DIR',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Everything this command says goes to stderr, because stdout is the
        // dump: an error message mixed into it would be piped onward and fed to
        // mysql as SQL. Under a real console `$output` is always a
        // ConsoleOutputInterface and has a separate error stream; the fallback
        // only matters to a test harness, which reads the exit code anyway.
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $requested = (string) $input->getArgument('file');
        $path = $this->resolve($requested);

        if (null === $path) {
            $stderr->writeln(sprintf(
                '<error>No such backup: %s (looked in %s too).</error>',
                $requested,
                $this->backupDir,
            ));

            return Command::FAILURE;
        }

        $stdout = fopen('php://stdout', 'wb');

        if (false === $stdout) {
            $stderr->writeln('<error>Cannot open stdout.</error>');

            return Command::FAILURE;
        }

        try {
            $this->cipher->decryptToStream($path, $stdout);
        } catch (RuntimeException $e) {
            $stderr->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::FAILURE;
        } finally {
            fclose($stdout);
        }

        return Command::SUCCESS;
    }

    private function resolve(string $requested): ?string
    {
        foreach ([$requested, $this->backupDir.'/'.basename($requested)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
