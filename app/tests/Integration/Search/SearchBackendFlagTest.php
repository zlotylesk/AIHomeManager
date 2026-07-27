<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Domain\Port\SearchIndexerInterface;
use App\Module\Search\Infrastructure\Cache\CachingSearchEngine;
use App\Module\Search\Infrastructure\Engine\FallbackSearchEngine;
use App\Module\Search\Infrastructure\Engine\FulltextSearchEngine;
use App\Module\Search\Infrastructure\Engine\OpenSearchEngine;
use App\Module\Search\Infrastructure\Index\DualWriteSearchIndexer;
use App\Module\Search\Infrastructure\Index\OpenSearchIndexer;
use App\Module\Search\Infrastructure\Index\SearchIndexer;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * HMAI-361: the ES/FULLTEXT feature flag as the container actually resolves it.
 *
 * {@see \App\Tests\Unit\Module\Search\Infrastructure\SearchEngineFactoryTest}
 * proves the selection rule; this proves the wiring behind it — that the flag
 * reaches the factory, that the two engines are not swapped in `services.yaml`,
 * and that the cache decorator still fronts whichever backend is active.
 */
final class SearchBackendFlagTest extends KernelTestCase
{
    private const string FLAG = 'SEARCH_ENGINE_BACKEND';

    private ?string $originalEnv = null;
    private ?string $originalServer = null;

    protected function setUp(): void
    {
        // Dotenv populates both superglobals, and Symfony reads $_ENV first and
        // $_SERVER second — so both have to move for a test to control the flag.
        $env = $_ENV[self::FLAG] ?? null;
        $server = $_SERVER[self::FLAG] ?? null;
        $this->originalEnv = is_string($env) ? $env : null;
        $this->originalServer = is_string($server) ? $server : null;
    }

    protected function tearDown(): void
    {
        $this->putFlag($this->originalEnv);
        if (null === $this->originalServer) {
            unset($_SERVER[self::FLAG]);
        } else {
            $_SERVER[self::FLAG] = $this->originalServer;
        }

        parent::tearDown();
    }

    public function testFulltextBacksThePortByDefault(): void
    {
        // Unset, not "fulltext": the fallback is what a deployment that never
        // heard of the flag gets, and it must stay the proven backend.
        $this->putFlag(null);
        unset($_SERVER[self::FLAG]);

        self::assertInstanceOf(FulltextSearchEngine::class, $this->activeBackend());
    }

    public function testFlagSwitchesThePortToOpenSearchBehindAFallback(): void
    {
        $this->putFlag('opensearch');

        $backend = $this->activeBackend();

        // HMAI-365: OpenSearch is never wired alone — the better engine must not
        // become a single point of failure.
        self::assertInstanceOf(FallbackSearchEngine::class, $backend);
        self::assertInstanceOf(OpenSearchEngine::class, $this->innerEngine($backend, 'primary'));
        // The pair, not just the wrapper: reversed in `services.yaml`, every
        // query would silently be answered by FULLTEXT.
        self::assertInstanceOf(FulltextSearchEngine::class, $this->innerEngine($backend, 'standby'));
    }

    public function testTheFulltextIndexIsStillWrittenWhileOpenSearchIsTheBackend(): void
    {
        $this->putFlag('opensearch');
        static::ensureKernelShutdown();
        self::bootKernel();

        $indexer = static::getContainer()->get(SearchIndexerInterface::class);

        // Without the dual write the `search_documents` table would freeze the
        // day the flag was flipped, and the fallback above would serve a stale
        // library during an outage — plausible, wrong, and hard to notice.
        self::assertInstanceOf(DualWriteSearchIndexer::class, $indexer);
        $primary = new ReflectionProperty(DualWriteSearchIndexer::class, 'primary')->getValue($indexer);
        $standby = new ReflectionProperty(DualWriteSearchIndexer::class, 'standby')->getValue($indexer);
        self::assertInstanceOf(OpenSearchIndexer::class, $primary);
        self::assertInstanceOf(SearchIndexer::class, $standby);
    }

    public function testTheFulltextBackendWritesOnlyTheFulltextIndex(): void
    {
        $this->putFlag(null);
        unset($_SERVER[self::FLAG]);
        static::ensureKernelShutdown();
        self::bootKernel();

        // Nothing to keep warm: FULLTEXT is both the reader and the standby, so
        // a dual write here would be an OpenSearch dependency on a box that
        // deliberately runs without one.
        self::assertInstanceOf(SearchIndexer::class, static::getContainer()->get(SearchIndexerInterface::class));
    }

    private function putFlag(?string $value): void
    {
        if (null === $value) {
            unset($_ENV[self::FLAG]);

            return;
        }

        $_ENV[self::FLAG] = $value;
    }

    /**
     * The port resolves to the Redis cache decorator either way (HMAI-271) — the
     * backend is the engine it wraps.
     */
    private function activeBackend(): SearchEngineInterface
    {
        static::ensureKernelShutdown();
        self::bootKernel();

        $port = static::getContainer()->get(SearchEngineInterface::class);

        // Reading the decorator's own property is the assertion that it still
        // fronts the flag: were the port re-wired to something else, there
        // would be no `engine` to read and this would fail loudly.
        $engine = new ReflectionProperty(CachingSearchEngine::class, 'engine')->getValue($port);
        self::assertInstanceOf(SearchEngineInterface::class, $engine);

        return $engine;
    }

    /**
     * @param 'primary'|'standby' $side
     */
    private function innerEngine(FallbackSearchEngine $fallback, string $side): SearchEngineInterface
    {
        $engine = new ReflectionProperty(FallbackSearchEngine::class, $side)->getValue($fallback);
        self::assertInstanceOf(SearchEngineInterface::class, $engine);

        return $engine;
    }
}
