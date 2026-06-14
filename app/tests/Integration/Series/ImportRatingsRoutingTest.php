<?php

declare(strict_types=1);

namespace App\Tests\Integration\Series;

use App\Module\Series\Application\Command\ImportRatingsFromTrakt;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

class ImportRatingsRoutingTest extends KernelTestCase
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

    public function testRatingsImportCommandIsRoutedToAsyncTransport(): void
    {
        $this->commandBus->dispatch(new ImportRatingsFromTrakt());

        $filtered = array_filter(
            $this->asyncTransport->getSent(),
            static fn ($envelope) => $envelope->getMessage() instanceof ImportRatingsFromTrakt,
        );

        self::assertCount(1, $filtered, 'ImportRatingsFromTrakt must be routed to async — the import is rate-limited + I/O bound and must never run inline in a request.');
    }
}
