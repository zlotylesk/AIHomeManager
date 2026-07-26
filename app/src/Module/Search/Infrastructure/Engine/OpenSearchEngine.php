<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Engine;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Domain\ReadModel\SearchFacet;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Domain\ValueObject\SearchResult;
use App\Module\Search\Infrastructure\Index\SearchIndexDefinition;
use OpenSearch\Client;
use RuntimeException;
use Throwable;

/**
 * OpenSearch implementation of the search engine port (HMAI-361, epic HMAI-359)
 * — the second adapter behind the same Domain contract as
 * {@see FulltextSearchEngine}, so the Application/UI read side is untouched by a
 * backend switch (see {@see SearchEngineFactory} for the feature flag).
 *
 * Named after the engine the project actually provisioned in HMAI-360
 * (opensearch-php against an OpenSearch node), not after the ticket's tentative
 * "ElasticsearchSearchAdapter" — a class calling itself Elasticsearch while
 * talking to OpenSearch would be misleading, and the module's existing adapter
 * is `*SearchEngine`, not `*Adapter`.
 *
 * HMAI-364 turned the deliberately plain first query into the relevance work the
 * epic promised: field boosts, the diacritic-insensitive `.folded` sub-fields
 * HMAI-362 mapped, typo tolerance and per-type facets. Degrading to FULLTEXT
 * when the engine is down is still HMAI-365's scope.
 *
 * Reads go through the alias rather than a physical index name (HMAI-362), so a
 * reindex swaps the data underneath this adapter without it noticing.
 */
