<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup\Destination;

use DateTimeImmutable;

/**
 * Which off-host copies have aged out.
 *
 * Shared by every destination rather than reimplemented per backend, so the
 * answer to "how long do we keep off-host copies" cannot come out different
 * depending on where they happen to be stored.
 *
 * Simpler than the local policy (30 daily + 12 first-of-month) on purpose. The
 * local directory is a working set someone browses; the off-host copy is a
 * safety net whose only question is how far back it reaches, and a plain window
 * answers that in one number an operator can hold in their head.
 */
final readonly class RemoteRetention
{
    /**
     * @param list<RemoteBackup> $backups
     *
     * @return list<RemoteBackup>
     */
    public static function expired(array $backups, DateTimeImmutable $today, int $keepDays): array
    {
        // Never let a bad window empty the destination. A misconfigured
        // BACKUP_REMOTE_RETENTION_DAYS of 0 or -1 would otherwise put every
        // copy, including last night's, on the deletion list — a retention
        // setting quietly acting as a delete-everything switch. Checked before
        // the cutoff is computed, since a negative window makes it a date in the
        // future and there is no sensible answer to derive from that.
        if ($keepDays < 1) {
            return [];
        }

        $cutoff = $today->modify(sprintf('-%d days', $keepDays));

        return array_values(array_filter(
            $backups,
            static fn (RemoteBackup $backup): bool => $backup->date < $cutoff,
        ));
    }
}
