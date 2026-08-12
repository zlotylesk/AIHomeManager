<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup\Destination;

use RuntimeException;

/**
 * Runs an external command and returns its standard output.
 *
 * It exists so {@see RcloneBackupDestination} can be tested at all. Everything
 * interesting about that class — the arguments it builds, how it reads a
 * listing, what it does with a non-zero exit — is logic that would otherwise be
 * reachable only on a machine with rclone installed and a real remote
 * configured, which is to say never, in CI or anywhere else.
 */
interface CommandRunnerInterface
{
    /**
     * @param list<string> $command
     *
     * @return string standard output
     *
     * @throws RuntimeException when the command is missing or exits non-zero
     */
    public function run(array $command, int $timeoutSeconds): string;
}
