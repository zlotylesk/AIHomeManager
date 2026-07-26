<?php

declare(strict_types=1);

namespace App\Module\Search\Domain\ReadModel;

use App\Module\Search\Domain\Enum\SearchResultType;
use InvalidArgumentException;

/**
 * How many documents of one {@see SearchResultType} the phrase matches
 * (HMAI-364).
 *
 * A facet answers a different question than a page of results does: the result
 * list says "here are twenty hits", the facet says "there are forty-two books
 * and three tasks behind them". That is what lets someone narrow a search
 * instead of paging blindly through it.
 *
 * Two rules the engines share and that the read model exists to make explicit:
 * facets count the **whole** match set, not the current page, and they are
 * computed **ignoring the active type filter** — otherwise selecting "books"
 * would leave "books" as the only visible option and there would be no way back.
 */
final readonly class SearchFacet
{
    public function __construct(
        public SearchResultType $type,
        public int $count,
    ) {
        if ($count < 0) {
            throw new InvalidArgumentException(sprintf('Facet count must not be negative, %d given.', $count));
        }
    }
}
