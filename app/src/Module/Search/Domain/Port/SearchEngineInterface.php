<?php

declare(strict_types=1);

namespace App\Module\Search\Domain\Port;

use App\Module\Search\Domain\ReadModel\SearchFacet;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Domain\ValueObject\SearchResult;

/**
 * The search engine port: runs a {@see SearchQuery} against the product-wide
 * index and returns the ranked, paginated {@see SearchResult} list. Backed by a
 * DBAL/FULLTEXT adapter and an OpenSearch one in Infrastructure (HMAI-268,
 * HMAI-361), selected by the SEARCH_ENGINE_BACKEND flag.
 */
interface SearchEngineInterface
{
    /**
     * @return SearchResult[] ranked, paginated hits (empty when nothing matches)
     */
    public function search(SearchQuery $query): array;

    /**
     * Per-type match counts for the same phrase (HMAI-364).
     *
     * Two deliberate differences from {@see search()}, identical on every
     * backend so the UI can trust them regardless of which one is active:
     *
     * - the counts span the **entire** match set, not the requested page, so
     *   `page`/`perPage` on the query are ignored here;
     * - the query's `typeFilter` is **ignored** as well. Facets exist to offer
     *   the alternatives; narrowing them by the current selection would leave
     *   the selected type as the only one on offer.
     *
     * Types with no matches are omitted rather than reported as zero — a facet
     * is a route the user can actually take.
     *
     * @return list<SearchFacet> ordered by count descending, then type
     */
    public function facets(SearchQuery $query): array;
}
