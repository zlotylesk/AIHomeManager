<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Application\Command\IndexSearchDocument;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * HMAI-363: pins that incremental indexing is offloaded to the queue.
 *
 * The routing is the whole reason indexing cannot break a user's write: if this
 * message ever ran inline, a slow engine would slow every task edit, and a
 * failed index would surface as an error on a request that actually succeeded.
 * Config is easy to lose in a merge, so it is asserted rather than assumed.
 */
final class IndexSearchDocumentRoutingTest extends KernelTestCase
{
    public function testIndexingIsHandledOnTheAsyncTransport(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var MessageBusInterface $bus */
        $bus = $container->get('command.bus');
        $envelope = $bus->dispatch(new IndexSearchDocument('task', 't-1'));

        $sent = $envelope->last(SentStamp::class);
        self::assertNotNull($sent, 'The command must be sent to a transport, not handled inline.');
        self::assertSame('async', $sent->getSenderAlias());

        /** @var InMemoryTransport $transport */
        $transport = $container->get('messenger.transport.async');
        self::assertCount(1, $transport->getSent());
    }
}
