<?php

declare(strict_types=1);

namespace App\Application\Scheduled;

use App\Monitoring\SystemMonitor;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the monitoring sweep inside the scheduler worker.
 *
 * **Deliberately not routed to the async transport.** Every other recurring
 * command is, and this one must not be: the async worker dying is one of the
 * failures being watched for, and routing this message there would mean the
 * alert about a dead worker sits in a queue that dead worker was supposed to
 * consume. It would also need RabbitMQ to be up in order to report that
 * RabbitMQ is down. Running inline in the scheduler worker leaves the sweep
 * dependent on nothing but that process and the local disk.
 *
 * The scheduler worker's own death is therefore the blind spot, and it is a
 * real one — `app:monitor:run` exists so an external timer can cover it. See
 * `docs/operations.md`, section "Failure alerting".
 */
#[AsMessageHandler]
final readonly class MonitorSystemHealthHandler
{
    public function __construct(
        private SystemMonitor $monitor,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(MonitorSystemHealth $message): void
    {
        $summary = $this->monitor->run(new DateTimeImmutable());

        // Only when something was actually said. A sweep every five minutes that
        // logged "nothing wrong" would bury the twelve lines a day that matter
        // under three hundred that do not — and the log is where somebody looks
        // to reconstruct what an outage did, after the e-mail told them it
        // happened.
        if ([] === $summary->announced) {
            return;
        }

        $this->logger->info('Operational alerts announced.', [
            'scheduled_task' => 'monitor_system_health',
            'announced' => $summary->announced,
            'standing' => $summary->standing,
        ]);
    }
}
