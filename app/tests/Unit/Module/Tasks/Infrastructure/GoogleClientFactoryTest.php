<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Tasks\Infrastructure;

use App\Module\Tasks\Infrastructure\Google\GoogleClientFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GoogleClientFactoryTest extends TestCase
{
    public function testCreatesClientWhenAllParamsAreValid(): void
    {
        $factory = new GoogleClientFactory('client-id', 'client-secret', 'https://example.com/auth/google/callback');

        $client = $factory->create();

        self::assertSame('client-id', $client->getClientId());

        self::assertContains('https://www.googleapis.com/auth/calendar.events', $client->getScopes());
    }

    public function testWiresYouTubeScopeSoOneTokenServesCalendarAndYouTube(): void
    {
        $factory = new GoogleClientFactory('client-id', 'client-secret', 'https://example.com/auth/google/callback');

        $client = $factory->create();

        self::assertContains('https://www.googleapis.com/auth/youtube', $client->getScopes());
    }

    public function testPropagatesClientSecretAndRedirectUriToClient(): void
    {
        $factory = new GoogleClientFactory('client-id', 'client-secret', 'https://example.com/auth/google/callback');

        $client = $factory->create();

        self::assertSame('client-secret', $client->getClientSecret());
        self::assertSame('https://example.com/auth/google/callback', $client->getRedirectUri());
    }

    public function testRequestsOfflineAccessWithConsentPromptSoRefreshTokenIsIssued(): void
    {
        $factory = new GoogleClientFactory('client-id', 'client-secret', 'https://example.com/auth/google/callback');

        $authUrl = $factory->create()->createAuthUrl();

        self::assertStringContainsString('access_type=offline', $authUrl);
        self::assertStringContainsString('prompt=consent', $authUrl);
    }

    public function testThrowsWhenClientSecretIsWhitespaceOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GOOGLE_OAUTH_CLIENT_SECRET is empty');

        new GoogleClientFactory('client-id', "  \t", 'https://example.com/callback');
    }

    public function testThrowsWhenClientIdIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GOOGLE_OAUTH_CLIENT_ID is empty');

        new GoogleClientFactory('', 'secret', 'https://example.com/callback');
    }

    public function testThrowsWhenClientIdIsWhitespaceOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GOOGLE_OAUTH_CLIENT_ID is empty');

        new GoogleClientFactory("  \t", 'secret', 'https://example.com/callback');
    }

    public function testThrowsWhenClientSecretIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GOOGLE_OAUTH_CLIENT_SECRET is empty');

        new GoogleClientFactory('client-id', '', 'https://example.com/callback');
    }

    public function testThrowsWhenRedirectUriIsMalformed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GOOGLE_OAUTH_REDIRECT_URI is not a valid URL');

        new GoogleClientFactory('client-id', 'secret', 'not-a-url');
    }

    public function testThrowsWhenRedirectUriIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GOOGLE_OAUTH_REDIRECT_URI is not a valid URL');

        new GoogleClientFactory('client-id', 'secret', '');
    }
}
