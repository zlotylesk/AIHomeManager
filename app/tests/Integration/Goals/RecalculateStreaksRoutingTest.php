<?php

declare(strict_types=1);

namespace App\Tests\Integration\Goals;

use App\Module\Goals\Application\Command\RecalculateStreaks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RecalculateStreaksRoutingTest extends KernelTestCase
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

    public function testRecalculateStreaksIsRoutedToAsyncTransport(): void
    {
        $this->commandBus->dispatch(new RecalculateStreaks());

        $filtered = array_filter(
            $this->asyncTransport->getSent(),
            static fn ($envelope) => $envelope->getMessage() instanceof RecalculateStreaks,
        );

        self::assertCount(1, $filtered, 'RecalculateStreaks must be routed to async — the nightly recompute is I/O bound and must never run inline.');
    }
}
