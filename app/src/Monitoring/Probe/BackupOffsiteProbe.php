<?php

declare(strict_types=1);

namespace App\Monitoring\Probe;

use App\Infrastructure\Backup\Destination\BackupDestinationInterface;
use App\Infrastructure\Backup\Destination\RemoteBackup;
use App\Monitoring\Alert;
use App\Monitoring\AlertProbeInterface;
use App\Monitoring\AlertSeverity;
use DateTimeImmutable;
use Throwable;

/**
 * Watches the copy that is supposed to be somewhere else.
 *
 * {@see BackupFreshnessProbe} answers "is there a recent, plausible dump on this
 * machine", which is a different question and stays true right up until the
 * machine is the thing that is lost. This one answers the question that survives
 * that: is there a recent, plausible copy somewhere the machine's own failure
 * cannot reach.
 *
 * It is also what makes a failed upload an event rather than a log line. The
 * nightly job deliberately does not retry or fail its message when the
 * destination is unreachable — the dump succeeded, and re-running would dump the
 * database again — so without something reading the destination on a timer, an
 * off-host copy could stop happening in silence and be discovered during a
 * restore. Which is when nobody wants to discover anything.
 *
 * Like the local probe it checks the OUTCOME, not the causes: whatever new way
 * the upload finds to fail, the newest object at the destination is either
 * recent and plausible, or it is not.
 */
final readonly class BackupOffsiteProbe implements AlertProbeInterface
{
    public function __construct(
        private BackupDestinationInterface $destination,
        private int $maxAgeHours,
        private int $minBytes,
    ) {
    }

    public function name(): string
    {
        return 'backup_offsite';
    }

    public function probe(DateTimeImmutable $at): array
    {
        // Nothing to report when off-host copies are switched off. It is a
        // legitimate configuration — a laptop — and it is a *stated* one:
        // BACKUP_REMOTE_BACKEND has to name `none` explicitly, an unknown value
        // is refused at boot, and `make doctor` says it out loud on every run.
        // Alerting here would put every development instance permanently into
        // alarm, and an alert that is always firing is one people stop reading.
        if (!$this->destination->isConfigured()) {
            return [];
        }

        try {
            $backups = $this->destination->listBackups();
        } catch (Throwable $e) {
            return [new Alert(
                key: 'unreachable',
                severity: AlertSeverity::CRITICAL,
                title: 'The off-host backup destination cannot be read',
                detail: sprintf(
                    "%s (%s) did not answer: %s\n\nUntil this clears, every backup exists only on the machine it is protecting. Check the mount or the remote's credentials, then run `make backup-now` to push tonight's copy.",
                    $this->destination->describe(),
                    $this->destination->name(),
                    $e->getMessage(),
                ),
            )];
        }

        $newest = $this->newest($backups);

        if (null === $newest) {
            return [new Alert(
                key: 'missing',
                severity: AlertSeverity::CRITICAL,
                title: 'There is no off-host backup at all',
                detail: sprintf(
                    "%s is reachable but holds no backup.\n\nEvery restore point currently lives on one machine. Run `make backup-now` and read what it says about the off-host copy.",
                    $this->destination->describe(),
                ),
            )];
        }

        $ageHours = (int) floor(($at->getTimestamp() - $newest->date->getTimestamp()) / 3600);

        if ($ageHours > $this->maxAgeHours) {
            return [new Alert(
                key: 'stale',
                severity: AlertSeverity::CRITICAL,
                title: sprintf('The newest off-host backup is %d h old', $ageHours),
                detail: sprintf(
                    "%s at %s is the most recent copy and the limit is %d h.\n\nThe local backup may well be fine — this says the copy stopped leaving the machine. The nightly job logs `Off-host backup copy failed` with the reason; it does not retry, by design, so the fix is to repair the destination and run `make backup-now`.",
                    $newest->name,
                    $this->destination->describe(),
                    $this->maxAgeHours,
                ),
            )];
        }

        // A negative size means the backend does not report one (some rclone
        // remotes), not that the object is empty. Treating unknown as zero would
        // hold the alert open forever on those backends, and an alert that can
        // never clear teaches people to ignore the ones that can.
        if ($newest->bytes >= 0 && $newest->bytes < $this->minBytes) {
            return [new Alert(
                key: 'empty',
                severity: AlertSeverity::CRITICAL,
                title: sprintf('The newest off-host backup is only %d bytes', $newest->bytes),
                detail: sprintf(
                    "%s at %s is recent but too small to hold a dump (minimum %d bytes).\n\nMost likely the upload was cut short. Re-run `make backup-now`; if it recurs, the destination is probably out of space.",
                    $newest->name,
                    $this->destination->describe(),
                    $this->minBytes,
                ),
            )];
        }

        return [];
    }

    /** @param list<RemoteBackup> $backups */
    private function newest(array $backups): ?RemoteBackup
    {
        $newest = null;

        foreach ($backups as $backup) {
            if (null === $newest || $backup->date > $newest->date) {
                $newest = $backup;
            }
        }

        return $newest;
    }
}
