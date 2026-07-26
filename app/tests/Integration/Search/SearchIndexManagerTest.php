<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Infrastructure\Index\SearchIndexDefinition;
use App\Module\Search\Infrastructure\Index\SearchIndexManager;
use App\Tests\Support\ResetsSearchIndex;
use OpenSearch\Client;
use RuntimeException;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * HMAI-362: the index schema and the alias/reindex machinery against the real
 * engine (the docker-compose `search` service locally, the container the CI
 * `tests` job starts).
 *
 * These assertions only mean something against a real OpenSearch: whether an
 * analyzer actually collapses Polish inflection, and whether an alias swap
 * really is atomic, are properties of the engine — a mocked client would only
 * prove that this class sends the JSON it was written to send.
 */
final class SearchIndexManagerTest extends KernelTestCase
{
    use ResetsSearchIndex;

    private Client $client;
    private SearchIndexDefinition $definition;
    private SearchIndexManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Client $client */
        $client = static::getContainer()->get('app.search_client');
        /** @var SearchIndexDefinition $definition */
        $definition = static::getContainer()->get(SearchIndexDefinition::class);

        $this->client = $client;
        $this->definition = $definition;
        $this->manager = new SearchIndexManager($client, $definition);

        $this->resetSearchIndex($client, $definition);
    }

    protected function tearDown(): void
    {
        $this->resetSearchIndex($this->client, $this->definition);
        parent::tearDown();
    }

    public function testCreatesAVersionedIndexAndPointsTheAliasAtIt(): void
    {
        $index = $this->manager->createIfMissing();

        self::assertStringStartsWith($this->definition->alias().'_v'.SearchIndexDefinition::SCHEMA_VERSION.'_', $index);
        self::assertSame($index, $this->manager->currentIndex(), 'The alias resolves to the index just created.');
        self::assertTrue($this->client->indices()->existsAlias(['name' => $this->definition->alias()]));
    }

    public function testProvisioningIsIdempotent(): void
    {
        $first = $this->manager->createIfMissing();
        $second = $this->manager->createIfMissing();

        self::assertSame($first, $second);
        // A second run must not leave an orphaned index behind — the command is
        // meant to be safe to run on every deploy.
        self::assertCount(1, $this->ownedIndices($this->client, $this->definition));
    }

    public function testCollapsesPolishInflectionToASingleToken(): void
    {
        $index = $this->manager->createIfMissing();

        $tokens = $this->analyze($index, SearchIndexDefinition::ANALYZER_POLISH, 'książka książki książkami');

        // The point of the Polish analyzer: three inflected forms of one word
        // index as one term, so searching any of them finds all of them.
        self::assertCount(3, $tokens);
        self::assertCount(1, array_unique($tokens), 'All three inflected forms stem to the same token.');
    }

    public function testFoldsDiacriticsSoAnUnaccentedQueryStillMatches(): void
    {
        $index = $this->manager->createIfMissing();

        self::assertSame(
            ['zolc', 'lodz'],
            $this->analyze($index, SearchIndexDefinition::ANALYZER_FOLDED, 'Żółć Łódź'),
        );
    }

    public function testMapsFieldsForFilteringSortingAndFacets(): void
    {
        $index = $this->manager->createIfMissing();

        $mapping = $this->client->indices()->getMapping(['index' => $index]);
        $properties = $mapping[$index]['mappings']['properties'] ?? null;
        self::assertIsArray($properties);

        // keyword: exact `type` filter today, facet buckets in HMAI-364.
        self::assertSame('keyword', $properties['type']['type'] ?? null);
        // The sortable copy that makes a relevance tie deterministic.
        self::assertSame('keyword', $properties['title']['fields']['keyword']['type'] ?? null);
        // The diacritic-insensitive sub-field HMAI-364 will query alongside.
        self::assertSame(
            SearchIndexDefinition::ANALYZER_FOLDED,
            $properties['title']['fields']['folded']['analyzer'] ?? null,
        );
        self::assertSame('date', $properties['indexed_at']['type'] ?? null);
    }

    public function testRejectsADocumentCarryingAnUnmappedField(): void
    {
        $this->manager->createIfMissing();

        // `dynamic: strict` — a stray field is a bug in the indexing pipeline,
        // and inventing a mapping for it would hide that until the schema had
        // already drifted.
        $this->expectException(Throwable::class);

        $this->client->index([
            'index' => $this->definition->alias(),
            'body' => ['type' => 'book', 'source_id' => 'b1', 'title' => 'Dune', 'content' => '', 'url' => '/books', 'typo_field' => 'x'],
        ]);
    }

    public function testReindexMovesTheAliasToAFreshIndexWithoutLosingDocuments(): void
    {
        $original = $this->manager->createIfMissing();
        $this->indexDocument('b1', 'Dune');
        $this->indexDocument('b2', 'Solaris');

        $rebuilt = $this->manager->reindex();

        self::assertNotSame($original, $rebuilt, 'A reindex builds a new physical index.');
        self::assertSame($rebuilt, $this->manager->currentIndex(), 'The alias now resolves to it.');
        self::assertFalse(
            $this->client->indices()->exists(['index' => $original]),
            'The superseded index is dropped rather than left as a full second copy of the corpus.',
        );

        // Read through the alias exactly as the adapter does: the documents
        // survived the migration and the caller never saw a different name.
        $this->client->indices()->refresh(['index' => $this->definition->alias()]);
        self::assertSame(['b1', 'b2'], $this->sourceIdsThroughAlias());
    }

    public function testReindexProvisionsWhenThereIsNothingToMigrate(): void
    {
        $index = $this->manager->reindex();

        self::assertSame($index, $this->manager->currentIndex());
        self::assertCount(1, $this->ownedIndices($this->client, $this->definition));
    }

    public function testRefusesToProvisionWhenTheAliasNameIsTakenByAnIndex(): void
    {
        // The leftover an engine that ran the adapter before this ticket carries.
        $this->client->indices()->create(['index' => $this->definition->alias()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists as a concrete index');

        $this->manager->createIfMissing();
    }

    /**
     * @return list<string>
     */
    private function analyze(string $index, string $analyzer, string $text): array
    {
        $response = $this->client->indices()->analyze([
            'index' => $index,
            'body' => ['analyzer' => $analyzer, 'text' => $text],
        ]);

        $tokens = [];
        foreach ($response['tokens'] ?? [] as $token) {
            $value = is_array($token) ? ($token['token'] ?? null) : null;
            if (is_string($value)) {
                $tokens[] = $value;
            }
        }

        return $tokens;
    }

    private function indexDocument(string $sourceId, string $title): void
    {
        $this->client->index([
            'index' => $this->definition->alias(),
            'id' => 'book:'.$sourceId,
            'body' => [
                'type' => 'book',
                'source_id' => $sourceId,
                'title' => $title,
                'content' => 'sample content',
                'url' => '/books',
                'indexed_at' => '2026-07-26T12:00:00+00:00',
            ],
        ]);
        $this->client->indices()->refresh(['index' => $this->definition->alias()]);
    }

    /**
     * @return list<string>
     */
    private function sourceIdsThroughAlias(): array
    {
        $response = $this->client->search([
            'index' => $this->definition->alias(),
            'body' => ['query' => ['match_all' => new stdClass()], 'sort' => [['source_id' => 'asc']]],
        ]);

        $ids = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $source = is_array($hit) ? ($hit['_source'] ?? null) : null;
            $id = is_array($source) ? ($source['source_id'] ?? null) : null;
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
