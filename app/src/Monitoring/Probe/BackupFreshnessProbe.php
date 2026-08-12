<?php

declare(strict_types=1);

namespace App\Monitoring\Probe;

use App\Infrastructure\Backup\BackupFilename;
use App\Monitoring\Alert;
use App\Monitoring\AlertProbeInterface;
use App\Monitoring\AlertSeverity;
use DateTimeImmutable;

/**
 * Answers the only question about backups that matters: is there a recent one,
 * and is it big enough to be real.
 *
 * It checks the **outcome** rather than the causes. A failing backup already
 * logs an error, and that log is exactly what nobody read for six days while
 * every nightly dump came out empty. Whatever new way the job finds to fail — a
 * missing client library, a dead worker, a full disk, a credential change — the
 * newest dump on disk is either fresh and plausible, or it is not.
 *
 * The thresholds and the way age is measured are deliberately the same ones
 * `scripts/doctor.sh` uses, down to the `BACKUP_MAX_AGE_HOURS` variable. Two
 * answers to "is the backup fresh" in one repository is how one of them quietly
 * starts tolerating a state the other rejects.
 *
 * Three separate states rather than one "backup is broken": what to do about a
 * job that never ran differs from what to do about one that produced twenty
 * bytes, and the alert should say which.
 */
final readonly class BackupFreshnessProbe implements AlertProbeInterface
{
    /**
     * @param int $maxAgeHours how old the newest dump may be. 48 rather than 24,
     *                         because the 03:00 cron is not when the job actually
     *                         runs on a host that is off overnight — the Scheduler
     *                         fires the missed window whenever it next comes up,
     *                         and a check that cries wolf on an ordinary day is one
     *                         people learn to ignore
     * @param int $minBytes    below this a gzip stream cannot hold a real dump —
     *                         an empty one is about twenty bytes
     */
    public function __construct(
        private string $backupDir,
        private int $maxAgeHours,
        private int $minBytes,
    ) {
    }

    public function name(): string
    {
        return 'backup';
    }

    public function probe(DateTimeImmutable $at): array
    {
        $newest = $this->newestBackup();

        if (null === $newest) {
            return [new Alert(
                key: 'missing',
                severity: AlertSeverity::CRITICAL,
                title: 'There is no database backup at all',
                detail: sprintf(
                    "No file matching %s exists in %s.\n\nRun `make backup-now` and read the output. If the directory itself is missing, check that the backups volume is mounted into the scheduler worker. Note that only encrypted `%s` artifacts count — a leftover plaintext dump is not something this system will restore from.",
                    BackupFilename::GLOB,
                    $this->backupDir,
                    BackupFilename::SUFFIX,
                ),
            )];
        }

        [$path, $date, $bytes] = $newest;
        $ageHours = (int) floor(($at->getTimestamp() - $date->getTimestamp()) / 3600);

        if ($ageHours > $this->maxAgeHours) {
            return [new Alert(
                key: 'stale',
                severity: AlertSeverity::CRITICAL,
                title: sprintf('The newest database backup is %d h old', $ageHours),
                detail: sprintf(
                    "%s is the most recent dump and the limit is %d h.\n\nEither the nightly job stopped firing — check that scheduler_worker is alive — or it ran and failed, in which case it deleted its own partial file and the reason is in the worker log.",
                    basename($path),
                    $this->maxAgeHours,
                ),
            )];
        }

        if ($bytes < $this->minBytes) {
            return [new Alert(
                key: 'empty',
                severity: AlertSeverity::CRITICAL,
                title: sprintf('The newest database backup is only %d bytes', $bytes),
                detail: sprintf(
                    "%s is recent but too small to contain a dump (minimum %d bytes).\n\nCheck it with `gunzip -c … | head`. A dump that is technically valid and empty is how six days of restore points were lost once already.",
                    basename($path),
                    $this->minBytes,
                ),
            )];
        }

        return [];
    }

    /**
     * The most recent dump, dated by the filename — see {@see BackupFilename}
     * for why that rather than mtime, and read through the same parser as every
     * other reader so this probe cannot develop its own idea of what a backup is.
     *
     * @return array{string, DateTimeImmutable, int}|null
     */
    private function newestBackup(): ?array
    {
        $files = glob($this->backupDir.'/'.BackupFilename::GLOB);

        if (false === $files || [] === $files) {
            return null;
        }

        $newest = null;

        foreach ($files as $file) {
            $date = BackupFilename::dateOf(basename($file));
            $bytes = @filesize($file);

            if (null === $date || false === $bytes) {
                continue;
            }

            if (null === $newest || $date > $newest[1]) {
                $newest = [$file, $date, $bytes];
            }
        }

        return $newest;
    }
}
