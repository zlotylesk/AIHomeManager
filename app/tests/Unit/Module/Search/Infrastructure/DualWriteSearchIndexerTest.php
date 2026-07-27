<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Search\Infrastructure;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\ReadModel\SearchableDocument;
use App\Module\Search\Infrastructure\Index\DualWriteSearchIndexer;
use App\Tests\Support\RecordingSearchIndexer;
use App\Tests\Support\SpyLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * HMAI-365: the standby index has to stay current, or the fallback is a fallback
 * in name only.
 *
 * The asymmetry is the point of these tests: the primary is the index being
 * read, so its failures propagate and Messenger retries them; the standby is
 * insurance, so its failures are logged and swallowed rather than costing the
 * write that was actually being served.
 */
final class DualWriteSearchIndexerTest extends TestCase
{
    private SpyLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new SpyLogger();
    }

    public function testEveryOperationReachesBothIndexes(): void
    {
        $primary = new RecordingSearchIndexer();
        $standby = new RecordingSearchIndexer();
        $indexer = new DualWriteSearchIndexer($primary, $standby, $this->logger);

        $indexer->reindex();
        $indexer->index($this->document());
        $indexer->remove(SearchResultType::BOOK, '42');

        self::assertSame(1, $primary->reindexed);
        self::assertSame(1, $standby->reindexed);
        self::assertSame(['book:7'], $primary->indexed);
        self::assertSame(['book:7'], $standby->indexed);
        self::assertSame(['book:42'], $primary->removed);
        // A deletion missed by the standby is the worst kind of drift: the
        // fallback would keep offering an entity that no longer exists.
        self::assertSame(['book:42'], $standby->removed);
    }

    public function testTheCountComesFromThePrimary(): void
    {
        $indexer = new DualWriteSearchIndexer(
            new RecordingSearchIndexer(documentCount: 120),
            new RecordingSearchIndexer(documentCount: 3),
            $this->logger,
        );

        // The operator running `app:search:populate` is asking about the index
        // that answers reads.
        self::assertSame(120, $indexer->reindex());
    }

    public function testAFailingStandbyIsLoggedButDoesNotCostTheWrite(): void
    {
        $primary = new RecordingSearchIndexer();
        $indexer = new DualWriteSearchIndexer($primary, new RecordingSearchIndexer(broken: true), $this->logger);

        $indexer->index($this->document());

        // Losing the insurance must not also lose the write being served — and
        // the 15-minute rebuild re-attempts the standby anyway.
        self::assertSame(['book:7'], $primary->indexed);
        $record = $this->logger->findByMessage('Failed to update the standby search index.');
        self::assertNotNull($record);
        self::assertSame('warning', $record['level']);
        self::assertSame('index', $record['context']['operation']);
    }

    public function testAFailingPrimaryPropagatesSoMessengerRetries(): void
    {
        $standby = new RecordingSearchIndexer();
        $indexer = new DualWriteSearchIndexer(new RecordingSearchIndexer(broken: true), $standby, $this->logger);

        try {
            $indexer->index($this->document());
            self::fail('A failed write to the index being read must not be swallowed.');
        } catch (RuntimeException) {
            // Expected: the caller is a Messenger handler, and retrying is
            // exactly the right response.
        }

        // The standby is not written behind a failed primary — the two indexes
        // would drift, and the retry re-runs the pair anyway.
        self::assertSame([], $standby->indexed);
    }

    private function document(): SearchableDocument
    {
        return new SearchableDocument(SearchResultType::BOOK, '7', 'Diuna', 'Frank Herbert', '/books/7');
    }
}
