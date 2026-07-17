<?php

declare(strict_types=1);

namespace App\Tests\Integration\Movies;

use App\Module\Movies\Application\Command\ImportMovieRatingsFromTrakt;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

class ImportMovieRatingsRoutingTest extends KernelTestCase
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

    public function testImportCommandIsRoutedToAsyncTransport(): void
    {
        $this->commandBus->dispatch(new ImportMovieRatingsFromTrakt());

        $filtered = array_filter(
            $this->asyncTransport->getSent(),
            static fn ($envelope) => $envelope->getMessage() instanceof ImportMovieRatingsFromTrakt,
        );

        self::assertCount(1, $filtered, 'ImportMovieRatingsFromTrakt must be routed to async — the import is rate-limited + I/O bound and must never run inline in a request.');
    }
}
