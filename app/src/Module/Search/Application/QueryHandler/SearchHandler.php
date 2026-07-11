<?php

declare(strict_types=1);

namespace App\Module\Search\Application\QueryHandler;

use App\Module\Search\Application\Query\Search;
use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Domain\ValueObject\SearchResult;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Thin query.bus handler: delegates the search to the {@see SearchEngineInterface}
 * port and returns the ranked, paginated {@see SearchResult} list.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class SearchHandler
{
    public function __construct(private SearchEngineInterface $engine)
    {
    }

    /**
     * @return SearchResult[]
     */
    public function __invoke(Search $query): array
    {
        return $this->engine->search($query->criteria);
    }
}
