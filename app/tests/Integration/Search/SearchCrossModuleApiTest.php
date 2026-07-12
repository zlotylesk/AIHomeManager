<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Domain\Port\SearchIndexerInterface;
use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HMAI-272 — cross-module search over the real indexing pipeline.
 *
 * Unlike {@see SearchApiTest} (which direct-seeds `search_documents` to pin the
 * HTTP contract), this test seeds the actual source-module tables, runs the
 * wired {@see SearchIndexerInterface} so the `CompositeSearchableProvider` builds
 * the index, and then queries `/api/search` over HTTP. It proves the whole chain
 * — five module adapters → indexer → FULLTEXT engine → controller — returns
 * ranked, paginated, type-filterable results spanning every module.
 */
final class SearchCrossModuleApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        $container = static::getContainer();
        $this->connection = $container->get(EntityManagerInterface::class)->getConnection();

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['books', 'articles', 'series', 'tasks', 'music_listening_sessions', 'search_documents'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $this->seedSourceModules();
        $container->get(SearchIndexerInterface::class)->reindex();
    }

    /**
     * One "space"-bearing row per module (so a single query spans all five), plus a
     * control book with no match. The winning book carries "space" three times
     * (title + author twice), the rest once — an unambiguous FULLTEXT ranking.
     */
    private function seedSourceModules(): void
    {
        $this->connection->insert('books', [
            'id' => 'bk-1', 'isbn' => '9780000000001', 'title' => 'Space Travel Guide',
            'author' => 'Space Agency deep space division', 'publisher' => 'Ace', 'year' => 2001,
            'current_page' => 0, 'total_pages' => 300, 'status' => 'reading',
        ]);
        $this->connection->insert('books', [
            'id' => 'bk-2', 'isbn' => '9780000000002', 'title' => 'Cooking Basics',
            'author' => 'Jane Doe', 'publisher' => 'Ace', 'year' => 2010,
            'current_page' => 0, 'total_pages' => 200, 'status' => 'reading',
        ]);
        $this->connection->insert('series', [
            'id' => 'sr-1', 'title' => 'Deep Space Nine', 'created_at' => '2026-07-01 00:00:00',
            'description' => 'orbital station drama',
        ]);
        $this->connection->insert('articles', [
            'id' => 'ar-1', 'title' => 'Space Law Primer', 'url' => 'https://example.test/space-law',
            'added_at' => '2026-07-01 00:00:00', 'is_read' => 0, 'category' => 'legal notes',
        ]);
        $this->connection->insert('tasks', [
            'id' => 'tk-1', 'title' => 'Space mission prep', 'time_start' => '2026-07-01 08:00:00',
            'time_end' => '2026-07-01 09:00:00', 'status' => 'pending',
        ]);
        $this->connection->insert('music_listening_sessions', [
            'id' => 'mu-1', 'artist' => 'Space Band', 'title' => 'Rocket Anthology',
            'played_at' => '2026-07-01 10:00:00', 'source' => 'lastfm', 'dedup_hash' => 'h1',
            'created_at' => '2026-07-01 10:00:00',
        ]);
    }

    public function testQuerySpansEveryModule(): void
    {
        $this->client->request('GET', '/api/search?q=space&perPage=20');
        self::assertResponseIsSuccessful();

        $results = $this->jsonResponse($this->client);
        self::assertCount(5, $results, 'One matching document per module (the control book is excluded).');

        $types = array_map(static fn (array $r): string => $r['type'], $results);
        sort($types);
        self::assertSame(['article', 'book', 'music', 'series', 'task'], $types);
    }

    public function testResultsAreRelevanceRanked(): void
    {
        $this->client->request('GET', '/api/search?q=space&perPage=20');
        self::assertResponseIsSuccessful();

        $results = $this->jsonResponse($this->client);
        self::assertSame('bk-1', $results[0]['id'], 'The document with the densest "space" match ranks first.');
        self::assertSame('book', $results[0]['type']);
    }

    public function testTypeFilterNarrowsToOneModule(): void
    {
        $this->client->request('GET', '/api/search?q=space&type=book');
        self::assertResponseIsSuccessful();

        $results = $this->jsonResponse($this->client);
        self::assertCount(1, $results, 'Only the winning book matches "space"; the control book does not.');
        self::assertSame('bk-1', $results[0]['id']);
        self::assertSame('book', $results[0]['type']);
    }

    public function testPaginationSlicesTheRankedSet(): void
    {
        $this->client->request('GET', '/api/search?q=space&perPage=2&page=1');
        $page1 = $this->jsonResponse($this->client);
        self::assertCount(2, $page1);
        self::assertSame('bk-1', $page1[0]['id']);

        $this->client->request('GET', '/api/search?q=space&perPage=2&page=3');
        $page3 = $this->jsonResponse($this->client);
        self::assertCount(1, $page3, 'Five results across three pages of two leaves one on the last page.');

        $ids1 = array_map(static fn (array $r): string => $r['id'], $page1);
        self::assertNotContains($page3[0]['id'], $ids1, 'Pages must not overlap.');
    }

    public function testNoMatchReturnsEmptyArray(): void
    {
        $this->client->request('GET', '/api/search?q=nonexistentqwerty');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonResponse($this->client));
    }
}
