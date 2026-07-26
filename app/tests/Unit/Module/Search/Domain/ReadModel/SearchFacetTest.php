<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Search\Domain\ReadModel;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\ReadModel\SearchFacet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SearchFacetTest extends TestCase
{
    public function testCarriesTypeAndCount(): void
    {
        $facet = new SearchFacet(SearchResultType::BOOK, 42);

        self::assertSame(SearchResultType::BOOK, $facet->type);
        self::assertSame(42, $facet->count);
    }

    public function testAcceptsZero(): void
    {
        // The engines omit empty types, but zero is still a coherent count and a
        // caller constructing one directly should not be refused.
        self::assertSame(0, new SearchFacet(SearchResultType::TASK, 0)->count);
    }

    public function testRejectsANegativeCount(): void
    {
        // A negative count means an aggregation was misread; failing here beats
        // rendering "Książka (-3)".
        $this->expectException(InvalidArgumentException::class);

        new SearchFacet(SearchResultType::BOOK, -1);
    }
}