final readonly class OpenSearchEngine implements SearchEngineInterface
{
    private const int SNIPPET_LENGTH = 160;

    /**
     * A title match says more about relevance than a body match: the title is
     * what the user recognises the entity by, while `content` is a blurb, an
     * author or a category. The stemmed field outranks its folded twin so that
     * properly typed Polish wins over the fallback spelling.
     *
     * @var list<string>
     */
    private const array MATCH_FIELDS = ['title^3', 'title.folded^2', 'content', 'content.folded^0.5'];

    /**
     * Typo tolerance runs against the **folded** fields only.
     *
     * Edit distance over stems is not what a user means by "a typo": Stempel
     * rewrites "książkami" to "książek", so a single mistyped letter can land
     * several edits away after stemming, while two unrelated words can collapse
     * onto neighbouring stems. The folded fields keep whole lower-cased,
     * unaccented tokens, where "one character off" means exactly that.
     *
     * @var list<string>
     */
    private const array FUZZY_FIELDS = ['title.folded', 'content.folded'];

    /**
     * Fuzzy hits are admitted, never promoted. At this weight a typo match can
     * surface a result that would otherwise be missing entirely, but it can
     * never outrank something that actually contains the phrase — which is the
     * failure mode that makes typo tolerance feel broken.
     */
    private const float FUZZY_BOOST = 0.1;

    /**
     * The first character must match exactly. Without it every short phrase
     * fuzzy-expands across the whole term dictionary, which is both slow and
     * noisy.
     */
    private const int FUZZY_PREFIX_LENGTH = 1;

    public function __construct(
        private Client $client,
        private SearchIndexDefinition $definition,
    ) {
    }

    public function search(SearchQuery $query): array
    {
        $response = $this->execute($this->searchBody($query));

        $hits = $response['hits']['hits'] ?? null;
        if (!is_array($hits)) {
            throw new RuntimeException('The search engine returned a response without a hit list.');
        }

        $results = [];
        foreach ($hits as $hit) {
            $source = is_array($hit) ? ($hit['_source'] ?? null) : null;
            if (!is_array($source)) {
                throw new RuntimeException('The search engine returned a hit without a document source.');
            }

            $results[] = new SearchResult(
                SearchResultType::from((string) $source['type']),
                (string) $source['source_id'],
                (string) $source['title'],
                mb_substr((string) ($source['content'] ?? ''), 0, self::SNIPPET_LENGTH),
                (string) $source['url'],
            );
        }

        return $results;
    }

    public function facets(SearchQuery $query): array
    {
        $response = $this->execute($this->facetBody($query));

        $buckets = $response['aggregations']['types']['buckets'] ?? null;
        if (!is_array($buckets)) {
            throw new RuntimeException('The search engine returned a response without facet buckets.');
        }

        $facets = [];
        foreach ($buckets as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }

            // A type the index carries but this application no longer knows is
            // stale data, not a reason to fail the whole facet read.
            $type = SearchResultType::tryFrom((string) ($bucket['key'] ?? ''));
            if (null === $type) {
                continue;
            }

            $facets[] = new SearchFacet($type, (int) ($bucket['doc_count'] ?? 0));
        }

        return $facets;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed, mixed>
     */
    private function execute(array $body): array
    {
        try {
            $response = $this->client->search([
                'index' => $this->definition->alias(),
                'body' => $body,
            ]);
        } catch (Throwable $e) {
            // An unreachable engine or a missing index must be loud: silently
            // answering "no results" would look like an empty library rather
            // than a broken backend. The fallback to FULLTEXT is HMAI-365.
            throw new RuntimeException(sprintf('The search engine query failed: %s', $e->getMessage()), 0, $e);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function searchBody(SearchQuery $query): array
    {
        return [
            'from' => ($query->page - 1) * $query->perPage,
            'size' => $query->perPage,
            'query' => ['bool' => $this->matchClause($query, true)],
            // Relevance first, then title — the same total order FULLTEXT
            // produces. Without the tie-break, equally scored hits come back in
            // whatever order the engine happens to produce, which is not stable
            // between requests: a tie straddling a page boundary could then show
            // the same row on both pages, or on neither. `title.keyword` is the
            // sortable copy the mapping carries for exactly this; titles longer
            // than its `ignore_above` have no keyword value and sort last rather
            // than disappearing.
            'sort' => [
                ['_score' => ['order' => 'desc']],
                ['title.keyword' => ['order' => 'asc', 'missing' => '_last']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function facetBody(SearchQuery $query): array
    {
        return [
            // Counts only — fetching hits we would immediately discard would
            // double the cost of every keystroke in the search box.
            'size' => 0,
            // The type filter is deliberately dropped (see the port's contract):
            // facets are the alternatives on offer, so narrowing them by the
            // current selection would leave exactly one of them.
            'query' => ['bool' => $this->matchClause($query, false)],
            'aggs' => [
                'types' => [
                    'terms' => [
                        'field' => 'type',
                        // The vocabulary is a closed enum, so asking for all of
                        // it keeps the counts exact rather than approximate.
                        'size' => count(SearchResultType::cases()),
                        'order' => ['_count' => 'desc'],
                    ],
                ],
            ],
        ];
    }

    /**
     * The shared scoring clause: an exact (analyzed) match plus a deliberately
     * weak fuzzy one, either of which admits a document.
     *
     * @return array<string, mixed>
     */
    private function matchClause(SearchQuery $query, bool $applyTypeFilter): array
    {
        $bool = [
            'must' => [[
                'bool' => [
                    'should' => [
                        [
                            'multi_match' => [
                                'query' => $query->term,
                                'fields' => self::MATCH_FIELDS,
                            ],
                        ],
                        [
                            'multi_match' => [
                                'query' => $query->term,
                                'fields' => self::FUZZY_FIELDS,
                                'fuzziness' => 'AUTO',
                                'prefix_length' => self::FUZZY_PREFIX_LENGTH,
                                'boost' => self::FUZZY_BOOST,
                            ],
                        ],
                    ],
                    'minimum_should_match' => 1,
                ],
            ]],
        ];

        $typeFilter = $query->typeFilter;
        if ($applyTypeFilter && null !== $typeFilter) {
            // A filter clause, not a query clause: the type narrows the result
            // set without contributing to the relevance score. `type` is mapped
            // as a keyword (HMAI-362), so the term matches the value verbatim.
            $bool['filter'] = [['term' => ['type' => $typeFilter->value]]];
        }

        return $bool;
    }
}
