<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Engine;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Domain\ReadModel\SearchFacet;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Domain\ValueObject\SearchResult;
use Doctrine\DBAL\Connection;

/**
 * MySQL FULLTEXT implementation of the search engine port. Runs a
 * `MATCH … AGAINST … IN NATURAL LANGUAGE MODE` query over the `search_documents`
 * index table, ranks hits by relevance score, paginates, and optionally filters
 * by type — mapping each row to a Domain {@see SearchResult}. The port seam lets
 * the OpenSearch engine (HMAI-361) drop in without touching the read side.
 *
 * HMAI-364 gave this side two of the three relevance features the epic promised.
 * **Facets** are here because they are a contract surface, not a quality one:
 * the SEARCH_ENGINE_BACKEND flag still defaults to `fulltext`, so leaving them
 * to OpenSearch alone would ship an endpoint that answers nothing in the
 * configuration we actually run. **Title boosting** is here because it costs one
 * extra FULLTEXT index and it narrows the ranking gap the cutover (HMAI-366) has
 * to survive.
 *
 * **Typo tolerance is deliberately absent.** MySQL's natural-language mode has
 * no notion of edit distance, and faking one with `LIKE` would scan the table
 * and rank nothing. That is a real difference between the backends, and it is
 * exactly the kind of difference this epic exists to resolve — so it is recorded
 * here rather than papered over.
 */
final readonly class FulltextSearchEngine implements SearchEngineInterface
{
    private const int SNIPPET_LENGTH = 160;

    /**
     * How much more a title match is worth than a body match. Mirrors the
     * `title^3` weight the OpenSearch engine gives the same field, so the two
     * backends at least agree on what matters most.
     */
    private const int TITLE_BOOST = 3;

    public function __construct(private Connection $connection)
    {
    }

    public function search(SearchQuery $query): array
    {
        // Two MATCH expressions, two scores: one over the title alone (its own
        // FULLTEXT index, added in HMAI-364) and one over the combined index
        // that also decides membership. Summing them with a weight is how the
        // title outranks the body without changing which rows match at all.
        $sql = 'SELECT type, source_id, title, content, url, '
            .'(MATCH(title) AGAINST (:titleTerm IN NATURAL LANGUAGE MODE) * '.self::TITLE_BOOST
            .' + MATCH(title, content) AGAINST (:scoreTerm IN NATURAL LANGUAGE MODE)) AS score '
            .'FROM search_documents '
            .'WHERE MATCH(title, content) AGAINST (:whereTerm IN NATURAL LANGUAGE MODE)';
        $params = ['titleTerm' => $query->term, 'scoreTerm' => $query->term, 'whereTerm' => $query->term];

        $typeFilter = $query->typeFilter;
        if (null !== $typeFilter) {
            $sql .= ' AND type = :type';
            $params['type'] = $typeFilter->value;
        }

        $offset = ($query->page - 1) * $query->perPage;
        $sql .= sprintf(' ORDER BY score DESC, title ASC LIMIT %d OFFSET %d', $query->perPage, $offset);

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map(
            static fn (array $row): SearchResult => new SearchResult(
                SearchResultType::from((string) $row['type']),
                (string) $row['source_id'],
                (string) $row['title'],
                mb_substr((string) $row['content'], 0, self::SNIPPET_LENGTH),
                (string) $row['url'],
            ),
            $rows,
        );
    }

    public function facets(SearchQuery $query): array
    {
        // No type filter and no LIMIT: the counts describe the whole match set
        // and every type it spans (see the port's contract). The result is at
        // most one row per SearchResultType, so grouping is cheap even on a
        // large index.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT type, COUNT(*) AS hits FROM search_documents '
            .'WHERE MATCH(title, content) AGAINST (:term IN NATURAL LANGUAGE MODE) '
            .'GROUP BY type ORDER BY hits DESC, type ASC',
            ['term' => $query->term],
        );

        $facets = [];
        foreach ($rows as $row) {
            $type = SearchResultType::tryFrom((string) $row['type']);
            if (null === $type) {
                // A stale row from a type this application no longer knows —
                // skip it rather than fail the whole facet read.
                continue;
            }

            $facets[] = new SearchFacet($type, (int) $row['hits']);
        }

        return $facets;
    }
}
