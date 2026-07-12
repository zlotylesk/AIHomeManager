<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Search\Infrastructure;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchableProviderInterface;
use App\Module\Search\Domain\ReadModel\SearchableDocument;
use App\Module\Search\Infrastructure\Provider\CompositeSearchableProvider;
use PHPUnit\Framework\TestCase;

final class CompositeSearchableProviderTest extends TestCase
{
    public function testConcatenatesDocumentsFromAllProvidersInOrder(): void
    {
        $first = $this->providerReturning([
            new SearchableDocument(SearchResultType::BOOK, 'b-1', 'Dune', 'Frank Herbert', '/books'),
        ]);
        $second = $this->providerReturning([
            new SearchableDocument(SearchResultType::SERIES, 's-1', 'Severance', '', '/series'),
            new SearchableDocument(SearchResultType::TASK, 't-1', 'Buy milk', '', '/tasks'),
        ]);

        $composite = new CompositeSearchableProvider([$first, $second]);

        $documents = $composite->documents();

        self::assertCount(3, $documents);
        self::assertSame(SearchResultType::BOOK, $documents[0]->type);
        self::assertSame('Dune', $documents[0]->title);
        self::assertSame(SearchResultType::SERIES, $documents[1]->type);
        self::assertSame(SearchResultType::TASK, $documents[2]->type);
    }

    public function testReturnsEmptyWhenNoProviders(): void
    {
        self::assertSame([], new CompositeSearchableProvider([])->documents());
    }

    /**
     * @param SearchableDocument[] $documents
     */
    private function providerReturning(array $documents): SearchableProviderInterface
    {
        return new readonly class($documents) implements SearchableProviderInterface {
            /** @param SearchableDocument[] $documents */
            public function __construct(private array $documents)
            {
            }

            public function documents(): array
            {
                return $this->documents;
            }
        };
    }
}
