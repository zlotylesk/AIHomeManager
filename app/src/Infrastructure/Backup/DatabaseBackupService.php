<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

readonly class DatabaseBackupService
{
    private const int DAILY_RETENTION_DAYS = 30;
    private const int MONTHLY_RETENTION_COUNT = 12;
    private const string FILENAME_PATTERN = 'homemanager-%s.sql.gz';
    private const string FILENAME_REGEX = '/^homemanager-(\d{4}-\d{2}-\d{2})\.sql\.gz$/';

    public function __construct(
        private string $databaseUrl,
        private string $backupDir,
        private LoggerInterface $logger,
    ) {
    }

    public function backup(?DateTimeImmutable $date = null): string
    {
        $date ??= new DateTimeImmutable();
        $filename = sprintf(self::FILENAME_PATTERN, $date->format('Y-m-d'));
        $filepath = $this->backupDir.'/'.$filename;

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0o755, true);
        }

        $params = $this->parseDatabaseUrl();

        $command = sprintf(
            'mysqldump --single-transaction --no-tablespaces --routines --triggers --events -h%s -P%s -u%s %s | gzip > %s',
            escapeshellarg($params['host']),
            escapeshellarg($params['port']),
            escapeshellarg($params['user']),
            escapeshellarg($params['database']),
            escapeshellarg($filepath),
        );

        $process = new Process(['sh', '-c', $command]);
        $process->setTimeout(300);
        $process->setEnv(['MYSQL_PWD' => $params['password']]);

        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error('Database backup failed', [
                'exit_code' => $process->getExitCode(),
                'stderr' => $process->getErrorOutput(),
            ]);

            throw new RuntimeException('Database backup failed: '.$process->getErrorOutput());
        }

        $this->logger->info('Database backup completed', [
            'scheduled_task' => 'database_backup',
            'file' => $filename,
            'size_bytes' => filesize($filepath),
        ]);

        return $filepath;
    }

    public function cleanup(?DateTimeImmutable $today = null): int
    {
        $today ??= new DateTimeImmutable();
        $cutoffDaily = $today->modify(sprintf('-%d days', self::DAILY_RETENTION_DAYS));

        $files = glob($this->backupDir.'/homemanager-*.sql.gz');
        if (false === $files) {
            return 0;
        }

        $candidates = [];

        foreach ($files as $file) {
            $basename = basename($file);
            if (!preg_match(self::FILENAME_REGEX, $basename, $matches)) {
                continue;
            }

            $fileDate = new DateTimeImmutable($matches[1]);

            if ($fileDate >= $cutoffDaily) {
                continue;
            }

            $candidates[$file] = $fileDate;
        }

        // Sort newest-first so the 12 most recent 1st-of-month backups are kept
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
