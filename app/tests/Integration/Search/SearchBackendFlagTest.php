<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Infrastructure\Cache\CachingSearchEngine;
use App\Module\Search\Infrastructure\Engine\FulltextSearchEngine;
use App\Module\Search\Infrastructure\Engine\OpenSearchEngine;
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

    public function testFlagSwitchesThePortToOpenSearch(): void
    {
        $this->putFlag('opensearch');

        self::assertInstanceOf(OpenSearchEngine::class, $this->activeBackend());
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
}
