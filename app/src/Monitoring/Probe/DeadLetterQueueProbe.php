<?php

declare(strict_types=1);

namespace App\Monitoring\Probe;

use App\Monitoring\Alert;
use App\Monitoring\AlertProbeInterface;
use App\Monitoring\AlertSeverity;
use DateTimeImmutable;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Watches how much has piled up in the dead-letter queue.
 *
 * A message lands there after Messenger has retried it three times, so every
 * one of them is an operation that was asked for and never happened — an import
 * that did not import, a notification nobody received. Until now that depth was
 * visible only to somebody who thought to run `messenger:stats`, which is a
 * thing one runs *after* already suspecting a problem.
 *
 * Reported as a warning rather than critical: the instance keeps serving every
 * request, and the fix is draining the queue with `messenger:failed:retry`, not
 * an emergency. Whatever put the messages there usually has its own component
 * in the health probe and will say so at its own severity.
 */
final readonly class DeadLetterQueueProbe implements AlertProbeInterface
{
    /**
     * @param int $threshold depth at which the queue is worth mentioning; 1 means
     *                       "anything at all", which is the honest default when a
     *                       single stuck message is a lost operation
     */
    public function __construct(
        private TransportInterface $transport,
        private int $threshold,
    ) {
    }

    public function name(): string
    {
        return 'queue';
    }

    public function probe(DateTimeImmutable $at): array
    {
        // Not every transport can be counted — the in-memory one the test suite
        // binds cannot. Silence is right here: an uncountable transport is a
        // configuration shape, not a failure, and treating it as one would make
        // the whole suite alert on itself.
        if (!$this->transport instanceof MessageCountAwareInterface) {
            return [];
        }

        $depth = $this->transport->getMessageCount();

        if ($depth < $this->threshold) {
            return [];
        }

        return [new Alert(
            key: 'failed',
            severity: AlertSeverity::WARNING,
            title: sprintf('%d message(s) waiting in the dead-letter queue', $depth),
            detail: sprintf(
                "The threshold is %d. Each message there was retried three times and then given up on, so that much work silently did not happen.\n\nInspect with `make shell` then `bin/console messenger:stats`, and drain with `bin/console messenger:failed:retry`. Note that `messenger:failed:show` does not work against AMQP — it needs a receiver that can list by id, which only the Doctrine transport offers.",
                $this->threshold,
            ),
        )];
    }
}
