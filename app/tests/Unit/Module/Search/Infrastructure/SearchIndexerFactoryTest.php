<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Search\Infrastructure;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchIndexerInterface;
use App\Module\Search\Domain\ReadModel\SearchableDocument;
use App\Module\Search\Infrastructure\Index\SearchIndexerFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * HMAI-363: the index writer must follow the same flag as the reader.
 *
 * A mismatch is the nastiest failure this epic can produce — search would answer
 * from an index nothing maintains, which looks exactly like "you have no data"
 * rather than like a misconfiguration.
 */
final class SearchIndexerFactoryTest extends TestCase
{
    private SearchIndexerInterface $fulltext;
    private SearchIndexerInterface $openSearch;

    protected function setUp(): void
    {
        // Distinct anonymous classes: mocks of one interface share a class name,
        // so identity comparison could not tell the two branches apart.
        $this->fulltext = $this->indexerDouble();
        $this->openSearch = $this->indexerDouble();
    }

    public function testFulltextIsSelected(): void
    {
        self::assertSame($this->fulltext, SearchIndexerFactory::create('fulltext', $this->fulltext, $this->openSearch));
    }

    public function testOpenSearchIsSelected(): void
    {
        self::assertSame($this->openSearch, SearchIndexerFactory::create('opensearch', $this->fulltext, $this->openSearch));
    }

    public function testCaseAndWhitespaceAreNormalised(): void
    {
        // The value is hand-edited in an env file.
        self::assertSame($this->openSearch, SearchIndexerFactory::create('  OpenSearch ', $this->fulltext, $this->openSearch));
    }

    public function testAnUnknownBackendIsRejectedRatherThanDefaulted(): void
    {
        // Silently falling back would leave the operator believing they switched
        // backends while nothing changed.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown search backend "opensarch"');

        SearchIndexerFactory::create('opensarch', $this->fulltext, $this->openSearch);
    }

    private function indexerDouble(): SearchIndexerInterface
    {
        return new class implements SearchIndexerInterface {
            public function reindex(): int
            {
                return 0;
            }

            public function index(SearchableDocument $document): void
            {
            }

            public function remove(SearchResultType $type, string $id): void
            {
            }
        };
    }
}
