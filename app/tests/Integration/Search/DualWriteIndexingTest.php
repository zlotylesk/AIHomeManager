<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchableProviderInterface;
use App\Module\Search\Domain\ReadModel\SearchableDocument;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Infrastructure\Engine\FulltextSearchEngine;
use App\Module\Search\Infrastructure\Engine\OpenSearchEngine;
use App\Module\Search\Infrastructure\Index\DualWriteSearchIndexer;
use App\Module\Search\Infrastructure\Index\OpenSearchIndexer;
use App\Module\Search\Infrastructure\Index\SearchIndexDefinition;
use App\Module\Search\Infrastructure\Index\SearchIndexer;
use App\Tests\Support\ResetsSearchIndex;
use App\Tests\Support\SpyLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use OpenSearch\Client;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The dual write against both real indexes (epic HMAI-359 review).
 *
 * The rollback promise the runbook makes to an operator — "switch the flag back
 * and FULLTEXT answers immediately, nothing has to be rebuilt" — rests entirely
 * on the MySQL index still being maintained while OpenSearch is the backend
 * being read. Until now that rested on unit doubles, which cannot catch the two
 * ways it would actually fail: a real writer that no longer reaches its store,
 * or two writers that disagree about what a document is.
 *
 * So this test asserts through the **engines**, not the tables: the claim worth
 * making is not "a row exists" but "searching the standby finds it", because
 * that is what a rollback would depend on at the moment it is needed.
 */
final class DualWriteIndexingTest extends KernelTestCase
{
    use ResetsSearchIndex;

    private Connection $connection;
    private Client $search;
    private SearchIndexDefinition $definition;
    private DualWriteSearchIndexer $indexer;
    private OpenSearchEngine $primaryEngine;
    private FulltextSearchEngine $standbyEngine;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var Connection $connection */
        $connection = $container->get(EntityManagerInterface::class)->getConnection();
        /** @var Client $search */
        $search = $container->get('app.search_client');
        /** @var SearchIndexDefinition $definition */
        $definition = $container->get(SearchIndexDefinition::class);
        /** @var OpenSearchIndexer $openSearchIndexer */
        $openSearchIndexer = $container->get(OpenSearchIndexer::class);
        /** @var SearchIndexer $fulltextIndexer */
        $fulltextIndexer = $container->get(SearchIndexer::class);

        $this->connection = $connection;
        $this->search = $search;
        $this->definition = $definition;
        $this->primaryEngine = new OpenSearchEngine($search, $definition);
        $this->standbyEngine = new FulltextSearchEngine($connection);

        // Assembled exactly the way SearchIndexerFactory assembles it when the
        // flag selects OpenSearch — real writers on both sides, no doubles.
        $this->indexer = new DualWriteSearchIndexer($openSearchIndexer, $fulltextIndexer, new SpyLogger());

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['books', 'articles', 'series', 'tasks', 'music_listening_sessions', 'search_documents'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $this->resetSearchIndex($search, $definition);
    }

    protected function tearDown(): void
    {
        $this->resetSearchIndex($this->search, $this->definition);
        parent::tearDown();
    }

    public function testARebuildMakesTheDocumentSearchableInBothIndexes(): void
    {
        $this->givenBook('book-1', 'Diuna', 'Frank Herbert');

        self::assertSame(1, $this->indexer->reindex());

        // The index being read…
        self::assertSame('book-1', $this->primaryEngine->search(new SearchQuery('diuna'))[0]->id);
        // …and the one a rollback would fall back on, which is the whole point.
        self::assertSame('book-1', $this->standbyEngine->search(new SearchQuery('diuna'))[0]->id);
    }

    public function testASingleDocumentWriteReachesBothIndexes(): void
    {
        $this->givenBook('book-2', 'Kometa', 'obserwacje nieba');

        // The incremental path: one document, not a rebuild.
        $this->indexer->index($this->documentFor('book-2'));

        self::assertCount(1, $this->primaryEngine->search(new SearchQuery('kometa')));
        self::assertCount(1, $this->standbyEngine->search(new SearchQuery('kometa')));
    }

    public function testADeletionReachesBothIndexes(): void
    {
        $this->givenBook('book-3', 'Solaris', 'Stanisław Lem');
        $this->indexer->reindex();

        $this->indexer->remove(SearchResultType::BOOK, 'book-3');

        // A deletion missed by the standby is the worst drift of the three: a
        // rollback would resurrect an entity the user deleted.
        self::assertSame([], $this->primaryEngine->search(new SearchQuery('solaris')));
        self::assertSame([], $this->standbyEngine->search(new SearchQuery('solaris')));
    }

    public function testBothIndexesAgreeOnTheDocumentTheyStore(): void
    {
        $this->givenBook('book-4', 'Wydma', 'pustynny przewodnik');

        $this->indexer->reindex();

        $primary = $this->primaryEngine->search(new SearchQuery('wydma'))[0];
        $standby = $this->standbyEngine->search(new SearchQuery('wydma'))[0];

        // Not just "both found something" — the same entity, under the same
        // identity, rendered the same way. A rollback that changed the link or
        // the label would be visible to the user.
        self::assertTrue($primary->equals($standby));
    }

    private function givenBook(string $id, string $title, string $author): void
    {
        $this->connection->insert('books', [
            'id' => $id, 'isbn' => substr('978'.abs(crc32($id)).'0000000000', 0, 13), 'title' => $title,
            'author' => $author, 'publisher' => 'Test', 'year' => 2026,
            'current_page' => 0, 'total_pages' => 100, 'status' => 'reading',
        ]);
    }

    /**
     * Pulled through the real provider rather than hand-built, so the
     * incremental path indexes exactly the document the rebuild would.
     */
    private function documentFor(string $id): SearchableDocument
    {
        /** @var SearchableProviderInterface $provider */
        $provider = static::getContainer()->get(SearchableProviderInterface::class);
        $document = $provider->documentFor(SearchResultType::BOOK, $id);
        self::assertNotNull($document);

        return $document;
    }
}
