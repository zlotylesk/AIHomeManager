<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Search\Infrastructure;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Infrastructure\Engine\FallbackSearchEngine;
use App\Tests\Support\RecordingSearchEngine;
use App\Tests\Support\SpyLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * HMAI-365: an engine outage must cost relevance, not the feature.
 *
 * The rules under test are the ones deciding whether a user notices an
 * OpenSearch outage at all — and the one deciding whether an operator does.
 */
final class FallbackSearchEngineTest extends TestCase
{
    private SpyLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new SpyLogger();
    }

    public function testAHealthyPrimaryAnswersAndTheStandbyIsNeverAsked(): void
    {
        $standby = new RecordingSearchEngine('fulltext');
        $engine = new FallbackSearchEngine(new RecordingSearchEngine('opensearch'), $standby, $this->logger);

        $results = $engine->search(new SearchQuery('diuna'));

        self::assertSame('opensearch', $results[0]->id);
        // Falling back while the primary is fine would quietly downgrade every
        // query's relevance.
        self::assertSame(0, $standby->calls);
        self::assertSame([], $this->logger->records);
    }

    public function testAFailingPrimaryDegradesToTheStandbyInsteadOfThrowing(): void
    {
        $engine = new FallbackSearchEngine(
            new RecordingSearchEngine('opensearch', broken: true),
            new RecordingSearchEngine('fulltext'),
            $this->logger,
        );

        $results = $engine->search(new SearchQuery('diuna'));

        // The user gets results, not a 500.
        self::assertCount(1, $results);
        self::assertSame('fulltext', $results[0]->id);
    }

    public function testTheDegradeIsLoggedAsAWarningNamingTheOperation(): void
    {
        $engine = new FallbackSearchEngine(
            new RecordingSearchEngine('opensearch', broken: true),
            new RecordingSearchEngine('fulltext'),
            $this->logger,
        );

        $engine->search(new SearchQuery('diuna'));

        $record = $this->logger->findByMessage('Search degraded to the fallback engine.');
        self::assertNotNull($record);
        // Warning, not error — the user is being served. But a silent degrade
        // means running on the fallback for weeks without anyone noticing the
        // engine died.
        self::assertSame('warning', $record['level']);
        self::assertSame('search', $record['context']['operation']);
        self::assertSame('The search engine is unreachable.', $record['context']['message']);
    }

    public function testFacetsDegradeTheSameWay(): void
    {
        $engine = new FallbackSearchEngine(
            new RecordingSearchEngine('opensearch', broken: true),
            new RecordingSearchEngine('fulltext'),
            $this->logger,
        );

        $facets = $engine->facets(new SearchQuery('diuna'));

        self::assertSame(SearchResultType::BOOK, $facets[0]->type);
        $record = $this->logger->findByMessage('Search degraded to the fallback engine.');
        self::assertNotNull($record);
        self::assertSame('facets', $record['context']['operation']);
    }

    public function testATotalFailurePropagatesRatherThanReportingNoResults(): void
    {
        $engine = new FallbackSearchEngine(
            new RecordingSearchEngine('opensearch', broken: true),
            new RecordingSearchEngine('fulltext', broken: true),
            $this->logger,
        );

        // With both engines down, answering "nothing found" would be a lie about
        // the library's contents — worse than an error the operator can see.
        $this->expectException(RuntimeException::class);

        $engine->search(new SearchQuery('diuna'));
    }
}
