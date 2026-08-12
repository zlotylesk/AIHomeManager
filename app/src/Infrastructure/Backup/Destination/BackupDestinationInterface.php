<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup\Destination;

use DateTimeImmutable;
use RuntimeException;

/**
 * Where the encrypted dump goes once it exists locally.
 *
 * The point of the port is that "off host" has more than one honest answer — a
 * mounted NAS or external disk for one instance, object storage for another —
 * and the job that makes the backup, the probe that watches it and the doctor
 * script should not each grow a branch per answer.
 *
 * Every method reports failure by throwing. A destination that returned false,
 * or logged and carried on, would reproduce the exact fault this work exists to
 * remove: a backup pipeline that reports success while producing nothing usable.
 */
interface BackupDestinationInterface
{
    /** Short backend name — what `make doctor` and the logs call this destination. */
    public function name(): string;

    /**
     * Whether an off-host copy is actually configured.
     *
     * False is a legitimate state (a laptop backing up nothing off-host), and it
     * is why the probes can tell "switched off on purpose" apart from "switched
     * on and broken" — two situations that need opposite responses.
     */
    public function isConfigured(): bool;

    /** Human-readable target, for logs and operator messages. Never contains a secret. */
    public function describe(): string;

    /** @throws RuntimeException when the copy did not arrive intact */
    public function push(string $localPath): void;

    /**
     * What is at the destination now, newest last is not guaranteed — callers sort.
     *
     * @return list<RemoteBackup>
     *
     * @throws RuntimeException when the destination cannot be read at all
     */
    public function listBackups(): array;

    /**
     * Applies the destination's own retention.
     *
     * Its own, and deliberately longer than the local one: the off-host copy is
     * the one that has to survive losing the machine, and a remote that mirrors
     * local retention exactly would lose the same days at the same moment.
     *
     * @return int how many were removed
     */
    public function prune(DateTimeImmutable $today): int;
}
