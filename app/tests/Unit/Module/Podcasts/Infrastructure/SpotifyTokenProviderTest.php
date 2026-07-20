<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Podcasts\Infrastructure;

use App\Module\Podcasts\Infrastructure\External\SpotifyTokenProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(SpotifyTokenProvider::class)]
final class SpotifyTokenProviderTest extends TestCase
{
    public function testReturnsStoredTokenWhenStillValid(): void
    {
        $repository = $this->repositoryWith([
            'access_token' => 'still-good',
            'refresh_token' => 'r1',
            'expires_in' => 3600,
            'created_at' => time(),
        ]);

        $provider = $this->provider($repository, new MockHttpClient([]));

        self::assertSame('still-good', $provider->getValidAccessToken());
        self::assertSame([], $repository->saved, 'A valid token must not be rewritten.');
    }

    public function testReturnsNullWhenSpotifyWasNeverConnected(): void
    {
        $provider = $this->provider($this->repositoryWith(null), new MockHttpClient([]));

        self::assertNull($provider->getValidAccessToken());
    }

    public function testRefreshesAnExpiredToken(): void
    {
        $repository = $this->repositoryWith([
            'access_token' => 'stale',
            'refresh_token' => 'r1',
            'expires_in' => 3600,
            'created_at' => time() - 7200,
        ]);

        $client = new MockHttpClient([
            new MockResponse((string) json_encode([
                'access_token' => 'fresh',
                'refresh_token' => 'r2',
                'expires_in' => 3600,
            ])),
        ]);

        $provider = $this->provider($repository, $client);

        self::assertSame('fresh', $provider->getValidAccessToken());
        self::assertCount(1, $repository->saved);
        self::assertSame('fresh', $repository->saved[0]['access_token']);
        self::assertSame('r2', $repository->saved[0]['refresh_token']);
    }

    /**
     * Spotify rotates the refresh token only occasionally, so a refresh response
     * usually omits it. Replacing the stored payload wholesale would drop the one
     * credential able to refresh again — the integration would keep working until
     * the next expiry and then die, needing a manual re-authorization.
     */
    public function testKeepsThePreviousRefreshTokenWhenTheResponseOmitsIt(): void
    {
        $repository = $this->repositoryWith([
            'access_token' => 'stale',
            'refresh_token' => 'the-only-one-we-have',
            'expires_in' => 3600,
            'created_at' => time() - 7200,
        ]);

        $client = new MockHttpClient([
            new MockResponse((string) json_encode([
                'access_token' => 'fresh',
                'expires_in' => 3600,
            ])),
        ]);

        $provider = $this->provider($repository, $client);

        self::assertSame('fresh', $provider->getValidAccessToken());
        self::assertSame('the-only-one-we-have', $repository->saved[0]['refresh_token']);
    }

    public function testStampsCreatedAtSoTheNextExpiryCheckCanBeComputed(): void
    {
        $repository = $this->repositoryWith([
            'access_token' => 'stale',
            'refresh_token' => 'r1',
            'expires_in' => 3600,
            'created_at' => time() - 7200,
        ]);

        $client = new MockHttpClient([
            new MockResponse((string) json_encode(['access_token' => 'fresh', 'expires_in' => 3600])),
        ]);

        $before = time();
        $this->provider($repository, $client)->getValidAccessToken();

        self::assertGreaterThanOrEqual($before, $repository->saved[0]['created_at']);
    }

    public function testReturnsNullWhenTheRefreshIsRejected(): void
    {
        $repository = $this->repositoryWith([
            'access_token' => 'stale',
            'refresh_token' => 'revoked',
            'expires_in' => 3600,
            'created_at' => time() - 7200,
        ]);

        $client = new MockHttpClient([new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400])]);

        self::assertNull($this->provider($repository, $client)->getValidAccessToken());
        self::assertSame([], $repository->saved, 'A rejected refresh must not overwrite the stored token.');
    }

    public function testReturnsNullWhenAnExpiredTokenHasNoRefreshToken(): void
    {
        $repository = $this->repositoryWith([
            'access_token' => 'stale',
            'expires_in' => 3600,
            'created_at' => time() - 7200,
        ]);

        self::assertNull($this->provider($repository, new MockHttpClient([]))->getValidAccessToken());
    }

    /**
     * @param array<string, mixed>|null $stored
     */
    private function repositoryWith(?array $stored): InMemorySpotifyTokenRepository
    {
        return new InMemorySpotifyTokenRepository($stored);
    }

    private function provider(
        InMemorySpotifyTokenRepository $repository,
        MockHttpClient $client,
    ): SpotifyTokenProvider {
        return new SpotifyTokenProvider($repository, $client, 'client-id', 'client-secret');
    }
}
