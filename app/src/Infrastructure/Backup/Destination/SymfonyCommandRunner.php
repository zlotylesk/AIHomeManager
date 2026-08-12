<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup\Destination;

use RuntimeException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

final readonly class SymfonyCommandRunner implements CommandRunnerInterface
{
    public function run(array $command, int $timeoutSeconds): string
    {
        $process = new Process($command);
        $process->setTimeout((float) $timeoutSeconds);

        try {
            $process->run();
        } catch (ExceptionInterface $e) {
            // A missing binary and a timeout both land here, and both mean the
            // off-host copy did not happen — which has to travel as a failure
            // rather than as an empty output string that reads like an empty
            // remote.
            throw new RuntimeException(sprintf('%s could not be run: %s', $command[0] ?? 'command', $e->getMessage()), previous: $e);
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                '%s exited with %s: %s',
                $command[0] ?? 'command',
                var_export($process->getExitCode(), true),
                trim($process->getErrorOutput()) ?: trim($process->getOutput()),
            ));
        }

        return $process->getOutput();
    }
}
