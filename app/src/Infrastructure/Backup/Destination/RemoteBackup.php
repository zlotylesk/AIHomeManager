<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup\Destination;

use DateTimeImmutable;

/**
 * One backup as it exists at the off-host destination.
 *
 * Primitives and a date, nothing that ties it to how the destination stores
 * things — a directory listing and an `rclone lsjson` row both reduce to this,
 * so the probe that judges freshness never learns which backend it is looking at.
 */
final readonly class RemoteBackup
{
    public function __construct(
        public string $name,
        public int $bytes,
        public DateTimeImmutable $date,
    ) {
    }
}
