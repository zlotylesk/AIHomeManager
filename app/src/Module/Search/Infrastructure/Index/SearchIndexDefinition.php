<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Index;

use DateTimeImmutable;

/**
 * The declarative schema of the OpenSearch search index (HMAI-362, epic
 * HMAI-359): analyzers, field mappings and the naming scheme that lets a mapping
 * change ship as a reindex behind an alias instead of a search outage.
 *
 * Pure configuration — no I/O. {@see SearchIndexManager} is what talks to the
 * engine; keeping the two apart is what makes the schema unit-testable and stops
 * "what the index looks like" from being spread across a create call, a reindex
 * call and a test fixture.
 *
 * Nothing here is written to `config/` on purpose. The ticket suggested keeping
 * the schema version in configuration, but a version that can be edited
 * independently of the mappings it describes is a version that will eventually
 * lie: bumping it belongs in the same commit as the mapping change, so it lives
 * beside the mapping. The one thing that genuinely is deployment-specific — the
 * alias name — *is* injected, because the test suite has to point parallel
 * workers at separate indices on one shared engine.
 */
final readonly class SearchIndexDefinition
{
    /**
     * Bump whenever {@see settings()} or {@see mappings()} change. The version is
     * part of every physical index name, so `_cat/indices` shows at a glance
     * which schema the live data was built with, and a half-finished migration
     * is visible rather than silent.
     */
    public const int SCHEMA_VERSION = 1;

    /**
     * Polish text: lowercase, drop Polish stopwords, stem, then fold diacritics.
     *
     * Order matters. Stempel is trained on properly accented Polish, so folding
     * before it would hand the stemmer words it does not recognise; folding last
     * keeps the stemmer accurate and still normalises the token it produces
     * ("książkami" -> "książek" -> "ksiazek").
     */
    public const string ANALYZER_POLISH = 'aihm_polish';

    /**
     * Diacritic-insensitive fallback: lowercase + fold, no stemming.
     *
     * It exists because stemming degrades on unaccented input — "ksiazka" still
     * reaches "ksiazek", but "ksiazkami" stems to "ksiazkam" and no longer
     * matches. Someone typing without Polish diacritics would silently get worse
     * results, so every searchable field carries a `.folded` sub-field analyzed
     * this way. Wiring it into the query (as a lower-weighted `multi_match`
     * field) is HMAI-364's relevance work; the schema has to exist first.
     */
    public const string ANALYZER_FOLDED = 'aihm_folded';

    public function __construct(private string $alias)
    {
    }

    /**
     * The name reads and writes go through. It always resolves to exactly one
     * physical index; swapping which one is how a reindex stays invisible to
     * callers.
     */
    public function alias(): string
    {
        return $this->alias;
    }

    /**
     * A fresh physical index name: `{alias}_v{schema}_{timestamp}_{random}`.
     *
     * The random tail is not decoration — two reindexes within the same second
     * (routine in the test suite) would otherwise collide on the timestamp and
     * the second one would fail to create its index.
     */
    public function newIndexName(DateTimeImmutable $at): string
    {
        return sprintf(
            '%s_v%d_%s_%s',
            $this->alias,
            self::SCHEMA_VERSION,
            $at->format('YmdHis'),
            bin2hex(random_bytes(3)),
        );
    }

    /**
     * Matches every physical index this definition has ever created, across
     * schema versions — the handle for listing or cleaning up superseded ones.
     */
    public function indexPattern(): string
    {
        return $this->alias.'_v*';
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return [
            'settings' => $this->settings(),
            'mappings' => $this->mappings(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            // One node, one user: a second shard would only split the term
            // statistics that relevance scoring is computed from, and a replica
            // would park the cluster on yellow forever with nowhere to place it.
            'number_of_shards' => 1,
            'number_of_replicas' => 0,
            'analysis' => [
                'filter' => [
                    // Both filter types come from the `analysis-stempel` plugin,
                    // which the stock OpenSearch image does not carry — see
                    // docker/opensearch/Dockerfile. Declared explicitly rather
                    // than referenced as pre-configured filters so the settings
                    // do not depend on how the plugin registers its defaults.
                    'aihm_polish_stop' => ['type' => 'polish_stop'],
                    'aihm_polish_stem' => ['type' => 'polish_stem'],
                ],
                'analyzer' => [
                    self::ANALYZER_POLISH => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'filter' => [
                            'lowercase',
                            'aihm_polish_stop',
                            'aihm_polish_stem',
                            'asciifolding',
                        ],
                    ],
                    self::ANALYZER_FOLDED => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'filter' => ['lowercase', 'asciifolding'],
                    ],
                ],
            ],
        ];
    }

    /**
     * The document shape mirrors the FULLTEXT `search_documents` table
     * field-for-field, because both backends have to build the identical
     * `SearchResult`. The per-module differences the ticket asks about are
     * already resolved upstream: each module's DBAL provider (HMAI-267) folds
     * its own columns into this one normalised shape, so a per-module mapping
     * here would mean five divergent document types where the port promises one.
     * What stays per-module is `type`, and it is a keyword precisely so
     * HMAI-364 can facet on it.
     *
     * @return array<string, mixed>
     */
    public function mappings(): array
    {
        return [
            // An unexpected field is rejected instead of quietly inventing a
            // mapping for itself. The document shape is a fixed contract
            // (SearchableDocument), so a typo in the indexing pipeline should
            // fail at the first document rather than rot the schema silently.
            'dynamic' => 'strict',
            'properties' => [
                'type' => ['type' => 'keyword'],
                'source_id' => ['type' => 'keyword'],
                'title' => [
                    'type' => 'text',
                    'analyzer' => self::ANALYZER_POLISH,
                    'fields' => [
                        'folded' => [
                            'type' => 'text',
                            'analyzer' => self::ANALYZER_FOLDED,
                        ],
                        // Sortable copy of the raw title. This is what lets the
                        // adapter break a relevance tie deterministically —
                        // without it, equally-scored hits come back in whatever
                        // order the engine happens to produce, and a tie
                        // straddling a page boundary can repeat or drop a row.
                        'keyword' => [
                            'type' => 'keyword',
                            'ignore_above' => 256,
                        ],
                    ],
                ],
                'content' => [
                    'type' => 'text',
                    'analyzer' => self::ANALYZER_POLISH,
                    'fields' => [
                        'folded' => [
                            'type' => 'text',
                            'analyzer' => self::ANALYZER_FOLDED,
                        ],
                    ],
                ],
                // Carried through to the result so the UI can link to the
                // entity, never searched or aggregated — indexing it would cost
                // space for a term nobody queries.
                'url' => ['type' => 'keyword', 'index' => false],
                // When the pipeline (HMAI-363) last wrote this document. The
                // handle for spotting a stale index and for the retention work
                // in HMAI-365.
                'indexed_at' => ['type' => 'date'],
            ],
        ];
    }
}
