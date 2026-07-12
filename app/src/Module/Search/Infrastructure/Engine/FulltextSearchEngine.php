<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Engine;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Domain\ValueObject\SearchResult;
use Doctrine\DBAL\Connection;

/**
 * MySQL FULLTEXT implementation of the search engine port. Runs a
 * `MATCH … AGAINST … IN NATURAL LANGUAGE MODE` query over the `search_documents`
 * index table, ranks hits by relevance score, paginates, and optionally filters
 * by type — mapping each row to a Domain {@see SearchResult}. The port seam lets
 * a future Elasticsearch engine (HMAI-359) drop in without touching the read side.
 */
final readonly class FulltextSearchEngine implements SearchEngineInterface
{
    private const int SNIPPET_LENGTH = 160;

    public function __construct(private Connection $connection)
    {
    }

    public function search(SearchQuery $query): array
    {
        $sql = 'SELECT type, source_id, title, content, url, '
            .'MATCH(title, content) AGAINST (:scoreTerm IN NATURAL LANGUAGE MODE) AS score '
            .'FROM search_documents '
            .'WHERE MATCH(title, content) AGAINST (:whereTerm IN NATURAL LANGUAGE MODE)';
        $params = ['scoreTerm' => $query->term, 'whereTerm' => $query->term];

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
}
