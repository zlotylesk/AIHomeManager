<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Search\Application;

use App\Module\Search\Application\Query\Search;
use App\Module\Search\Application\QueryHandler\SearchHandler;
use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Domain\ValueObject\SearchResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SearchHandlerTest extends TestCase
{
    public function testDelegatesToTheEngineWithTheQueryCriteria(): void
    {
        $criteria = new SearchQuery('dune', SearchResultType::BOOK, 2, 10);
        $expected = [new SearchResult(SearchResultType::BOOK, 'b1', 'Dune', 'desert', '/books')];

        /** @var SearchEngineInterface&MockObject $engine */
        $engine = $this->createMock(SearchEngineInterface::class);
        $engine->expects(self::once())
            ->method('search')
            ->with($criteria)
            ->willReturn($expected);

        $handler = new SearchHandler($engine);

        self::assertSame($expected, $handler(new Search($criteria)));
    }
}
