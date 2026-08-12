<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup\Destination;

use DateTimeImmutable;

/**
 * No off-host copy — the setting a developer laptop runs on.
 *
 * It exists so that "we are not copying backups anywhere" is something an
 * instance SAYS rather than something it merely does. `BACKUP_REMOTE_BACKEND`
 * has to name this backend explicitly; there is no implicit fallback to it, and
 * an unrecognised value is refused at boot instead of degrading to here. That
 * distinction is the whole reason this class is not just a null check somewhere:
 * `isConfigured()` returning false lets the probes and `make doctor` report
 * "switched off on purpose" instead of alerting, and lets them alert loudly when
 * a real backend is selected and failing — two states that a silent default
 * would have collapsed into one.
 */
final readonly class NullBackupDestination implements BackupDestinationInterface
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function describe(): string
    {
        return 'no off-host destination (BACKUP_REMOTE_BACKEND=none)';
    }

    public function push(string $localPath): void
    {
    }

    public function listBackups(): array
    {
        return [];
    }

    public function prune(DateTimeImmutable $today): int
    {
        return 0;
    }
}
