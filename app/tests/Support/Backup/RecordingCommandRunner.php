<?php

declare(strict_types=1);

namespace App\Tests\Support\Backup;

use App\Infrastructure\Backup\Destination\CommandRunnerInterface;
use RuntimeException;

/**
 * Records the commands an rclone destination would run, and hands back whatever
 * answer a test wants rclone to have given.
 *
 * A hand-written double rather than a stub because the assertions are about the
 * exact argument lists — `copyto` and not `copy`, the remote path built from the
 * local filename — and those read far better as recorded arrays than as a stack
 * of `with()` expectations.
 */
final class RecordingCommandRunner implements CommandRunnerInterface
{
    /** @var list<list<string>> */
    public array $commands = [];
    public string $output = '[]';
    public ?RuntimeException $fail = null;

    public function run(array $command, int $timeoutSeconds): string
    {
        $this->commands[] = $command;

        if (null !== $this->fail) {
            throw $this->fail;
        }

        return $this->output;
    }
}
