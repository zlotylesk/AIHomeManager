<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Search\Domain\ValueObject;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SearchQueryTest extends TestCase
{
    public function testDefaultsToUnfilteredFirstPage(): void
    {
        $query = new SearchQuery('dune');

        self::assertSame('dune', $query->term);
        self::assertNull($query->typeFilter);
        self::assertFalse($query->hasTypeFilter());
        self::assertSame(1, $query->page);
        self::assertSame(20, $query->perPage);
    }

    public function testCarriesTypeFilterAndPagination(): void
    {
        $query = new SearchQuery('dune', SearchResultType::BOOK, 3, 50);

        self::assertSame(SearchResultType::BOOK, $query->typeFilter);
        self::assertTrue($query->hasTypeFilter());
        self::assertSame(3, $query->page);
        self::assertSame(50, $query->perPage);
    }

    public function testThrowsWhenTermIsBlank(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Search term must not be empty.');

        new SearchQuery('   ');
    }

    public function testThrowsWhenPageBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SearchQuery('dune', null, 0);
    }

    public function testThrowsWhenPerPageBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SearchQuery('dune', null, 1, 0);
    }

    public function testThrowsWhenPerPageAboveMax(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SearchQuery('dune', null, 1, SearchQuery::MAX_PER_PAGE + 1);
    }

    public function testEqualsIsValueBased(): void
    {
        $a = new SearchQuery('dune', SearchResultType::BOOK, 2, 20);
        $b = new SearchQuery('dune', SearchResultType::BOOK, 2, 20);
        $c = new SearchQuery('dune', SearchResultType::SERIES, 2, 20);

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
