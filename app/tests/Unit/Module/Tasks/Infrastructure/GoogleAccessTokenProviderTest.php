<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Tasks\Infrastructure;

use App\Module\Tasks\Infrastructure\Google\GoogleAccessTokenProvider;
use App\Module\Tasks\Infrastructure\Persistence\GoogleTokenRepositoryInterface;
use Google\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Refresh-on-expiry logic moved 1:1 out of
 * GoogleCalendarService::prepareAuthenticatedClient() (HMAI-399) — these
 * scenarios used to live in GoogleCalendarServiceTest.
 */
final class GoogleAccessTokenProviderTest extends TestCase
{
    public function testReturnsNullWhenNoTokenStored(): void
    {
        $tokenRepo = $this->createMock(GoogleTokenRepositoryInterface::class);
        $tokenRepo->method('get')->willReturn(null);
        $tokenRepo->expects(self::never())->method('save');

        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('setAccessToken');

        $provider = new GoogleAccessTokenProvider($client, $tokenRepo, $this->logger());

        self::assertNull($provider->getValidAccessToken());
    }

    public function testLogsWarningWhenNoTokenStored(): void
    {
        $tokenRepo = $this->createStub(GoogleTokenRepositoryInterface::class);
        $tokenRepo->method('get')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $provider = new GoogleAccessTokenProvider($this->createStub(Client::class), $tokenRepo, $logger);
        $provider->getValidAccessToken();
    }

    public function testReturnsAccessTokenWithoutRefreshWhenStillValid(): void
    {
        $tokenRepo = $this->createMock(GoogleTokenRepositoryInterface::class);
        $tokenRepo->method('get')->willReturn(['access_token' => 'still-valid', 'refresh_token' => 'r']);
        $tokenRepo->expects(self::never())->method('save');

        $client = $this->createStub(Client::class);
        $client->method('isAccessTokenExpired')->willReturn(false);
        $client->method('getAccessToken')->willReturn(['access_token' => 'still-valid']);

        $provider = new GoogleAccessTokenProvider($client, $tokenRepo, $this->logger());

        self::assertSame('still-valid', $provider->getValidAccessToken());
    }

    public function testRefreshesExpiredTokenAndPersistsNewToken(): void
    {
        $newToken = ['access_token' => 'new-tok', 'refresh_token' => 'old-refresh', 'expires_in' => 3600];

        $tokenRepo = $this->createMock(GoogleTokenRepositoryInterface::class);
        $tokenRepo->method('get')->willReturn(['access_token' => 'expired', 'refresh_token' => 'old-refresh']);
        $tokenRepo->expects(self::once())->method('save')->with($newToken);

        $client = $this->createMock(Client::class);
        $client->method('isAccessTokenExpired')->willReturn(true);
        $client->method('getRefreshToken')->willReturn('old-refresh');
        $client->expects(self::once())
            ->method('fetchAccessTokenWithRefreshToken')
            ->with('old-refresh')
            ->willReturn($newToken);
        $client->method('getAccessToken')->willReturn($newToken);

        $provider = new GoogleAccessTokenProvider($client, $tokenRepo, $this->logger());

        self::assertSame('new-tok', $provider->getValidAccessToken());
    }

    public function testReturnsNullWhenExpiredWithoutRefreshToken(): void
    {
        $tokenRepo = $this->createMock(GoogleTokenRepositoryInterface::class);
        $tokenRepo->method('get')->willReturn(['access_token' => 'expired']);
        $tokenRepo->expects(self::never())->method('save');

        $client = $this->createStub(Client::class);
        $client->method('isAccessTokenExpired')->willReturn(true);
        $client->method('getRefreshToken')->willReturn(null);

        $provider = new GoogleAccessTokenProvider($client, $tokenRepo, $this->logger());

        self::assertNull($provider->getValidAccessToken());
    }

    public function testLogsWarningWhenRefreshTokenMissing(): void
    {
        $tokenRepo = $this->createStub(GoogleTokenRepositoryInterface::class);
        $tokenRepo->method('get')->willReturn(['access_token' => 'expired']);

        $client = $this->createStub(Client::class);
        $client->method('isAccessTokenExpired')->willReturn(true);
        $client->method('getRefreshToken')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::stringContains('refresh token missing')
        );

        $provider = new GoogleAccessTokenProvider($client, $tokenRepo, $logger);
        $provider->getValidAccessToken();
    }

    public function testReturnsNullWhenRefreshFails(): void
    {
        $tokenRepo = $this->createMock(GoogleTokenRepositoryInterface::class);
        $tokenRepo->method('get')->willReturn(['access_token' => 'expired', 'refresh_token' => 'revoked-refresh']);
        $tokenRepo->expects(self::never())->method('save');

        $provider = new GoogleAccessTokenProvider($this->clientWithFailedRefresh(), $tokenRepo, $this->logger());

        self::assertNull($provider->getValidAccessToken());
    }

    public function testDoesNotSaveCorruptedTokenWhenRefreshFails(): void
    {
        $tokenRepo = $this->createMock(GoogleTokenRepositoryInterface::class);
        $tokenRepo->method('get')->willReturn(['access_token' => 'expired', 'refresh_token' => 'revoked-refresh']);
        $tokenRepo->expects(self::never())->method('save');

        $provider = new GoogleAccessTokenProvider($this->clientWithFailedRefresh(), $tokenRepo, $this->logger());
        $provider->getValidAccessToken();
    }

    public function testLogsWarningWhenRefreshFails(): void
    {
        $tokenRepo = $this->createStub(GoogleTokenRepositoryInterface::class);
        $tokenRepo->method('get')->willReturn(['access_token' => 'expired', 'refresh_token' => 'revoked-refresh']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Google OAuth: token refresh failed, re-authentication required',
            self::callback(static fn (array $ctx) => 'invalid_grant' === $ctx['error'])
        );

        $provider = new GoogleAccessTokenProvider($this->clientWithFailedRefresh(), $tokenRepo, $logger);
        $provider->getValidAccessToken();
    }

    /**
     * Pre-configured Google Client stub for the "refresh-token-revoked" scenario:
     * token is reported expired and fetchAccessTokenWithRefreshToken returns
     * Google's standard error-shaped response instead of a fresh token.
     */
    private function clientWithFailedRefresh(): Client
    {
        $client = $this->createStub(Client::class);
        $client->method('isAccessTokenExpired')->willReturn(true);
        $client->method('getRefreshToken')->willReturn('revoked-refresh');
        $client->method('fetchAccessTokenWithRefreshToken')->willReturn([
            'error' => 'invalid_grant',
            'error_description' => 'Token has been expired or revoked.',
        ]);

        return $client;
    }

    private function logger(): LoggerInterface
    {
        return $this->createStub(LoggerInterface::class);
    }
}
