<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup;

use App\Infrastructure\Backup\Destination\BackupDestinationInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

readonly class DatabaseBackupService
{
    private const int DAILY_RETENTION_DAYS = 30;
    private const int MONTHLY_RETENTION_COUNT = 12;

    public function __construct(
        private string $databaseUrl,
        private string $backupDir,
        private BackupCipher $cipher,
        private BackupDestinationInterface $destination,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Dumps the database and leaves exactly one artifact on disk: the encrypted
     * `.sql.gz.enc`.
     *
     * mysqldump cannot encrypt, so a plaintext dump has to exist for as long as
     * it takes to read it back through the cipher. It is kept to that: written
     * under a dot-prefixed temporary name that no glob in this system matches —
     * so a crash mid-run can never leave something the freshness probe counts as
     * a backup — created 0600 before a byte goes into it, and removed in a
     * `finally` whether the run succeeded, failed or threw.
     */
    public function backup(?DateTimeImmutable $date = null): string
    {
        $date ??= new DateTimeImmutable();
        $filename = BackupFilename::forDate($date);
        $filepath = $this->backupDir.'/'.$filename;
        $plaintextPath = $this->backupDir.'/.'.$filename.'.plain';

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0o755, true);
        }

        $params = $this->parseDatabaseUrl();

        try {
            // Created and locked down BEFORE the dump writes into it: a
            // world-readable plaintext copy of the whole database, even for the
            // seconds this exists, is the thing encrypting the backups was meant
            // to prevent.
            //
            // Both results are checked rather than assumed. If either fails the
            // shell redirect below still creates the file, but at the umask's
            // discretion — so ignoring these would turn a locked-down temporary
            // into a world-readable one silently, which is the only way this
            // precaution could fail while appearing to work.
            if (!touch($plaintextPath) || !chmod($plaintextPath, 0o600)) {
                throw new RuntimeException(sprintf('Cannot create the temporary dump %s with private permissions — check ownership of %s.', basename($plaintextPath), $this->backupDir));
            }

            $this->dump($params, $plaintextPath);

            $this->cipher->encryptFile($plaintextPath, $filepath);
        } catch (Throwable $e) {
            // A backup that cannot be restored is worse than no backup at all —
            // it passes every check while being nothing — so a failed run must
            // not leave its own remains behind wearing today's name.
            @unlink($filepath);

            $this->logger->error('Database backup failed', [
                'scheduled_task' => 'database_backup',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            @unlink($plaintextPath);
        }

        $this->logger->info('Database backup completed', [
            'scheduled_task' => 'database_backup',
            'file' => $filename,
            'size_bytes' => filesize($filepath),
            'encrypted' => true,
        ]);

        return $filepath;
    }

    /**
     * Copies the newest artifact off the host and applies the destination's own
     * retention.
     *
     * Runs after {@see cleanup}, never before, and the ordering is deliberate: a
     * remote that is unreachable tonight must cost nothing locally. It is also
     * why the caller is expected to catch rather than retry — the dump already
     * succeeded, and re-running the message to have another go at the upload
     * would dump the whole database again for a failure that had nothing to do
     * with it.
     */
    public function pushOffsite(string $localPath, ?DateTimeImmutable $today = null): void
    {
        if (!$this->destination->isConfigured()) {
            return;
        }

        $this->destination->push($localPath);

        $pruned = $this->destination->prune($today ?? new DateTimeImmutable());

        $this->logger->info('Backup copied off-host', [
            'scheduled_task' => 'database_backup',
            'file' => basename($localPath),
            'destination' => $this->destination->name(),
            'target' => $this->destination->describe(),
            'pruned_count' => $pruned,
        ]);
    }

    /**
     * The configured destination, for callers that have to *describe* it rather
     * than use it — the console command saying where a copy went, the handler
     * naming it in a log line.
     *
     * A reader, not a seam: pushing and pruning stay behind {@see pushOffsite},
     * so no caller decides for itself what "copy this off-host" means.
     */
    public function destination(): BackupDestinationInterface
    {
        return $this->destination;
    }

    public function cleanup(?DateTimeImmutable $today = null): int
    {
        $today ??= new DateTimeImmutable();
        $cutoffDaily = $today->modify(sprintf('-%d days', self::DAILY_RETENTION_DAYS));

        $files = glob($this->backupDir.'/'.BackupFilename::GLOB);
        if (false === $files) {
            return 0;
        }

        $candidates = [];

        foreach ($files as $file) {
            $fileDate = BackupFilename::dateOf(basename($file));

            if (null === $fileDate || $fileDate >= $cutoffDaily) {
                continue;
            }

            $candidates[$file] = $fileDate;
        }

        uasort($candidates, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $b <=> $a);

        $monthlyKept = [];
        $deleted = 0;

        foreach ($candidates as $file => $fileDate) {
            if ('01' === $fileDate->format('d') && \count($monthlyKept) < self::MONTHLY_RETENTION_COUNT) {
                $monthlyKept[] = basename($file);
                continue;
            }

            unlink($file);
            ++$deleted;
        }

        if ($deleted > 0) {
            $this->logger->info('Backup cleanup completed', [
                'scheduled_task' => 'database_backup',
                'deleted_count' => $deleted,
                'monthly_kept' => $monthlyKept,
            ]);
        }

        return $deleted;
    }

    /** @param array{host: string, port: string, user: string, password: string, database: string} $params */
    private function dump(array $params, string $target): void
    {
        $command = sprintf(
            'mysqldump --single-transaction --no-tablespaces --routines --triggers --events -h%s -P%s -u%s %s | gzip > %s',
            escapeshellarg($params['host']),
            escapeshellarg($params['port']),
            escapeshellarg($params['user']),
            escapeshellarg($params['database']),
            escapeshellarg($target),
        );

        // `sh` (POSIX) reports the exit code of the LAST pipeline member (gzip),
        // so a failed mysqldump — bad host or user, no PROCESS privilege, MySQL
        // down — would exit 0 and leave a corrupted-but-"successful" backup on
        // disk. `bash -o pipefail` makes the pipeline's status the first non-zero
        // member's, so a mysqldump failure is detected the same way a gzip
        // failure already was. bash is installed by docker/php/Dockerfile for
        // this one reason; `make doctor` checks it is still there.
        $process = new Process(['bash', '-o', 'pipefail', '-c', $command]);
        $process->setTimeout(300);
        // The password goes through the environment, never the argument list —
        // an argument is visible to every process on the box via `ps`.
        $process->setEnv(['MYSQL_PWD' => $params['password']]);

        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Database backup failed: '.$process->getErrorOutput());
        }
    }

    /** @return array{host: string, port: string, user: string, password: string, database: string} */
    private function parseDatabaseUrl(): array
    {
        $parts = parse_url($this->databaseUrl);
        if (false === $parts || !isset($parts['host'], $parts['user'], $parts['pass'], $parts['path'])) {
            throw new RuntimeException('Invalid DATABASE_URL format');
        }

        return [
            'host' => $parts['host'],
            'port' => (string) ($parts['port'] ?? 3306),
            'user' => $parts['user'],
            'password' => $parts['pass'],
            'database' => ltrim($parts['path'], '/'),
        ];
    }
}
