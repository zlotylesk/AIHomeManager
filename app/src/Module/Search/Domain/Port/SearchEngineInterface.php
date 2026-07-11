<?php

declare(strict_types=1);

namespace App\Module\Search\Domain\Port;

use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Domain\ValueObject\SearchResult;

/**
 * The search engine port: runs a {@see SearchQuery} against the product-wide
 * index and returns the ranked, paginated {@see SearchResult} list. Backed by a
 * DBAL/FULLTEXT adapter in Infrastructure (HMAI-268).
 */
interface SearchEngineInterface
{
    /**
     * @return SearchResult[] ranked, paginated hits (empty when nothing matches)
     */
    public function search(SearchQuery $query): array;
}
