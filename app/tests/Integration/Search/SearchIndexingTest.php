<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Domain\Port\SearchIndexerInterface;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exercises the full indexing chain through the container-wired ports: the
 * composite provider → indexer → search_documents → FULLTEXT engine.
 */
final class SearchIndexingTest extends KernelTestCase
{
    private Connection $connection;
    private SearchIndexerInterface $indexer;
    private SearchEngineInterface $engine;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->connection = $container->get(EntityManagerInterface::class)->getConnection();
        $this->indexer = $container->get(SearchIndexerInterface::class);
        $this->engine = $container->get(SearchEngineInterface::class);

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['books', 'articles', 'series', 'tasks', 'music_listening_sessions', 'search_documents'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testReindexPopulatesTheIndexAndMakesSourceDataSearchable(): void
    {
        $this->connection->insert('books', [
            'id' => 'book-1', 'isbn' => '9780000000001', 'title' => 'Dune', 'author' => 'Frank Herbert',
            'publisher' => 'Ace', 'year' => 1965, 'current_page' => 0, 'total_pages' => 412, 'status' => 'reading',
        ]);
        $this->connection->insert('series', [
            'id' => 'series-1', 'title' => 'Severance', 'created_at' => '2026-07-01 00:00:00', 'description' => 'office thriller',
        ]);

        $count = $this->indexer->reindex();

        self::assertSame(2, $count);

        $results = $this->engine->search(new SearchQuery('dune'));
        self::assertCount(1, $results);
        self::assertSame('book-1', $results[0]->id);
    }

    public function testReindexIsIdempotent(): void
    {
        $this->connection->insert('books', [
            'id' => 'book-1', 'isbn' => '9780000000001', 'title' => 'Dune', 'author' => 'Frank Herbert',
            'publisher' => 'Ace', 'year' => 1965, 'current_page' => 0, 'total_pages' => 412, 'status' => 'reading',
        ]);

        $this->indexer->reindex();
        $this->indexer->reindex();

        $indexed = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM search_documents');
        self::assertSame(1, $indexed);
    }

    public function testReindexClearsTheSearchResultCache(): void
    {
        $cache = static::getContainer()->get('cache.search');

        $item = $cache->getItem('search_probe');
        $item->set([]);
        $cache->save($item);
        self::assertTrue($cache->getItem('search_probe')->isHit());

        $this->indexer->reindex();

        self::assertFalse($cache->getItem('search_probe')->isHit(), 'Reindex must clear the search result cache.');
    }
}
