<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Search\Infrastructure;

use App\Module\Search\Infrastructure\Index\SearchIndexDefinition;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * HMAI-362: the index schema as a value — the parts worth pinning without an
 * engine. What the analyzers actually *do* to Polish text is asserted against a
 * real OpenSearch in {@see \App\Tests\Integration\Search\SearchIndexManagerTest}.
 */
final class SearchIndexDefinitionTest extends TestCase
{
    private SearchIndexDefinition $definition;

    protected function setUp(): void
    {
        $this->definition = new SearchIndexDefinition('search_documents');
    }

    public function testPhysicalNamesCarryTheSchemaVersion(): void
    {
        $name = $this->definition->newIndexName(new DateTimeImmutable('2026-07-26 12:34:56'));

        // The version in the name is what makes a half-finished migration
        // visible in _cat/indices instead of silent.
        self::assertStringStartsWith('search_documents_v'.SearchIndexDefinition::SCHEMA_VERSION.'_20260726123456_', $name);
    }

    public function testTwoIndexesCreatedInTheSameSecondDoNotCollide(): void
    {
        $at = new DateTimeImmutable('2026-07-26 12:34:56');

        // Reindexing twice within one second is routine in the test suite; a
        // timestamp alone would make the second create fail.
        self::assertNotSame($this->definition->newIndexName($at), $this->definition->newIndexName($at));
    }

    public function testIndexPatternMatchesEverySchemaVersion(): void
    {
        self::assertSame('search_documents_v*', $this->definition->indexPattern());
    }

    public function testPolishAnalyzerStemsBeforeFolding(): void
    {
        $settings = $this->definition->settings();

        // Order is the whole correctness of the analyzer: Stempel is trained on
        // accented Polish, so folding first would hand it words it cannot stem.
        self::assertSame(
            ['lowercase', 'aihm_polish_stop', 'aihm_polish_stem', 'asciifolding'],
            $settings['analysis']['analyzer'][SearchIndexDefinition::ANALYZER_POLISH]['filter'] ?? null,
        );
        self::assertSame(
            ['lowercase', 'asciifolding'],
            $settings['analysis']['analyzer'][SearchIndexDefinition::ANALYZER_FOLDED]['filter'] ?? null,
        );
    }

    public function testSingleNodeShardLayout(): void
    {
        $settings = $this->definition->settings();

        self::assertSame(1, $settings['number_of_shards'] ?? null);
        // A replica has nowhere to go on one node and would park the cluster on
        // yellow forever.
        self::assertSame(0, $settings['number_of_replicas'] ?? null);
    }

    public function testDocumentShapeIsClosed(): void
    {
        $mappings = $this->definition->mappings();

        self::assertSame('strict', $mappings['dynamic'] ?? null);
        self::assertSame(
            ['type', 'source_id', 'title', 'content', 'url', 'indexed_at'],
            array_keys($mappings['properties'] ?? []),
            'The document mirrors the FULLTEXT table field for field, so both backends build the same SearchResult.',
        );
    }

    public function testSearchableFieldsCarryBothAnalyzers(): void
    {
        $properties = $this->definition->mappings()['properties'] ?? [];

        foreach (['title', 'content'] as $field) {
            self::assertSame('text', $properties[$field]['type'] ?? null);
            self::assertSame(SearchIndexDefinition::ANALYZER_POLISH, $properties[$field]['analyzer'] ?? null);
            self::assertSame(
                SearchIndexDefinition::ANALYZER_FOLDED,
                $properties[$field]['fields']['folded']['analyzer'] ?? null,
                sprintf('%s needs a diacritic-insensitive sub-field: stemming degrades on unaccented input.', $field),
            );
        }
    }

    public function testUrlIsCarriedButNotSearchable(): void
    {
        $url = $this->definition->mappings()['properties']['url'] ?? [];

        self::assertSame('keyword', $url['type'] ?? null);
        // Nothing queries or aggregates a URL — indexing it would cost space for
        // terms no query ever asks for.
        self::assertFalse($url['index'] ?? true);
    }
}
