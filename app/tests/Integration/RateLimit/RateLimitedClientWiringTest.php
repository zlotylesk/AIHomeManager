<?php

declare(strict_types=1);

namespace App\Tests\Integration\RateLimit;

use App\Http\RateLimitedHttpClient;
use App\Module\Books\Infrastructure\External\NationalLibraryApiClient;
use App\Module\Music\Infrastructure\External\DiscogsApiClient;
use App\Module\Music\Infrastructure\External\LastFmApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guards against regressions in services.yaml where an external API client could end up wired
 * to the bare http_client instead of its rate-limited decorator — a silent regression that
 * would only surface when a third-party API starts banning the public IP.
 */
class RateLimitedClientWiringTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function externalApiClientProvider(): iterable
    {
        yield 'Discogs' => [DiscogsApiClient::class];
        yield 'Last.fm' => [LastFmApiClient::class];
        yield 'National Library' => [NationalLibraryApiClient::class];
    }

    /**
     * @param class-string $clientClass
     */
    #[DataProvider('externalApiClientProvider')]
    public function testExternalClientUsesRateLimitedHttpClient(string $clientClass): void
    {
        self::bootKernel();
        $client = static::getContainer()->get($clientClass);

        $reflection = new ReflectionClass($client);
        $property = $reflection->getProperty('httpClient');
        $injected = $property->getValue($client);

        self::assertInstanceOf(
            RateLimitedHttpClient::class,
            $injected,
            sprintf('%s must be decorated with RateLimitedHttpClient — wiring regression?', $clientClass),
        );
    }
}
