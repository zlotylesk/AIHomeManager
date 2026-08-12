<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup\Destination;

use App\Infrastructure\Backup\BackupFilename;
use DateTimeImmutable;
use RuntimeException;

/**
 * Object storage, via rclone — the backend for a copy that is genuinely off the
 * machine rather than merely off its main disk.
 *
 * rclone rather than a vendor SDK because the requirement is "somewhere else",
 * not "S3": the same code reaches B2, S3, Drive or another box over SFTP, and
 * which one an instance uses becomes a line in an rclone config file instead of
 * a dependency in `composer.json`.
 *
 * The remote is addressed only through `BACKUP_REMOTE_TARGET` (`remote:path`);
 * credentials live in rclone's own config, so no secret passes through this
 * class, appears in an argument list, or can be printed by an error message here.
 */
final readonly class RcloneBackupDestination implements BackupDestinationInterface
{
    private const int PUSH_TIMEOUT_SECONDS = 1800;
    private const int QUERY_TIMEOUT_SECONDS = 120;

    public function __construct(
        private CommandRunnerInterface $runner,
        private string $target,
        private int $retentionDays,
        private string $binary = 'rclone',
    ) {
    }

    public function name(): string
    {
        return 'rclone';
    }

    public function isConfigured(): bool
    {
        return '' !== $this->target;
    }

    public function describe(): string
    {
        return $this->target;
    }

    public function push(string $localPath): void
    {
        // `copyto` names the destination file explicitly rather than dropping the
        // source into a directory, so the remote keeps exactly the local name and
        // the listing stays parseable by the same BackupFilename everything else
        // uses.
        //
        // No verification pass afterwards: rclone checks the uploaded object's
        // hash against the source as part of the transfer and exits non-zero when
        // they disagree, so a second round trip would re-ask a question already
        // answered — and answer it less well, since a size comparison cannot see
        // corruption that preserves length.
        $this->runner->run([
            $this->binary,
            'copyto',
            $localPath,
            $this->target.'/'.basename($localPath),
        ], self::PUSH_TIMEOUT_SECONDS);
    }

    public function listBackups(): array
    {
        $output = $this->runner->run([
            $this->binary,
            'lsjson',
            $this->target,
        ], self::QUERY_TIMEOUT_SECONDS);

        $rows = json_decode($output, true);

        if (!\is_array($rows)) {
            throw new RuntimeException(sprintf('Could not read the backup listing from %s: rclone returned no usable JSON.', $this->target));
        }

        $backups = [];

        foreach ($rows as $row) {
            if (!\is_array($row) || !isset($row['Name']) || !\is_string($row['Name'])) {
                continue;
            }

            $date = BackupFilename::dateOf($row['Name']);

            if (null === $date) {
                continue;
            }

            // Size is -1 on backends that do not report one. Treating that as
            // zero would make every such object look like an empty dump and put
            // the probe permanently into alarm, so it is passed through as-is and
            // the probe decides what an unknown size means.
            $bytes = isset($row['Size']) && is_numeric($row['Size']) ? (int) $row['Size'] : -1;

            $backups[] = new RemoteBackup($row['Name'], $bytes, $date);
        }

        return $backups;
    }

    public function prune(DateTimeImmutable $today): int
    {
        $deleted = 0;

        // Deleted one at a time by name, rather than with rclone's own
        // `--min-age`, so the off-host window is decided by the same
        // RemoteRetention every backend uses and by the date in the filename —
        // not by the modification time rclone happens to have recorded, which a
        // re-upload or a server-side copy resets.
        foreach (RemoteRetention::expired($this->listBackups(), $today, $this->retentionDays) as $backup) {
            $this->runner->run([
                $this->binary,
                'deletefile',
                $this->target.'/'.$backup->name,
            ], self::QUERY_TIMEOUT_SECONDS);

            ++$deleted;
        }

        return $deleted;
    }
}
