<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Infrastructure\Engine\OpenSearchEngine;
use App\Module\Search\Infrastructure\Index\SearchIndexDefinition;
use App\Module\Search\Infrastructure\Index\SearchIndexManager;
use App\Tests\Support\ResetsSearchIndex;
use OpenSearch\Client;
use RuntimeException;

/**
 * HMAI-361: the OpenSearch adapter against the real engine (the docker-compose
 * `search` service locally, the CI `tests` job container).
 *
 * The shared scenarios come from {@see SearchEngineContractTestCase} — the same
 * corpus and the same assertions FULLTEXT answers, which is what proves the two
 * are interchangeable behind the port. Until HMAI-366 they were duplicated here
 * by hand, so nothing stopped the two suites from drifting apart.
 *
 * What remains in this class is what only this backend can do (typo tolerance,
 * Polish stemming, diacritic folding — the reasons the epic exists) plus the
 * failure it must report rather than disguise.
 *
 * The documents are indexed directly rather than through the real pipeline,
 * which is HMAI-363's scope. The index itself is provisioned through the real
 * {@see SearchIndexManager} (HMAI-362), so the adapter meets the mappings and
 * analyzers it will meet in production, and reads go through the alias rather
 * than a physical index name.
 */
final class OpenSearchEngineTest extends SearchEngineContractTestCase
{
    use ResetsSearchIndex;

    private Client $client;
    private SearchIndexDefinition $definition;

    protected function prepareCorpus(): void
    {
        /** @var Client $client */
        $client = static::getContainer()->get('app.search_client');
        /** @var SearchIndexDefinition $definition */
        $definition = static::getContainer()->get(SearchIndexDefinition::class);

        $this->client = $client;
        $this->definition = $definition;

        $this->resetSearchIndex($client, $definition);
        new SearchIndexManager($client, $definition)->createIfMissing();
    }

    protected function tearDown(): void
    {
        $this->resetSearchIndex($this->client, $this->definition);
        parent::tearDown();
    }

    protected function seed(SearchResultType $type, string $id, string $title, string $content, string $url): void
    {
        $this->client->index([
            'index' => $this->definition->alias(),
            'id' => $type->value.':'.$id,
            'body' => [
                'type' => $type->value, 'source_id' => $id, 'title' => $title, 'content' => $content, 'url' => $url,
            ],
        ]);
    }

    protected function commit(): void
    {
        // Indexing is near-real-time, not synchronous — without this the
        // assertions would race the writes.
        $this->client->indices()->refresh(['index' => $this->definition->alias()]);
    }

    protected function createEngine(): SearchEngineInterface
    {
        return new OpenSearchEngine($this->client, $this->definition);
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
        $this->commit();

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
        $this->commit();

        self::assertSame('pl1', $this->engine->search(new SearchQuery('ksiazka'))[0]->id);
    }

    public function testMatchesAnInflectedPolishForm(): void
    {
        $this->seed(SearchResultType::BOOK, 'pl1', 'Książka kucharska', 'przepisy regionalne', '/books');
        $this->commit();

        // The stemmed field's job: "książkami" and "książka" collapse onto one
        // token, which is why the Polish analyzer is installed at all.
        self::assertSame('pl1', $this->engine->search(new SearchQuery('książkami'))[0]->id);
    }

    public function testReportsAnUnusableEngineInsteadOfAnEmptyResult(): void
    {
        $this->resetSearchIndex($this->client, $this->definition);

        // No index means the backend is broken, not that the library is empty —
        // the read must fail loudly so the HMAI-365 fallback can take over.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The search engine query failed');

        $this->engine->search(new SearchQuery('dune'));
    }
}
