<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Application\Command\IndexSearchDocument;
use App\Module\Search\Application\CommandHandler\IndexSearchDocumentHandler;
use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Domain\Port\SearchIndexerInterface;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Tasks\Domain\Event\TaskCreated;
use App\Module\Tasks\Domain\Event\TaskDeleted;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * HMAI-363: the incremental rail end to end on the default backend.
 *
 * The default is FULLTEXT, so this is the configuration the feature actually
 * runs in — testing only the OpenSearch path would leave the shipped behaviour
 * unproven. The engine is read through its port afterwards, because "the row is
 * in the table" is not the claim worth making; "searching for it finds it" is.
 */
final class IncrementalIndexingTest extends KernelTestCase
{
    private Connection $connection;
    private SearchIndexerInterface $indexer;
    private SearchEngineInterface $engine;
    private MessageBusInterface $eventBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        /** @var SearchIndexerInterface $indexer */
        $indexer = $container->get(SearchIndexerInterface::class);
        /** @var SearchEngineInterface $engine */
        $engine = $container->get(SearchEngineInterface::class);
        /** @var MessageBusInterface $eventBus */
        $eventBus = $container->get('event.bus');

        $this->connection = $connection;
        $this->indexer = $indexer;
        $this->engine = $engine;
        $this->eventBus = $eventBus;

        $this->connection->executeStatement('DELETE FROM search_documents');
        $this->connection->executeStatement('DELETE FROM tasks');
    }

    public function testAnEventPutsTheNewEntityIntoTheIndex(): void
    {
        $this->givenTask('t-1', 'Kupić mleko');

        $this->handle(new IndexSearchDocument('task', 't-1'));

        $results = $this->engine->search(new SearchQuery('mleko'));
        self::assertCount(1, $results);
        self::assertSame('t-1', $results[0]->id);
        self::assertSame(SearchResultType::TASK, $results[0]->type);
    }

    public function testReplayingTheSameMessageDoesNotDuplicateTheDocument(): void
    {
        $this->givenTask('t-1', 'Kupić mleko');

        $this->handle(new IndexSearchDocument('task', 't-1'));
        $this->handle(new IndexSearchDocument('task', 't-1'));
        $this->handle(new IndexSearchDocument('task', 't-1'));

        // A queue redelivers; the document id is the entity's identity, so a
        // replay overwrites rather than accumulates.
        self::assertCount(1, $this->engine->search(new SearchQuery('mleko')));
    }

    public function testAnUpdateReplacesTheIndexedContentRatherThanAddingToIt(): void
    {
        $this->givenTask('t-1', 'Kupić mleko');
        $this->handle(new IndexSearchDocument('task', 't-1'));

        $this->connection->executeStatement('UPDATE tasks SET title = ? WHERE id = ?', ['Kupić chleb', 't-1']);
        $this->handle(new IndexSearchDocument('task', 't-1'));

        self::assertSame([], $this->engine->search(new SearchQuery('mleko')), 'The stale title must stop matching.');
        self::assertCount(1, $this->engine->search(new SearchQuery('chleb')));
    }

    public function testAVanishedEntityIsRemovedFromTheIndex(): void
    {
        $this->givenTask('t-1', 'Kupić mleko');
        $this->handle(new IndexSearchDocument('task', 't-1'));

        $this->connection->executeStatement('DELETE FROM tasks WHERE id = ?', ['t-1']);
        $this->handle(new IndexSearchDocument('task', 't-1'));

        // Deletion needs no dedicated message: the source no longer has it, so
        // the document goes. That also self-corrects a delete that happened
        // while the message was still queued.
        self::assertSame([], $this->engine->search(new SearchQuery('mleko')));
    }

    public function testRemovingSomethingAlreadyGoneIsNotAFailure(): void
    {
        $this->indexer->remove(SearchResultType::TASK, 'never-indexed');

        self::assertSame([], $this->engine->search(new SearchQuery('never')));
    }

    public function testAnUnknownDocumentTypeIsIgnoredRatherThanFailingTheWorker(): void
    {
        // A source module naming a type this index does not carry must not park
        // a message in the DLQ forever.
        $this->handle(new IndexSearchDocument('podcast', 'p-1'));

        self::assertSame([], $this->engine->search(new SearchQuery('p-1')));
    }

    public function testATaskEventOnTheEventBusRequestsIndexing(): void
    {
        $this->givenTask('t-9', 'Zadzwonić do dentysty');

        // The real rail: a Tasks domain event on the event bus, resolved to the
        // Search handler through the shared-kernel interface alone.
        $this->eventBus->dispatch(new TaskCreated(
            't-9',
            new TaskTitle('Zadzwonić do dentysty'),
            new TimeSlot(new DateTimeImmutable('2026-07-27 09:00'), new DateTimeImmutable('2026-07-27 10:00')),
        ));

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');

        // Filtered to this module's command on purpose. `TaskCreated` is opted
        // into two shared-kernel rails — search indexing and the Notifications
        // announcement — and which of them fire depends on the task's own data
        // (a slot starting today is also a `task_due` occurrence). Counting
        // every message on the transport made this assertion depend on the
        // calendar: the fixture's slot date silently became "today" and a
        // passing test turned red without a line of production code changing.
        $indexing = array_values(array_filter(
            array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()),
            static fn ($message) => $message instanceof IndexSearchDocument,
        ));

        // Exactly one is still the claim worth making — a second would mean the
        // same change being indexed twice.
        self::assertCount(1, $indexing, 'The event must produce exactly one indexing command.');
        self::assertSame('task', $indexing[0]->type);
        self::assertSame('t-9', $indexing[0]->id);
    }

    public function testDeletingATaskUsesTheSameDocumentIdentityAsCreatingIt(): void
    {
        $created = new TaskCreated(
            't-9',
            new TaskTitle('Zadzwonić'),
            new TimeSlot(new DateTimeImmutable('2026-07-27 09:00'), new DateTimeImmutable('2026-07-27 10:00')),
        );
        $deleted = new TaskDeleted('t-9', null);

        // The two events have to name the same document, or a deleted task would
        // leave its old document behind under a different identity.
        self::assertEquals($created->toSearchDocumentRef(), $deleted->toSearchDocumentRef());
    }

    private function givenTask(string $id, string $title): void
    {
        $this->connection->insert('tasks', [
            'id' => $id,
            'title' => $title,
            'status' => 'pending',
            'time_start' => '2026-07-27 09:00:00',
            'time_end' => '2026-07-27 10:00:00',
        ]);
    }

    private function handle(IndexSearchDocument $command): void
    {
        // The command is routed async, so dispatching it here would only park it
        // in the in-memory transport (that routing is pinned separately). The
        // handler is what this test is about, so invoke it the way the worker
        // would.
        /** @var IndexSearchDocumentHandler $handler */
        $handler = static::getContainer()->get(IndexSearchDocumentHandler::class);
        $handler($command);
    }
}
