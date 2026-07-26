<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Search\Infrastructure;

use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Infrastructure\Engine\SearchEngineFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * HMAI-361: the ES/FULLTEXT feature flag. The factory only selects, so the two
 * engines are plain doubles here — that the container hands it the right pair is
 * pinned by {@see \App\Tests\Integration\Search\SearchBackendFlagTest}.
 */
final class SearchEngineFactoryTest extends TestCase
{
    public function testSelectsTheFulltextEngine(): void
    {
        $fulltext = $this->createStub(SearchEngineInterface::class);
        $openSearch = $this->createStub(SearchEngineInterface::class);

        self::assertSame($fulltext, SearchEngineFactory::create('fulltext', $fulltext, $openSearch));
    }

    public function testSelectsTheOpenSearchEngine(): void
    {
        $fulltext = $this->createStub(SearchEngineInterface::class);
        $openSearch = $this->createStub(SearchEngineInterface::class);

        self::assertSame($openSearch, SearchEngineFactory::create('opensearch', $fulltext, $openSearch));
    }

    public function testAcceptsAValueWithStrayCaseAndWhitespace(): void
    {
        $fulltext = $this->createStub(SearchEngineInterface::class);
        $openSearch = $this->createStub(SearchEngineInterface::class);

        // An env value is hand-edited; "OpenSearch " must not read as a typo.
        self::assertSame($openSearch, SearchEngineFactory::create('  OpenSearch ', $fulltext, $openSearch));
    }

    public function testRejectsAnUnknownBackend(): void
    {
        $fulltext = $this->createStub(SearchEngineInterface::class);
        $openSearch = $this->createStub(SearchEngineInterface::class);

        $this->expectException(InvalidArgumentException::class);
        // The message must name the accepted values — the operator is mid-typo.
        $this->expectExceptionMessage('Unknown search backend "elastic". Set SEARCH_ENGINE_BACKEND to "fulltext" or "opensearch".');

        SearchEngineFactory::create('elastic', $fulltext, $openSearch);
    }
}
