<?php

declare(strict_types=1);

namespace App\Health;

/**
 * `statvfs` through PHP's two wrappers, which report on the filesystem that
 * actually holds the path — a mount point therefore answers for the mounted
 * device and not for the directory it hangs off. That is the whole reason the
 * probe is given paths rather than a container root.
 *
 * Two properties of that pair, stated rather than discovered later:
 *
 * 1. **`disk_free_space` reports what is free to THIS process, `disk_total_space`
 *    the whole device.** ext4 reserves 5 % for root by default, so a filesystem
 *    a normal user has entirely filled reads as ~95 % rather than 100 %, and
 *    the ratio runs a few points ahead of what `df` shows a root shell. The
 *    thresholds are calibrated against this reading, which is also the one that
 *    matters: the reserve is not space MySQL or the backup job can use.
 * 2. **`statvfs` can block on a hung mount** — a dead NFS export or an
 *    unresponsive network share holds the call until the kernel gives up, and
 *    there is no timeout to pass it from PHP. Both measured paths are local (a
 *    named volume and a bind mount), which is what keeps this theoretical here;
 *    an off-host destination is deliberately measured by `BackupOffsiteProbe`
 *    on the monitoring sweep instead, off the request path.
 */
final readonly class NativeDiskUsageReader implements DiskUsageReaderInterface
{
    public function usedRatio(string $path): ?float
    {
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if (false === $free || false === $total || $total <= 0.0) {
            return null;
        }

        return 1.0 - ($free / $total);
    }
}
