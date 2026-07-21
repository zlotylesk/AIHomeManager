<?php

declare(strict_types=1);

namespace App\Tests\Integration\Podcasts;

use App\Module\Podcasts\Application\Command\PollPodcastListens;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PollPodcastListensRoutingTest extends KernelTestCase
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

    public function testPollPodcastListensIsRoutedToAsyncTransport(): void
    {
        $this->commandBus->dispatch(new PollPodcastListens());

        $filtered = array_filter(
            $this->asyncTransport->getSent(),
            static fn ($envelope) => $envelope->getMessage() instanceof PollPodcastListens,
        );

        self::assertCount(1, $filtered, 'PollPodcastListens must be routed to async — the sweep is 1 + N external calls and must never run inline.');
    }
}
