<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Search\Infrastructure;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchEngineInterface;
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
        };
        $second = new class implements SearchEngineInterface {
            public function search(SearchQuery $query): array
            {
                return [new SearchResult(SearchResultType::BOOK, 'b1', 'Dune', 'from the second engine', '/books')];
            }
        };

        self::assertSame('from the first engine', new CachingSearchEngine($first, $cache)->search($query)[0]->snippet);
        self::assertSame('from the second engine', new CachingSearchEngine($second, $cache)->search($query)[0]->snippet);
    }
}
