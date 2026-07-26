<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Cache;

use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Domain\ReadModel\SearchFacet;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Domain\ValueObject\SearchResult;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Caches search results in Redis, keyed by the decorated engine plus the
 * normalized query (lower-cased + trimmed phrase + type filter + pagination)
 * with a short TTL. Repeated queries — e.g. the frontend's debounced keystrokes
 * — are served from Redis instead of re-running the search. The cache is wiped
 * whenever the index is rebuilt (SearchIndexer), so a hit never outlives a
 * reindex. Decorates whichever backend the SEARCH_ENGINE_BACKEND flag selected
 * (HMAI-361), behind the same {@see SearchEngineInterface} port.
 */
final readonly class CachingSearchEngine implements SearchEngineInterface
{
    private const int TTL_SECONDS = 300;

    public function __construct(
        private SearchEngineInterface $engine,
        private CacheInterface $cache,
    ) {
    }

    public function search(SearchQuery $query): array
    {
        /** @var SearchResult[] $results */
        $results = $this->cache->get($this->cacheKey($query), function (ItemInterface $item) use ($query): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return $this->engine->search($query);
        });

        return $results;
    }

    public function facets(SearchQuery $query): array
    {
        /** @var list<SearchFacet> $facets */
        $facets = $this->cache->get($this->facetsCacheKey($query), function (ItemInterface $item) use ($query): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return $this->engine->facets($query);
        });

        return $facets;
    }

    private function cacheKey(SearchQuery $query): string
    {
        $typeFilter = $query->typeFilter;

        return 'search_'.sha1(sprintf(
            '%s|%s|%s|%d|%d',
            // The engine is part of the identity of a cached result (HMAI-361):
            // without it, flipping SEARCH_ENGINE_BACKEND would keep serving the
            // previous backend's answers until the TTL ran out.
            $this->engine::class,
            mb_strtolower(trim($query->term)),
            null === $typeFilter ? '' : $typeFilter->value,
            $query->page,
            $query->perPage,
        ));
    }

    /**
     * Facets depend on the phrase alone — the port defines them as spanning the
     * whole match set and ignoring both the type filter and the page. Keying
     * them by those would split one answer across a dozen identical entries and
     * miss the hit every time the user narrowed or paged.
     */
    private function facetsCacheKey(SearchQuery $query): string
    {
        return 'search_facets_'.sha1(sprintf(
            '%s|%s',
            $this->engine::class,
            mb_strtolower(trim($query->term)),
        ));
    }
}
