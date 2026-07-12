<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Goals\Application\Command\RecalculateStreaks;
use App\Module\Search\Application\Command\ReindexSearchDocuments;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Pins ReindexSearchDocuments as a synchronous command. The scheduler fires it
 * every 15 minutes (Schedule.php) and the reindex must run inline in the
 * scheduler worker — it must NOT be routed to the async transport. This mirrors
 * the sync-by-design guardrail BookCompletedRoutingTest gives BookCompleted
 * (ADR-006): a regression routing the reindex to async would silently move the
 * rebuild off the scheduler worker with nothing else failing.
 */
final class ReindexSearchDocumentsRoutingTest extends KernelTestCase
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

    public function testReindexSearchDocumentsIsNotRoutedToAsyncTransport(): void
    {
        $this->commandBus->dispatch(new ReindexSearchDocuments());

        $filtered = array_filter(
            $this->asyncTransport->getSent(),
            static fn ($envelope) => $envelope->getMessage() instanceof ReindexSearchDocuments,
        );

        self::assertCount(0, $filtered, 'ReindexSearchDocuments must stay sync — the 15-min reindex runs inline in the scheduler worker. Do not add async routing without updating Schedule.php intent and this test.');
    }

    public function testRecalculateStreaksIsRoutedToAsyncTransport(): void
    {
        $this->commandBus->dispatch(new RecalculateStreaks());

        $filtered = array_filter(
            $this->asyncTransport->getSent(),
            static fn ($envelope) => $envelope->getMessage() instanceof RecalculateStreaks,
        );

        self::assertCount(1, $filtered, 'RecalculateStreaks must be routed to async — counter-proof that the transport inspection detects async routing.');
    }
}
