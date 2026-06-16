<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\YouTubeProgress;

use Google\Client;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Pins the wired `google.client` service (built by GoogleClientFactory) to the
 * scope contract YouTubeProgress depends on: the same OAuth client must request
 * both the Calendar and the full-access YouTube scope, and force a consent
 * prompt so an existing Calendar-only user re-grants with the widened scope.
 */
final class GoogleClientYouTubeScopeTest extends KernelTestCase
{
    private Client $client;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->client = static::getContainer()->get('google.client');
    }

    public function testGoogleClientHasCalendarScope(): void
    {
        self::assertContains('https://www.googleapis.com/auth/calendar.events', $this->client->getScopes());
    }

    public function testGoogleClientHasYouTubeScope(): void
    {
        self::assertContains('https://www.googleapis.com/auth/youtube', $this->client->getScopes());
    }

    public function testGoogleClientPromptsConsentForReauthorization(): void
    {
        $authUrl = $this->client->createAuthUrl();

        self::assertStringContainsString('prompt=consent', $authUrl);
    }
}
