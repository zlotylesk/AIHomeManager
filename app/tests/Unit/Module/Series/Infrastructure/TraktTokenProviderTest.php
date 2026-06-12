<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Series\Infrastructure;

use App\Module\Series\Infrastructure\External\TraktTokenProvider;
use App\Module\Series\Infrastructure\Persistence\TraktTokenRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TraktTokenProviderTest extends TestCase
{
    public function testReturnsNullWhenNoTokenStored(): void
    {
        $repository = $this->createMock(TraktTokenRepositoryInterface::class);
        $repository->method('get')->willReturn(null);
        $repository->expects(self::never())->method('save');

        $provider = $this->provider($repository, new MockHttpClient([]));

        self::assertNull($provider->getValidAccessToken());
    }

    public function testReturnsStoredTokenWhenStillValid(): void
    {
        $repository = $this->createMock(TraktTokenRepositoryInterface::class);
        $repository->method('get')->willReturn([
            'access_token' => 'still-valid',
            'refresh_token' => 'r',
            'created_at' => time(),
            'expires_in' => 7776000,
        ]);
        // No refresh, no persistence when the token is fresh.
        $repository->expects(self::never())->method('save');

        // An empty MockHttpClient throws if a request is attempted — proving no
        // refresh call was made.
        $provider = $this->provider($repository, new MockHttpClient([]));

        self::assertSame('still-valid', $provider->getValidAccessToken());
    }

    public function testRefreshesAndPersistsWhenExpired(): void
    {
        $repository = $this->createMock(TraktTokenRepositoryInterface::class);
        $repository->method('get')->willReturn([
            'access_token' => 'expired',
            'refresh_token' => 'refresh-me',
            'created_at' => time() - 10_000_000,
            'expires_in' => 7776000,
        ]);

        $newToken = [
            'access_token' => 'fresh-token',
            'refresh_token' => 'fresh-refresh',
            'created_at' => time(),
            'expires_in' => 7776000,
        ];
        $repository->expects(self::once())->method('save')->with($newToken);

        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($newToken, JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $provider = $this->provider($repository, $httpClient);

        self::assertSame('fresh-token', $provider->getValidAccessToken());
    }

    public function testReturnsNullWhenExpiredAndRefreshFails(): void
    {
        $repository = $this->createMock(TraktTokenRepositoryInterface::class);
        $repository->method('get')->willReturn([
            'access_token' => 'expired',
            'refresh_token' => 'refresh-me',
            'created_at' => time() - 10_000_000,
            'expires_in' => 7776000,
        ]);
        $repository->expects(self::never())->method('save');

        $httpClient = new MockHttpClient([
            new MockResponse('{"error":"invalid_grant"}', ['http_code' => 401]),
        ]);

        $provider = $this->provider($repository, $httpClient);

        self::assertNull($provider->getValidAccessToken());
    }

    public function testReturnsNullWhenExpiredWithoutRefreshToken(): void
    {
        $repository = $this->createMock(TraktTokenRepositoryInterface::class);
        $repository->method('get')->willReturn([
            'access_token' => 'expired',
            'created_at' => time() - 10_000_000,
            'expires_in' => 7776000,
        ]);
        $repository->expects(self::never())->method('save');

        $provider = $this->provider($repository, new MockHttpClient([]));

        self::assertNull($provider->getValidAccessToken());
    }

    private function provider(TraktTokenRepositoryInterface $repository, MockHttpClient $httpClient): TraktTokenProvider
    {
        return new TraktTokenProvider(
            $repository,
            $httpClient,
            'client-id',
            'client-secret',
            'https://localhost/auth/trakt/callback',
        );
    }
}
