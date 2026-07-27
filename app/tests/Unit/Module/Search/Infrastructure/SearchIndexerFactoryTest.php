<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Search\Infrastructure;

use App\Module\Search\Infrastructure\Index\DualWriteSearchIndexer;
use App\Module\Search\Infrastructure\Index\SearchIndexerFactory;
use App\Tests\Support\RecordingSearchIndexer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * HMAI-363: the index writer must follow the same flag as the reader.
 *
 * A mismatch is the nastiest failure this epic can produce — search would answer
 * from an index nothing maintains, which looks exactly like "you have no data"
 * rather than like a misconfiguration.
 *
 * HMAI-365 turned the OpenSearch branch into a dual write for the same reason
 * one level down: the read side degrades to FULLTEXT, so FULLTEXT has to keep
 * being written even while OpenSearch is the backend being read.
 */
final class SearchIndexerFactoryTest extends TestCase
{
    private RecordingSearchIndexer $fulltext;
    private RecordingSearchIndexer $openSearch;

    protected function setUp(): void
    {
        $this->fulltext = new RecordingSearchIndexer();
        $this->openSearch = new RecordingSearchIndexer();
    }

    public function testFulltextIsSelected(): void
    {
        // Bare, not wrapped: FULLTEXT is both the reader and the standby, so
        // there is no second index to keep warm.
        self::assertSame(
            $this->fulltext,
            SearchIndexerFactory::create('fulltext', $this->fulltext, $this->openSearch, new NullLogger()),
        );
    }

    public function testOpenSearchIsSelectedAsADualWrite(): void
    {
        $indexer = SearchIndexerFactory::create('opensearch', $this->fulltext, $this->openSearch, new NullLogger());

        self::assertInstanceOf(DualWriteSearchIndexer::class, $indexer);
    }

    public function testTheDualWriteKeepsBothIndexesCurrent(): void
    {
        $indexer = SearchIndexerFactory::create('opensearch', $this->fulltext, $this->openSearch, new NullLogger());

        $indexer->reindex();

        // Behavioural, not reflective: this is the whole point of the branch —
        // a wrapper that only wrote OpenSearch would leave the fallback serving
        // whatever the table held the day the flag was flipped.
        self::assertSame(1, $this->openSearch->reindexed);
        self::assertSame(1, $this->fulltext->reindexed);
    }

    public function testCaseAndWhitespaceAreNormalised(): void
    {
        // The value is hand-edited in an env file.
        self::assertInstanceOf(
            DualWriteSearchIndexer::class,
            SearchIndexerFactory::create('  OpenSearch ', $this->fulltext, $this->openSearch, new NullLogger()),
        );
    }

    public function testAnUnknownBackendIsRejectedRatherThanDefaulted(): void
    {
        // Silently falling back would leave the operator believing they switched
        // backends while nothing changed.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown search backend "opensarch"');

        SearchIndexerFactory::create('opensarch', $this->fulltext, $this->openSearch, new NullLogger());
    }
}
