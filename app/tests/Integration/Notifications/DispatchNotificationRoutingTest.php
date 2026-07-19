<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notifications;

use App\Module\Notifications\Application\Command\DispatchNotification;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class DispatchNotificationRoutingTest extends KernelTestCase
{
    private MessageBusInterface $commandBus;
    private InMemoryTransport $asyncTransport;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->commandBus = $container->get('command.bus');

        $transport = $container->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $this->asyncTransport = $transport;
    }

    public function testDispatchNotificationIsRoutedToAsyncTransport(): void
    {
        $this->commandBus->dispatch(new DispatchNotification('task_due', 'task-42', '2026-07-16'));

        $filtered = array_filter(
            $this->asyncTransport->getSent(),
            static fn (Envelope $envelope) => $envelope->getMessage() instanceof DispatchNotification,
        );

        self::assertCount(
            1,
            $filtered,
            'DispatchNotification must be routed to async — delivery is I/O bound (SMTP, WebPush) and must never block the triggering path.',
        );
    }
}
