<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Search\Infrastructure;

use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Infrastructure\Engine\FallbackSearchEngine;
use App\Module\Search\Infrastructure\Engine\SearchEngineFactory;
use App\Tests\Support\RecordingSearchEngine;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * HMAI-361: the ES/FULLTEXT feature flag. The factory only selects, so the two
 * engines are plain doubles here — that the container hands it the right pair is
 * pinned by {@see \App\Tests\Integration\Search\SearchBackendFlagTest}.
 *
 * HMAI-365 widened the OpenSearch branch: it now returns the engine wrapped in a
 * fallback to FULLTEXT. Which engine ended up on which side is asserted through
 * behaviour rather than reflection — a wrapper built the wrong way round would
 * still be an instance of the right class.
 */
final class SearchEngineFactoryTest extends TestCase
{
    public function testSelectsTheFulltextEngine(): void
    {
        $fulltext = $this->createStub(SearchEngineInterface::class);
        $openSearch = $this->createStub(SearchEngineInterface::class);

        // Bare, not wrapped: a fallback from FULLTEXT to FULLTEXT is a layer
        // that can never fire.
        self::assertSame($fulltext, SearchEngineFactory::create('fulltext', $fulltext, $openSearch, new NullLogger()));
    }

    public function testSelectsTheOpenSearchEngineBehindAFallback(): void
    {
        $fulltext = $this->createStub(SearchEngineInterface::class);
        $openSearch = $this->createStub(SearchEngineInterface::class);

        self::assertInstanceOf(
            FallbackSearchEngine::class,
            SearchEngineFactory::create('opensearch', $fulltext, $openSearch, new NullLogger()),
        );
    }

    public function testTheOpenSearchEngineAnswersAndFulltextIsOnlyTheStandby(): void
    {
        $engine = SearchEngineFactory::create(
            'opensearch',
            new RecordingSearchEngine('fulltext'),
            new RecordingSearchEngine('opensearch'),
            new NullLogger(),
        );

        // The better engine is the one being read; FULLTEXT must not silently
        // take over while OpenSearch is healthy.
        self::assertSame('opensearch', $engine->search(new SearchQuery('cokolwiek'))[0]->id);
    }

    public function testAFailingOpenSearchEngineDegradesToFulltext(): void
    {
        $engine = SearchEngineFactory::create(
            'opensearch',
            new RecordingSearchEngine('fulltext'),
            new RecordingSearchEngine('opensearch', broken: true),
            new NullLogger(),
        );

        // Proves the pair reached the wrapper in the right order: reversed, this
        // query would throw instead of degrading.
        self::assertSame('fulltext', $engine->search(new SearchQuery('cokolwiek'))[0]->id);
    }

    public function testAcceptsAValueWithStrayCaseAndWhitespace(): void
    {
        $fulltext = $this->createStub(SearchEngineInterface::class);
        $openSearch = $this->createStub(SearchEngineInterface::class);

        // An env value is hand-edited; "OpenSearch " must not read as a typo.
        self::assertInstanceOf(
            FallbackSearchEngine::class,
            SearchEngineFactory::create('  OpenSearch ', $fulltext, $openSearch, new NullLogger()),
        );
    }

    public function testRejectsAnUnknownBackend(): void
    {
        $fulltext = $this->createStub(SearchEngineInterface::class);
        $openSearch = $this->createStub(SearchEngineInterface::class);

        $this->expectException(InvalidArgumentException::class);
        // The message must name the accepted values — the operator is mid-typo.
        $this->expectExceptionMessage('Unknown search backend "elastic". Set SEARCH_ENGINE_BACKEND to "fulltext" or "opensearch".');

        SearchEngineFactory::create('elastic', $fulltext, $openSearch, new NullLogger());
    }
}
