<?php

declare(strict_types=1);

namespace App\Monitoring;

use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs one monitoring sweep from the command line.
 *
 * Two uses. The first is checking by hand that alerting works at all, without
 * waiting up to five minutes for the scheduler. The second is the reason it is
 * not merely a convenience: the scheduled sweep runs inside the scheduler
 * worker, so that worker's own death is the one failure it cannot report. An
 * external timer — host cron, a systemd timer, anything outside the stack —
 * calling this command closes that hole.
 *
 * The exit code is deliberately success whenever the sweep completed. Alerts
 * found are not an error of this command; a caller wanting a non-zero code on
 * an unhealthy system wants `/api/health`, which is what that endpoint is for.
 */
#[AsCommand(
    name: 'app:monitor:run',
    description: 'Run one monitoring sweep and announce anything that changed',
)]
final class RunMonitorCommand extends Command
{
    public function __construct(private readonly SystemMonitor $monitor)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $summary = $this->monitor->run(new DateTimeImmutable());

        $output->writeln(sprintf(
            'Announced: %s',
            [] === $summary->announced ? '(nothing changed)' : implode(', ', $summary->announced),
        ));
        $output->writeln(sprintf(
            'Standing:  %s',
            [] === $summary->standing ? '(nothing failing)' : implode(', ', $summary->standing),
        ));

        return Command::SUCCESS;
    }
}
