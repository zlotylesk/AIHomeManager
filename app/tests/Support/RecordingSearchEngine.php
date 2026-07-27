<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Domain\ReadModel\SearchFacet;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Domain\ValueObject\SearchResult;
use RuntimeException;

/**
 * A search engine that names itself in its answer and counts how often it was
 * asked (HMAI-365).
 *
 * Both facts are needed to tell a degrade apart from a healthy read: which
 * engine produced the results, and whether the other one was consulted at all.
 * A named class rather than an anonymous one so PHPStan keeps the counter
 * visible to the tests.
 */
final class RecordingSearchEngine implements SearchEngineInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly string $name = 'engine',
        private readonly bool $broken = false,
    ) {
    }

    public function search(SearchQuery $query): array
    {
        $this->record();

        return [new SearchResult(SearchResultType::BOOK, $this->name, 'Diuna', 'Fragment', '/books/1')];
    }

    public function facets(SearchQuery $query): array
    {
        $this->record();

        return [new SearchFacet(SearchResultType::BOOK, 1)];
    }

    private function record(): void
    {
        if ($this->broken) {
            throw new RuntimeException('The search engine is unreachable.');
        }

        ++$this->calls;
    }
}
