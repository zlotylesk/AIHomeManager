<?php

declare(strict_types=1);

namespace App\Module\Search\Application\Query;

use App\Module\Search\Domain\ValueObject\SearchQuery;

/**
 * The query.bus message carrying a validated {@see SearchQuery} (phrase +
 * optional type filter + pagination) to the search engine.
 */
final readonly class Search
{
    public function __construct(public SearchQuery $criteria)
    {
    }
}
