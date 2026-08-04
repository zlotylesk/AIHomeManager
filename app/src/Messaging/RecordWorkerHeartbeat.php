<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Health\WorkerHeartbeat;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * HMAI-418: the worker half of the liveness signal read by {@see \App\Health\HealthChecker}.
 *
 * Listens on the Messenger worker's own loop, so it covers every transport a
 * worker consumes without naming any of them here — the transports are read off
 * the running worker, which means a third worker added later reports for itself
 * with no change to this class.
 *
 * Two events, for one reason. `WorkerRunningEvent` fires on every loop
 * including the idle ones, which is the normal case and the one that matters:
 * an idle worker is alive. `WorkerMessageReceivedEvent` fires just before a
 * message is handled, and it narrows the blind spot — the loop event does not
 * fire again until the handler returns, so a slow job (a full Trakt import)
 * would otherwise go quiet from the moment it started rather than from the
 * moment it was picked up.
 *
 * That blind spot cannot be closed entirely from here: a single message taking
 * longer than the staleness threshold will read as `degraded` while the worker
 * is in fact working hard. It is a deliberate trade — the reading is HTTP 200
 * and informational either way, and the failure actually worth catching is a
 * worker that has been dead for hours.
 */
final readonly class RecordWorkerHeartbeat
{
    public function __construct(private WorkerHeartbeat $heartbeat)
    {
    }

    #[AsEventListener(event: WorkerRunningEvent::class)]
    public function onRunning(WorkerRunningEvent $event): void
    {
        $this->record($event->getWorker()->getMetadata()->getTransportNames());
    }

    #[AsEventListener(event: WorkerMessageReceivedEvent::class)]
    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->record([$event->getReceiverName()]);
    }

    /**
     * Typed as loosely as the source actually is: Messenger's WorkerMetadata
     * declares `getTransportNames(): array` with no value type, so narrowing
     * happens here rather than by asserting a shape the vendor never promised.
     *
     * @param array<mixed> $transports
     */
    private function record(array $transports): void
    {
        foreach ($transports as $transport) {
            if (is_string($transport) && '' !== $transport) {
                $this->heartbeat->record($transport);
            }
        }
    }
}
