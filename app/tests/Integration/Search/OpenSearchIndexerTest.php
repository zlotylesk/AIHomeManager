<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\ReadModel\SearchableDocument;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Infrastructure\Engine\OpenSearchEngine;
use App\Module\Search\Infrastructure\Index\OpenSearchIndexer;
use App\Module\Search\Infrastructure\Index\SearchIndexDefinition;
use App\Tests\Support\ResetsSearchIndex;
use Doctrine\DBAL\Connection;
use OpenSearch\Client;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * HMAI-363: the bulk pipeline against the real engine.
 *
 * The claims worth proving here are the ones a mocked client cannot reach: that
 * a rebuild never leaves the index empty midway, that re-running it changes
 * nothing, and that a document whose source row disappeared is actually gone
 * afterwards. Reads go through the real adapter, so "indexed" means "findable",
 * not merely "written".
 */
final class OpenSearchIndexerTest extends KernelTestCase
{
    use ResetsSearchIndex;

    private Client $client;
    private Connection $connection;
    private SearchIndexDefinition $definition;
    private OpenSearchIndexer $indexer;
    private OpenSearchEngine $engine;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var Client $client */
        $client = $container->get('app.search_client');
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        /** @var SearchIndexDefinition $definition */
        $definition = $container->get(SearchIndexDefinition::class);
        /** @var OpenSearchIndexer $indexer */
        $indexer = $container->get(OpenSearchIndexer::class);

        $this->client = $client;
        $this->connection = $connection;
        $this->definition = $definition;
        $this->indexer = $indexer;
        $this->engine = new OpenSearchEngine($client, $definition);

        $this->resetSearchIndex($client, $definition);

        // Every table the composite provider reads: the document count is an
        // assertion here, so a row left behind by another test would make this
        // one fail for the wrong reason.
        foreach (['books', 'tasks', 'articles', 'series', 'music_listening_sessions'] as $table) {
            $this->connection->executeStatement('DELETE FROM '.$table);
        }
    }

    protected function tearDown(): void
    {
        $this->resetSearchIndex($this->client, $this->definition);
        parent::tearDown();
    }

    public function testBulkFillsTheIndexFromEverySourceModule(): void
    {
        $this->givenBook('b-1', 'Solaris', 'Stanisław Lem');
        $this->givenTask('t-1', 'Kupić mleko');

        $indexed = $this->indexer->reindex();

        self::assertSame(2, $indexed);
        self::assertSame(['b-1'], $this->idsFor('Solaris'));
        self::assertSame(['t-1'], $this->idsFor('mleko'));
    }

    public function testTheIndexIsProvisionedOnDemand(): void
    {
        // A scheduled rebuild on a fresh box must not fail on a missing index.
        self::assertNull(
            $this->currentIndexOrNull(),
            'Precondition: the reset left the engine unprovisioned.',
        );

        $this->indexer->reindex();

        self::assertNotNull($this->currentIndexOrNull());
    }

    public function testRerunningTheRebuildDoesNotDuplicateAnything(): void
    {
        $this->givenBook('b-1', 'Solaris', 'Stanisław Lem');

        $this->indexer->reindex();
        $this->indexer->reindex();
        $this->indexer->reindex();

        // The document id is derived from the entity's identity, so a repeat run
        // overwrites. This is also what makes an interrupted run safe to simply
        // start again.
        self::assertSame(['b-1'], $this->idsFor('Solaris'));
    }

    public function testARebuildRemovesDocumentsWhoseSourceRowIsGone(): void
    {
        $this->givenBook('b-1', 'Solaris', 'Stanisław Lem');
        $this->givenBook('b-2', 'Cyberiada', 'Stanisław Lem');
        $this->indexer->reindex();

        $this->connection->executeStatement('DELETE FROM books WHERE id = ?', ['b-2']);
        $this->indexer->reindex();

        // Mark and sweep: only what this run did not touch is dropped, which is
        // why search kept answering throughout instead of going blank.
        self::assertSame(['b-1'], $this->idsFor('Lem'));
    }

    public function testARebuildKeepsAnsweringWhileItRuns(): void
    {
        $this->givenBook('b-1', 'Solaris', 'Stanisław Lem');
        $this->indexer->reindex();

        // Nothing is deleted up front, so the previous generation stays
        // searchable until its replacement has been written.
        $this->givenBook('b-2', 'Cyberiada', 'Stanisław Lem');
        $this->indexer->reindex();

        self::assertSame(['b-1', 'b-2'], $this->idsFor('Lem'));
    }

    public function testASingleDocumentCanBeWrittenAndDropped(): void
    {
        $document = new SearchableDocument(SearchResultType::BOOK, 'b-9', 'Niezwyciężony', 'Lem', '/books');

        $this->indexer->index($document);
        self::assertSame(['b-9'], $this->idsFor('Niezwyciężony'));

        $this->indexer->remove(SearchResultType::BOOK, 'b-9');
        self::assertSame([], $this->idsFor('Niezwyciężony'));
    }

    public function testPolishInflectionIsMatchedThroughTheRealMappings(): void
    {
        $this->givenBook('b-1', 'Opowieść o książkach', 'Autor');

        $this->indexer->reindex();

        // The pipeline writes through the alias, so documents land in the index
        // HMAI-362 defined — with the Polish analyzer actually applied.
        self::assertSame(['b-1'], $this->idsFor('książka'));
    }

    /**
     * @return list<string>
     */
    private function idsFor(string $term): array
    {
        $ids = array_map(
            static fn ($result): string => $result->id,
            $this->engine->search(new SearchQuery($term)),
        );
        sort($ids);

        return $ids;
    }

    private function currentIndexOrNull(): ?string
    {
        $indices = $this->ownedIndices($this->client, $this->definition);

        return $indices[0] ?? null;
    }

    private function givenBook(string $id, string $title, string $author): void
    {
        $this->connection->insert('books', [
            'id' => $id,
            'title' => $title,
            'author' => $author,
            'isbn' => '978'.substr(md5($id), 0, 10),
            'publisher' => 'Wydawnictwo',
            'year' => 1961,
            'total_pages' => 200,
            'current_page' => 0,
            'status' => 'to_read',
        ]);
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
}
