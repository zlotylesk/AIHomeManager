<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup\Destination;

use InvalidArgumentException;

/**
 * Picks the off-host destination from `BACKUP_REMOTE_BACKEND`.
 *
 * A factory rather than a container alias for the same reason the search backend
 * uses one: an alias is resolved when the container is compiled and cannot read
 * an environment variable, so the choice has to be made by something that runs.
 *
 * An unrecognised value is refused here, at boot, instead of falling back to
 * "none". A typo that silently disabled off-host backups would produce an
 * instance that looks configured, reports nothing wrong, and is copying nothing
 * anywhere — the failure mode this whole ticket is about, reintroduced by the
 * one line meant to prevent it.
 */
final readonly class BackupDestinationFactory
{
    public const string DIRECTORY = 'directory';
    public const string RCLONE = 'rclone';
    public const string NONE = 'none';

    public function __construct(
        private CommandRunnerInterface $runner,
        private string $backend,
        private string $remoteDir,
        private string $rcloneTarget,
        private string $localDir,
        private int $retentionDays,
        private bool $allowSameFilesystem = false,
    ) {
    }

    public function create(): BackupDestinationInterface
    {
        return match ($this->backend) {
            self::NONE => new NullBackupDestination(),
            self::DIRECTORY => $this->directory(),
            self::RCLONE => $this->rclone(),
            default => throw new InvalidArgumentException(sprintf(
                'Unknown BACKUP_REMOTE_BACKEND "%s". Expected one of: %s.',
                $this->backend,
                implode(', ', [self::NONE, self::DIRECTORY, self::RCLONE]),
            )),
        };
    }

    private function directory(): BackupDestinationInterface
    {
        if ('' === $this->remoteDir) {
            throw new InvalidArgumentException('BACKUP_REMOTE_BACKEND=directory needs BACKUP_REMOTE_DIR to point at the mounted off-host storage.');
        }

        return new LocalDirectoryBackupDestination(
            $this->remoteDir,
            $this->localDir,
            $this->retentionDays,
            $this->allowSameFilesystem,
        );
    }

    private function rclone(): BackupDestinationInterface
    {
        if ('' === $this->rcloneTarget) {
            throw new InvalidArgumentException('BACKUP_REMOTE_BACKEND=rclone needs BACKUP_REMOTE_TARGET, in rclone\'s own "remote:path" form.');
        }

        return new RcloneBackupDestination($this->runner, $this->rcloneTarget, $this->retentionDays);
    }
}
