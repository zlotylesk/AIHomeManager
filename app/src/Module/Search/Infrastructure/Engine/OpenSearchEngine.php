<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Engine;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchEngineInterface;
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
 * The query is deliberately plain: relevance-ranked `multi_match` over
 * title+content, an exact `type` filter and offset pagination — the same three
 * things FULLTEXT does, so both backends answer alike. Field boosts, facets,
 * fuzziness and the diacritic-insensitive `.folded` sub-fields HMAI-362 mapped
 * are HMAI-364's scope; degrading to FULLTEXT when the engine is down is
 * HMAI-365's.
 *
 * Reads go through the alias rather than a physical index name (HMAI-362), so a
 * reindex swaps the data underneath this adapter without it noticing.
 */
final readonly class OpenSearchEngine implements SearchEngineInterface
{
    private const int SNIPPET_LENGTH = 160;

    public function __construct(
        private Client $client,
        private SearchIndexDefinition $definition,
    ) {
    }

    public function search(SearchQuery $query): array
    {
        $response = $this->execute($query);

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

    /**
     * @return array<mixed, mixed>
     */
    private function execute(SearchQuery $query): array
    {
        try {
            $response = $this->client->search([
                'index' => $this->definition->alias(),
                'body' => $this->body($query),
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
    private function body(SearchQuery $query): array
    {
        $bool = [
            'must' => [[
                'multi_match' => [
                    'query' => $query->term,
                    'fields' => ['title', 'content'],
                ],
            ]],
        ];

        $typeFilter = $query->typeFilter;
        if (null !== $typeFilter) {
            // A filter clause, not a query clause: the type narrows the result
            // set without contributing to the relevance score. `type` is mapped
            // as a keyword (HMAI-362), so the term matches the value verbatim.
            $bool['filter'] = [['term' => ['type' => $typeFilter->value]]];
        }

        return [
            'from' => ($query->page - 1) * $query->perPage,
            'size' => $query->perPage,
            'query' => ['bool' => $bool],
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
}
