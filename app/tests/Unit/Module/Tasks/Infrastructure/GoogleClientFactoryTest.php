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
        // Calendar scope must be wired so the OAuth consent screen requests it.
        self::assertContains('https://www.googleapis.com/auth/calendar.events', $client->getScopes());
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
        // access_type=offline + prompt=consent are required for Google to issue a
        // refresh token on first authorization. Verify via the generated auth URL,
        // which is the only publicly observable surface for these settings.
        $factory = new GoogleClientFactory('client-id', 'client-secret', 'https://example.com/auth/google/callback');

        $authUrl = $factory->create()->createAuthUrl();

        self::assertStringContainsString('access_type=offline', $authUrl);
        self::assertStringContainsString('prompt=consent', $authUrl);
    }

    public function testThrowsWhenClientSecretIsWhitespaceOnly(): void
    {
        // Mirror of the clientId whitespace guard — both are equally easy to
        // mis-paste into an .env file.
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
        // Catches a common misconfig: GOOGLE_OAUTH_CLIENT_ID=" " after a copy-paste.
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
        // Empty string fails FILTER_VALIDATE_URL, so it's caught by the URL check
        // rather than needing a separate empty guard.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GOOGLE_OAUTH_REDIRECT_URI is not a valid URL');

        new GoogleClientFactory('client-id', 'secret', '');
    }
}
