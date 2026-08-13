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

        // Normalised to the start of the day before the window is measured. The
        // dates being compared against come from BackupFilename, which parses
        // with '!' and so always yields midnight; $today does not, because the
        // production caller passes `new DateTimeImmutable()` and the dump runs at
        // 03:00. Left un-normalised, the copy dated exactly $keepDays ago falls
        // on whichever side of the cutoff the clock happens to put it — kept when
        // the job is invoked at midnight, deleted at 03:00 — so the window is one
        // day shorter in production than every test of it describes, and a manual
        // `make backup-now` prunes differently from the nightly run over the same
        // set. Retention is expressed in whole days; the time of day it is
        // evaluated at must not be part of the answer.
        $cutoff = $today->setTime(0, 0)->modify(sprintf('-%d days', $keepDays));

        return array_values(array_filter(
            $backups,
            static fn (RemoteBackup $backup): bool => $backup->date < $cutoff,
        ));
    }
}
