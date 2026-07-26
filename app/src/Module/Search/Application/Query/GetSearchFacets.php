<?php

declare(strict_types=1);

namespace App\Module\Search\Application\Query;

use App\Module\Search\Domain\ValueObject\SearchQuery;

/**
 * The query.bus message asking how many documents of each type the phrase
 * matches (HMAI-364).
 *
 * It carries the same {@see SearchQuery} value object as {@see Search} rather
 * than a bare phrase, so the caller does not have to know which parts of a
 * search the facets ignore — the engine port defines that, and reusing the VO
 * keeps the phrase validation (non-blank) in one place.
 */
final readonly class GetSearchFacets
{
    public function __construct(public SearchQuery $criteria)
    {
    }
}
