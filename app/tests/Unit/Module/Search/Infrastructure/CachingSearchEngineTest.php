<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Search\Infrastructure;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Domain\ReadModel\SearchFacet;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Domain\ValueObject\SearchResult;
use App\Module\Search\Infrastructure\Cache\CachingSearchEngine;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CachingSearchEngineTest extends TestCase
{
    public function testServesRepeatedQueryFromCache(): void
    {
        $query = new SearchQuery('dune');
        $expected = [new SearchResult(SearchResultType::BOOK, 'b1', 'Dune', 'desert', '/books')];

        /** @var SearchEngineInterface&MockObject $inner */
        $inner = $this->createMock(SearchEngineInterface::class);
        $inner->expects(self::once())->method('search')->willReturn($expected);

        $engine = new CachingSearchEngine($inner, new ArrayAdapter());

        self::assertEquals($expected, $engine->search($query));
        self::assertEquals($expected, $engine->search($query));
    }

    public function testDistinctPhrasesAreComputedSeparately(): void
    {
        /** @var SearchEngineInterface&MockObject $inner */
        $inner = $this->createMock(SearchEngineInterface::class);
        $inner->expects(self::exactly(2))->method('search')->willReturn([]);

        $engine = new CachingSearchEngine($inner, new ArrayAdapter());

        $engine->search(new SearchQuery('dune'));
        $engine->search(new SearchQuery('space'));
    }

    public function testKeyNormalizesCaseAndSurroundingWhitespace(): void
    {
        /** @var SearchEngineInterface&MockObject $inner */
        $inner = $this->createMock(SearchEngineInterface::class);
        $inner->expects(self::once())->method('search')->willReturn([]);

        $engine = new CachingSearchEngine($inner, new ArrayAdapter());

        $engine->search(new SearchQuery('Dune'));
        $engine->search(new SearchQuery('  dune  '));
    }

    public function testTypeFilterAndPaginationArePartOfTheKey(): void
    {
        /** @var SearchEngineInterface&MockObject $inner */
        $inner = $this->createMock(SearchEngineInterface::class);
        $inner->expects(self::exactly(3))->method('search')->willReturn([]);

        $engine = new CachingSearchEngine($inner, new ArrayAdapter());

        $engine->search(new SearchQuery('dune'));
        $engine->search(new SearchQuery('dune', SearchResultType::BOOK));
        $engine->search(new SearchQuery('dune', null, 2));
    }

    /**
     * HMAI-361: two backends now sit behind the port, so a cached answer belongs
     * to the engine that produced it — otherwise flipping SEARCH_ENGINE_BACKEND
     * would keep serving the old backend's results until the TTL ran out.
     */
    public function testBackendsDoNotShareCachedResults(): void
    {
        $query = new SearchQuery('dune');
        $cache = new ArrayAdapter();

        $first = new class implements SearchEngineInterface {
            public function search(SearchQuery $query): array
            {
                return [new SearchResult(SearchResultType::BOOK, 'b1', 'Dune', 'from the first engine', '/books')];
            }

            public function facets(SearchQuery $query): array
            {
                return [new SearchFacet(SearchResultType::BOOK, 1)];
            }
        };
        $second = new class implements SearchEngineInterface {
            public function search(SearchQuery $query): array
            {
                return [new SearchResult(SearchResultType::BOOK, 'b1', 'Dune', 'from the second engine', '/books')];
            }

            public function facets(SearchQuery $query): array
            {
                return [new SearchFacet(SearchResultType::BOOK, 2)];
            }
        };

        self::assertSame('from the first engine', new CachingSearchEngine($first, $cache)->search($query)[0]->snippet);
        self::assertSame('from the second engine', new CachingSearchEngine($second, $cache)->search($query)[0]->snippet);
    }

    public function testServesRepeatedFacetsFromCache(): void
    {
        /** @var SearchEngineInterface&MockObject $inner */
        $inner = $this->createMock(SearchEngineInterface::class);
        $inner->expects(self::once())
            ->method('facets')
            ->willReturn([new SearchFacet(SearchResultType::BOOK, 3)]);

        $engine = new CachingSearchEngine($inner, new ArrayAdapter());
        $query = new SearchQuery('dune');

        self::assertSame(3, $engine->facets($query)[0]->count);
        self::assertSame(3, $engine->facets($query)[0]->count);
    }

    /**
     * HMAI-364: facets describe the whole match set and ignore both the type
     * filter and the page, so keying them by either would split one answer into
     * many identical entries and miss the cache exactly when the user is
     * narrowing — the moment the counts are needed most.
     */
    public function testFacetsOfTheSamePhraseShareOneCacheEntryAcrossFiltersAndPages(): void
    {
        /** @var SearchEngineInterface&MockObject $inner */
        $inner = $this->createMock(SearchEngineInterface::class);
        $inner->expects(self::once())
            ->method('facets')
            ->willReturn([new SearchFacet(SearchResultType::BOOK, 3)]);

        $engine = new CachingSearchEngine($inner, new ArrayAdapter());

        $engine->facets(new SearchQuery('dune'));
        $engine->facets(new SearchQuery('dune', SearchResultType::BOOK));
        $engine->facets(new SearchQuery('dune', null, 4, 5));
    }

    public function testFacetsAndResultsDoNotOverwriteEachOther(): void
    {
        /** @var SearchEngineInterface&MockObject $inner */
        $inner = $this->createMock(SearchEngineInterface::class);
        $inner->method('search')->willReturn([new SearchResult(SearchResultType::BOOK, 'b1', 'Dune', '', '/books')]);
        $inner->method('facets')->willReturn([new SearchFacet(SearchResultType::BOOK, 3)]);

        $engine = new CachingSearchEngine($inner, new ArrayAdapter());
        $query = new SearchQuery('dune');

        // Two different answers to the same phrase; a shared key would make the
        // second read return the first one's shape.
        self::assertCount(1, $engine->search($query));
        self::assertSame(3, $engine->facets($query)[0]->count);
        self::assertSame('b1', $engine->search($query)[0]->id);
    }
}
