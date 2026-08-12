<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup\Destination;

use App\Infrastructure\Backup\BackupFilename;
use DateTimeImmutable;
use RuntimeException;

/**
 * A second directory — in practice a mounted NAS share, an external disk or an
 * NFS export, i.e. storage that does not die with the database's own disk.
 *
 * The plain answer to the requirement, and the one that needs nothing installed.
 * It is only genuinely off-host if what is mounted there is off-host: pointing
 * `BACKUP_REMOTE_DIR` at another folder on the same filesystem satisfies every
 * check here while protecting against nothing, so {@see push} refuses that case
 * outright rather than reporting a success that is worth nothing.
 */
final readonly class LocalDirectoryBackupDestination implements BackupDestinationInterface
{
    /**
     * @param bool $allowSameFilesystem the operator's assertion that something
     *                                  else — Syncthing, a Dropbox client, a
     *                                  cron'd rsync — carries this directory off
     *                                  the machine, which is the one honest
     *                                  reason for the copy to sit on the same
     *                                  disk. Off by default, because the same
     *                                  configuration with nothing watching the
     *                                  directory is a backup strategy that
     *                                  protects against nothing while passing
     *                                  every check
     */
    public function __construct(
        private string $remoteDir,
        private string $localDir,
        private int $retentionDays,
        private bool $allowSameFilesystem = false,
    ) {
    }

    public function name(): string
    {
        return 'directory';
    }

    public function isConfigured(): bool
    {
        return '' !== $this->remoteDir;
    }

    public function describe(): string
    {
        return $this->remoteDir;
    }

    public function push(string $localPath): void
    {
        if (!is_dir($this->remoteDir)) {
            throw new RuntimeException(sprintf('Off-host backup directory %s does not exist — is the remote volume still mounted?', $this->remoteDir));
        }

        $this->assertDistinctDevice();

        $target = $this->remoteDir.'/'.basename($localPath);

        // Copy to a temporary name and rename into place. A rename within one
        // filesystem is atomic, so a copy interrupted halfway — the mount
        // dropping mid-transfer is the ordinary way this fails — leaves a
        // discarded partial file rather than a short one wearing tonight's name
        // and passing every freshness check that follows.
        $staging = $target.'.part';

        if (!@copy($localPath, $staging)) {
            @unlink($staging);

            throw new RuntimeException(sprintf('Copying the backup to %s failed.', $this->remoteDir));
        }

        $expected = filesize($localPath);
        $actual = filesize($staging);

        if (false === $expected || $actual !== $expected) {
            @unlink($staging);

            throw new RuntimeException(sprintf('The copy at %s is %s bytes, the local backup is %s — the destination is probably full.', $this->remoteDir, var_export($actual, true), var_export($expected, true)));
        }

        if (!@rename($staging, $target)) {
            @unlink($staging);

            throw new RuntimeException(sprintf('Could not move the copied backup into place at %s.', $target));
        }
    }

    public function listBackups(): array
    {
        $files = glob($this->remoteDir.'/'.BackupFilename::GLOB);

        if (false === $files) {
            throw new RuntimeException(sprintf('Cannot read the off-host backup directory %s.', $this->remoteDir));
        }

        $backups = [];

        foreach ($files as $file) {
            $date = BackupFilename::dateOf(basename($file));
            $bytes = @filesize($file);

            if (null === $date || false === $bytes) {
                continue;
            }

            $backups[] = new RemoteBackup(basename($file), $bytes, $date);
        }

        return $backups;
    }

    public function prune(DateTimeImmutable $today): int
    {
        $deleted = 0;

        foreach (RemoteRetention::expired($this->listBackups(), $today, $this->retentionDays) as $backup) {
            if (@unlink($this->remoteDir.'/'.$backup->name)) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * Refuses a destination that shares a device with the backups it is supposed
     * to outlive.
     *
     * `stat` reports the device id of the filesystem a path lives on, which is
     * what actually distinguishes a mount from a subdirectory — comparing the
     * paths as strings would accept `/backups/offsite`, and a path check cannot
     * see that a bind mount and its source are the same disk either. The check
     * is here, at push time, rather than at boot, because a mount that has
     * silently unmounted since startup collapses back onto the host filesystem
     * and would otherwise start "succeeding" again while protecting nothing.
     */
    private function assertDistinctDevice(): void
    {
        if ($this->allowSameFilesystem) {
            return;
        }

        $remote = @stat($this->remoteDir);
        $local = @stat($this->localDir);

        if (false === $remote || false === $local) {
            return;
        }

        if ($remote['dev'] === $local['dev']) {
            throw new RuntimeException(sprintf('BACKUP_REMOTE_DIR (%s) is on the same filesystem as BACKUP_DIR (%s), so it is not an off-host copy — one disk failure would take both. Mount remote storage there; or, if something else replicates that directory off the machine, say so with BACKUP_REMOTE_ALLOW_SAME_FILESYSTEM=1; or set BACKUP_REMOTE_BACKEND=none to record that off-host backups are deliberately not configured.', $this->remoteDir, $this->localDir));
        }
    }
}
