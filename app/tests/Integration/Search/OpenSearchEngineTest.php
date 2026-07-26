<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Infrastructure\Engine\OpenSearchEngine;
use App\Module\Search\Infrastructure\Index\SearchIndexDefinition;
use App\Module\Search\Infrastructure\Index\SearchIndexManager;
use App\Tests\Support\ResetsSearchIndex;
use OpenSearch\Client;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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
 * pipeline is HMAI-363's scope. The index itself is provisioned through the real
 * {@see SearchIndexManager} (HMAI-362), so the adapter is exercised against the
 * mappings and analyzers it will actually meet in production, and reads go
 * through the alias rather than a physical index name.
 */
final class OpenSearchEngineTest extends KernelTestCase
{
    use ResetsSearchIndex;

    private Client $client;
    private SearchIndexDefinition $definition;
    private OpenSearchEngine $engine;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Client $client */
        $client = static::getContainer()->get('app.search_client');
        /** @var SearchIndexDefinition $definition */
        $definition = static::getContainer()->get(SearchIndexDefinition::class);

        $this->client = $client;
        $this->definition = $definition;
        $this->engine = new OpenSearchEngine($client, $definition);

        $this->resetSearchIndex($client, $definition);
        new SearchIndexManager($client, $definition)->createIfMissing();

        $this->seed(SearchResultType::BOOK, 'b1', 'Dune', 'Frank Herbert desert planet', '/books');
        $this->seed(SearchResultType::BOOK, 'b2', 'Space Odyssey', 'a space voyage through space', '/books');
        $this->seed(SearchResultType::SERIES, 's1', 'Deep Space Nine', 'orbital station drama', '/series');
        $this->seed(SearchResultType::ARTICLE, 'a1', 'Cooking Pasta', 'italian cuisine tips', '/articles');
        $this->seed(SearchResultType::TASK, 't1', 'Buy groceries', '', '/tasks');
        $this->seed(SearchResultType::MUSIC, 'm1', 'Jazz Session', 'live studio recording', '/music');

        // Make the writes searchable before the assertions run — indexing is
        // near-real-time, not synchronous.
        $this->refresh();
    }

    protected function tearDown(): void
    {
        $this->resetSearchIndex($this->client, $this->definition);
        parent::tearDown();
    }

    private function refresh(): void
    {
        $this->client->indices()->refresh(['index' => $this->definition->alias()]);
    }

    private function seed(SearchResultType $type, string $id, string $title, string $content, string $url): void
    {
        $this->client->index([
            'index' => $this->definition->alias(),
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
        $this->refresh();

        $results = $this->engine->search(new SearchQuery('kaleidoscope'));

        self::assertCount(1, $results);
        // Same 160-character cut as FULLTEXT, so a switch cannot change what the
        // UI renders under a hit.
        self::assertSame(160, mb_strlen($results[0]->snippet));
    }

    /**
     * HMAI-364, mirroring {@see SearchEngineTest::testATitleMatchOutranksABodyMatch}:
     * the phrase sits once in each document, in mirror positions, and the titles
     * are ordered so the alphabetical tie-break would place them the other way
     * round.
     */
    public function testATitleMatchOutranksABodyMatch(): void
    {
        $this->seed(SearchResultType::BOOK, 'in-title', 'Zenit obserwacyjny', 'przewodnik testowy', '/books');
        $this->seed(SearchResultType::BOOK, 'in-body', 'Almanach testowy', 'zenit obserwacyjny', '/books');
        $this->refresh();

        $results = $this->engine->search(new SearchQuery('zenit'));

        self::assertCount(2, $results);
        self::assertSame('in-title', $results[0]->id);
        self::assertSame('in-body', $results[1]->id);
    }

    /**
     * The capability FULLTEXT cannot offer at all (MySQL natural-language mode
     * has no edit distance) — the concrete reason this epic exists.
     */
    public function testFindsAResultDespiteATypo(): void
    {
        $results = $this->engine->search(new SearchQuery('dume'));

        self::assertCount(1, $results);
        self::assertSame('b1', $results[0]->id, 'A single mistyped character still reaches "Dune".');
    }

    public function testAnExactMatchOutranksATypoMatch(): void
    {
        $this->seed(SearchResultType::BOOK, 'exact', 'Kometa', 'obserwacja nieba', '/books');
        $this->seed(SearchResultType::BOOK, 'typo', 'Komela', 'obserwacja nieba', '/books');
        $this->refresh();

        $results = $this->engine->search(new SearchQuery('kometa'));

        // Typo tolerance that can outrank a literal match is worse than none —
        // it makes the top result feel random. Hence the deliberately small
        // fuzzy boost.
        self::assertSame('exact', $results[0]->id);
        self::assertContains('typo', array_map(static fn ($result) => $result->id, $results));
    }

    /**
     * The `.folded` sub-fields HMAI-362 mapped, finally wired into the query:
     * someone typing Polish without diacritics still finds the entity.
     */
    public function testMatchesPolishTextTypedWithoutDiacritics(): void
    {
        $this->seed(SearchResultType::BOOK, 'pl1', 'Książka kucharska', 'przepisy regionalne', '/books');
        $this->refresh();

        self::assertSame('pl1', $this->engine->search(new SearchQuery('ksiazka'))[0]->id);
    }

    public function testMatchesAnInflectedPolishForm(): void
    {
        $this->seed(SearchResultType::BOOK, 'pl1', 'Książka kucharska', 'przepisy regionalne', '/books');
        $this->refresh();

        // The stemmed field's job: "książkami" and "książka" collapse onto one
        // token, which is why the Polish analyzer is installed at all.
        self::assertSame('pl1', $this->engine->search(new SearchQuery('książkami'))[0]->id);
    }

    public function testCountsMatchesPerType(): void
    {
        $facets = $this->engine->facets(new SearchQuery('space'));

        self::assertCount(2, $facets);
        self::assertSame(SearchResultType::BOOK, $facets[0]->type);
        self::assertSame(1, $facets[0]->count);
        self::assertSame(SearchResultType::SERIES, $facets[1]->type);
        self::assertSame(1, $facets[1]->count);
    }

    public function testFacetsIgnoreTheTypeFilter(): void
    {
        $facets = $this->engine->facets(new SearchQuery('space', SearchResultType::BOOK));

        self::assertSame(
            [SearchResultType::BOOK, SearchResultType::SERIES],
            array_map(static fn ($facet) => $facet->type, $facets),
        );
    }

    public function testFacetsSpanTheWholeMatchSetNotTheRequestedPage(): void
    {
        $facets = $this->engine->facets(new SearchQuery('space', null, 2, 1));

        self::assertSame(2, array_sum(array_map(static fn ($facet) => $facet->count, $facets)));
    }

    public function testFacetsAreEmptyWhenNothingMatches(): void
    {
        self::assertSame([], $this->engine->facets(new SearchQuery('nonexistentqwerty')));
    }

    public function testFacetsOmitTypesWithoutMatches(): void
    {
        $types = array_map(static fn ($facet) => $facet->type, $this->engine->facets(new SearchQuery('dune')));

        self::assertSame([SearchResultType::BOOK], $types);
    }

    public function testReportsAnUnusableEngineInsteadOfAnEmptyResult(): void
    {
        $this->resetSearchIndex($this->client, $this->definition);

        // No index means the backend is broken, not that the library is empty —
        // the read must fail loudly (the fallback to FULLTEXT is HMAI-365).
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The search engine query failed');

        $this->engine->search(new SearchQuery('dune'));
    }
}
