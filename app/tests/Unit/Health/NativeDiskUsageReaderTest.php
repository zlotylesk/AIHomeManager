<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use App\Health\NativeDiskUsageReader;
use PHPUnit\Framework\TestCase;

/**
 * There is no way to arrange a genuinely 96 %-full filesystem inside a test,
 * which is why the thresholds are pinned against a fake through
 * DiskUsageReaderInterface. What cannot be faked is the one thing this class
 * owes its caller: a reading it could not take comes back as null, never as a
 * number and never as an exception.
 *
 * That contract is load-bearing. HealthChecker turns null into 'degraded' with a
 * logged warning — not knowing how much space is left is not the same as knowing
 * there is none — so a reader that threw, or that returned a plausible ratio for
 * a path it never measured, would turn a missing directory into either a 500 on
 * a public endpoint or a silent all-clear.
 */
final class NativeDiskUsageReaderTest extends TestCase
{
    public function testARealPathReadsAsARatioBetweenZeroAndOne(): void
    {
        $ratio = new NativeDiskUsageReader()->usedRatio(sys_get_temp_dir());

        self::assertNotNull($ratio);
        self::assertGreaterThanOrEqual(0.0, $ratio);
        self::assertLessThanOrEqual(1.0, $ratio);
    }

    /**
     * A path that is not there is the realistic failure: DATABASE_DATA_DIR or
     * BACKUP_DIR pointing at a volume that never got mounted. PHP's two wrappers
     * both raise a warning and return false for it, so this also pins the
     * silencing operator — remove it and the warning surfaces as a PHPUnit
     * failure here rather than as noise in a production log.
     */
    public function testAPathThatDoesNotExistIsUnmeasurableRatherThanFull(): void
    {
        $missing = sys_get_temp_dir().'/aihm_absent_'.uniqid();
        self::assertDirectoryDoesNotExist($missing);

        self::assertNull(new NativeDiskUsageReader()->usedRatio($missing));
    }

    /**
     * The empty string reaches the wrappers as an invalid path rather than as
     * "the current directory", and an env var left unset is exactly how it would
     * get here. It must not read as a measurement.
     */
    public function testAnEmptyPathIsUnmeasurable(): void
    {
        self::assertNull(new NativeDiskUsageReader()->usedRatio(''));
    }
}
