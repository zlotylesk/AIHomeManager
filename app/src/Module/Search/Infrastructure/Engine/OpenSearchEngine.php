<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Engine;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Domain\ValueObject\SearchResult;
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
 * things FULLTEXT does, so both backends answer alike. Field boosts, facets and
 * fuzziness are HMAI-364's scope; degrading to FULLTEXT when the engine is down
 * is HMAI-365's.
 *
 * One ordering difference is knowingly left open: FULLTEXT breaks a score tie on
 * `title ASC`, while this engine leaves tied hits in the engine's own order, so
 * a tie straddling a page boundary could repeat or drop a row. Adding the
 * equivalent secondary sort needs `title` to carry a sortable keyword — which is
 * exactly what HMAI-362 decides — so it belongs to that ticket rather than to a
 * guess made here.
 */
final readonly class OpenSearchEngine implements SearchEngineInterface
{
    /**
     * The index this adapter reads. It mirrors the FULLTEXT `search_documents`
     * table field-for-field (`type`, `source_id`, `title`, `content`, `url`) so
     * both backends build identical Domain read models. The explicit
     * mappings/analyzers and the write alias arrive with HMAI-362, the pipeline
     * that fills the index with HMAI-363.
     */
    public const string INDEX = 'search_documents';

    private const int SNIPPET_LENGTH = 160;

    public function __construct(private Client $client)
    {
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
                'index' => self::INDEX,
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
            // set without contributing to the relevance score. `term` matches
            // whether HMAI-362 maps `type` as a keyword or leaves it analyzed —
            // every SearchResultType value is a single lowercase word.
            $bool['filter'] = [['term' => ['type' => $typeFilter->value]]];
        }

        return [
            'from' => ($query->page - 1) * $query->perPage,
            'size' => $query->perPage,
            'query' => ['bool' => $bool],
        ];
    }
}
