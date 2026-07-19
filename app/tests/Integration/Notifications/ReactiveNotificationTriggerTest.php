<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notifications;

use App\Module\Notifications\Application\Command\DispatchNotification;
use App\Module\Tasks\Domain\Event\TaskCreated;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Proves the reactive rail end to end through the real buses: a source module's
 * domain event reaches Notifications purely through the shared-kernel interface,
 * and comes out as a DispatchNotification for the engine.
 */
final class ReactiveNotificationTriggerTest extends KernelTestCase
{
    private MessageBusInterface $eventBus;
    private InMemoryTransport $asyncTransport;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->eventBus = $container->get('event.bus');

        $transport = $container->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $this->asyncTransport = $transport;
    }

    public function testATaskScheduledForTodayIsAnnounced(): void
    {
        $this->eventBus->dispatch(new TaskCreated('42', new TaskTitle('Zapłacić czynsz'), $this->slotAt('today 18:00')));

        $dispatched = $this->dispatchedNotifications();

        self::assertCount(1, $dispatched);
        $command = $dispatched[0]->getMessage();
        \assert($command instanceof DispatchNotification);
        self::assertSame('task_due', $command->type);
        self::assertSame('task-42', $command->subject);
        self::assertSame('Zapłacić czynsz', $command->payload['title']);
    }

    public function testAnEventWorthNoAnnouncementDispatchesNothing(): void
    {
        $this->eventBus->dispatch(new TaskCreated('42', new TaskTitle('Zapłacić czynsz'), $this->slotAt('+7 days 18:00')));

        self::assertSame([], $this->dispatchedNotifications());
    }

    /**
     * The same occurrence reported twice yields two commands, but both carry the
     * same subject+window — so the engine's dedup key collapses them into one
     * announcement, which is what keeps this rail consistent with the scheduler.
     */
    public function testARepeatedOccurrenceKeepsTheSameDedupIdentity(): void
    {
        $event = new TaskCreated('42', new TaskTitle('Zapłacić czynsz'), $this->slotAt('today 18:00'));

        $this->eventBus->dispatch($event);
        $this->eventBus->dispatch($event);

        $dispatched = $this->dispatchedNotifications();
        self::assertCount(2, $dispatched);

        $first = $dispatched[0]->getMessage();
        $second = $dispatched[1]->getMessage();
        \assert($first instanceof DispatchNotification && $second instanceof DispatchNotification);
        self::assertSame($first->subject, $second->subject);
        self::assertSame($first->window, $second->window);
    }

    /**
     * @return list<Envelope>
     */
    private function dispatchedNotifications(): array
    {
        return array_values(array_filter(
            $this->asyncTransport->getSent(),
            static fn (Envelope $envelope) => $envelope->getMessage() instanceof DispatchNotification,
        ));
    }

    private function slotAt(string $start): TimeSlot
    {
        $startsAt = new DateTimeImmutable($start);

        return new TimeSlot($startsAt, $startsAt->modify('+1 hour'));
    }
}
