<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Infrastructure\Engine\OpenSearchEngine;
use OpenSearch\Client;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * HMAI-361: the OpenSearch adapter against the real engine (the docker-compose
 * `search` service locally, the CI `tests` job service container).
 *
 * The scenarios deliberately mirror {@see SearchEngineTest} document for
 * document and assertion for assertion — the epic's whole premise is that the
 * two backends are interchangeable behind the port, and only running the same
 * expectations through both proves it.
 *
 * The documents are indexed here rather than by the real pipeline because the
 * pipeline is HMAI-363's scope; the index is created without explicit mappings
 * for the same reason (HMAI-362).
 */
final class OpenSearchEngineTest extends KernelTestCase
{
    private Client $client;
    private OpenSearchEngine $engine;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Client $client */
        $client = static::getContainer()->get('app.search_client');
        $this->client = $client;
        $this->engine = new OpenSearchEngine($this->client);

        $this->dropIndex();
        $this->client->indices()->create(['index' => OpenSearchEngine::INDEX]);

        $this->seed(SearchResultType::BOOK, 'b1', 'Dune', 'Frank Herbert desert planet', '/books');
        $this->seed(SearchResultType::BOOK, 'b2', 'Space Odyssey', 'a space voyage through space', '/books');
        $this->seed(SearchResultType::SERIES, 's1', 'Deep Space Nine', 'orbital station drama', '/series');
        $this->seed(SearchResultType::ARTICLE, 'a1', 'Cooking Pasta', 'italian cuisine tips', '/articles');
        $this->seed(SearchResultType::TASK, 't1', 'Buy groceries', '', '/tasks');
        $this->seed(SearchResultType::MUSIC, 'm1', 'Jazz Session', 'live studio recording', '/music');

        // Make the writes searchable before the assertions run — indexing is
        // near-real-time, not synchronous.
        $this->client->indices()->refresh(['index' => OpenSearchEngine::INDEX]);
    }

    private function dropIndex(): void
    {
        try {
            $this->client->indices()->delete(['index' => OpenSearchEngine::INDEX]);
        } catch (Throwable) {
            // Absent on the first run — nothing to clean up.
        }
    }

    private function seed(SearchResultType $type, string $id, string $title, string $content, string $url): void
    {
        $this->client->index([
            'index' => OpenSearchEngine::INDEX,
            'id' => $type->value.':'.$id,
            'body' => [
                'type' => $type->value, 'source_id' => $id, 'title' => $title, 'content' => $content, 'url' => $url,
            ],
        ]);
    }

    public function testRanksMatchesByRelevance(): void
    {
        $results = $this->engine->search(new SearchQuery('space'));

        self::assertCount(2, $results);
        self::assertSame('b2', $results[0]->id, 'The document mentioning the term three times ranks first.');
        self::assertSame('s1', $results[1]->id);
    }

    public function testFiltersByType(): void
    {
        $results = $this->engine->search(new SearchQuery('space', SearchResultType::BOOK));

        self::assertCount(1, $results);
        self::assertSame(SearchResultType::BOOK, $results[0]->type);
        self::assertSame('b2', $results[0]->id);
    }

    public function testPaginatesRankedResults(): void
    {
        $page1 = $this->engine->search(new SearchQuery('space', null, 1, 1));
        $page2 = $this->engine->search(new SearchQuery('space', null, 2, 1));

        self::assertCount(1, $page1);
        self::assertCount(1, $page2);
        self::assertSame('b2', $page1[0]->id);
        self::assertSame('s1', $page2[0]->id);
    }

    public function testReturnsEmptyWhenNothingMatches(): void
    {
        self::assertSame([], $this->engine->search(new SearchQuery('nonexistentqwerty')));
    }

    public function testNormalizesHitToSearchResult(): void
    {
        $results = $this->engine->search(new SearchQuery('dune'));

        self::assertCount(1, $results);
        $result = $results[0];
        self::assertSame(SearchResultType::BOOK, $result->type);
        self::assertSame('b1', $result->id);
        self::assertSame('Dune', $result->title);
        self::assertStringContainsString('Frank Herbert', $result->snippet);
        self::assertSame('/books', $result->url);
    }

    public function testTruncatesTheSnippetLikeTheFulltextEngine(): void
    {
        $long = str_repeat('a', 200);
        $this->seed(SearchResultType::ARTICLE, 'a2', 'Longread', 'kaleidoscope '.$long, '/articles');
        $this->client->indices()->refresh(['index' => OpenSearchEngine::INDEX]);

        $results = $this->engine->search(new SearchQuery('kaleidoscope'));

        self::assertCount(1, $results);
        // Same 160-character cut as FULLTEXT, so a switch cannot change what the
        // UI renders under a hit.
        self::assertSame(160, mb_strlen($results[0]->snippet));
    }

    public function testReportsAnUnusableEngineInsteadOfAnEmptyResult(): void
    {
        $this->dropIndex();

        // No index means the backend is broken, not that the library is empty —
        // the read must fail loudly (the fallback to FULLTEXT is HMAI-365).
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The search engine query failed');

        $this->engine->search(new SearchQuery('dune'));
    }
}
