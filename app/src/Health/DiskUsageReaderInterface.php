<?php

declare(strict_types=1);

namespace App\Health;

/**
 * How full is the filesystem a given path lives on.
 *
 * A port rather than two bare function calls inside the checker, because the
 * thresholds are the part worth pinning and there is no way to arrange for a
 * real filesystem to be 96 % full inside a test. With this, the boundary values
 * are supplied directly and the assertions can fail.
 */
interface DiskUsageReaderInterface
{
    /**
     * Fraction of the filesystem holding $path that is in use, 0.0–1.0.
     *
     * `null` means the question could not be answered — the path is not there,
     * the mount has gone, the call was refused. Deliberately distinct from
     * "full": not knowing how much space is left is not the same as knowing
     * there is none, and the two lead to different reports.
     */
    public function usedRatio(string $path): ?float;
}
