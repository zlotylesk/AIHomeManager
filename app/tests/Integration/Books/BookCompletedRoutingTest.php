<?php

declare(strict_types=1);

namespace App\Tests\Integration\Books;

use App\Module\Books\Domain\Event\BookCompleted;
use App\Module\Series\Domain\Event\EpisodeRated;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

class BookCompletedRoutingTest extends KernelTestCase
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

    public function testBookCompletedIsNotRoutedToAsyncTransport(): void
    {
        $this->eventBus->dispatch(new BookCompleted('book-routing-test'));

        $sent = $this->asyncTransport->getSent();

        $filtered = array_filter(
            $sent,
            static fn ($envelope) => $envelope->getMessage() instanceof BookCompleted,
        );

        self::assertCount(0, $filtered, 'BookCompleted must stay sync (ADR-006). Do not add async routing without updating the ADR and this test.');
    }

    public function testEpisodeRatedIsRoutedToAsyncTransport(): void
    {
        $this->eventBus->dispatch(new EpisodeRated('s-1', 'se-1', 'ep-1', 8));

        $sent = $this->asyncTransport->getSent();

        $filtered = array_filter(
            $sent,
            static fn ($envelope) => $envelope->getMessage() instanceof EpisodeRated,
        );

        self::assertCount(1, $filtered, 'EpisodeRated must be routed to async transport — counter-proof for the test mechanism.');
    }
}
