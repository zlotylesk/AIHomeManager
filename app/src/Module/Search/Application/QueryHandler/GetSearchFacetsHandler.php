<?php

declare(strict_types=1);

namespace App\Module\Search\Application\QueryHandler;

use App\Module\Search\Application\Query\GetSearchFacets;
use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Domain\ReadModel\SearchFacet;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Thin query.bus handler: delegates to the {@see SearchEngineInterface} port and
 * returns the per-type match counts (HMAI-364).
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetSearchFacetsHandler
{
    public function __construct(private SearchEngineInterface $engine)
    {
    }

    /**
     * @return list<SearchFacet>
     */
    public function __invoke(GetSearchFacets $query): array
    {
        return $this->engine->facets($query->criteria);
    }
}
