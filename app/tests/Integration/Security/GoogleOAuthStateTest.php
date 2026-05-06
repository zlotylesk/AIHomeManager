<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GoogleOAuthStateTest extends WebTestCase
{
    private const string SESSION_STATE_KEY = 'google_oauth_state';

    public function testAuthorizeStoresHexStateInSession(): void
    {
        $client = static::createClient();
        $client->request('GET', '/auth/google');

        self::assertSame(302, $client->getResponse()->getStatusCode());

        $session = $client->getRequest()->getSession();
        $state = $session->get(self::SESSION_STATE_KEY);

        self::assertIsString($state);
        self::assertSame(64, strlen($state));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $state);
    }

    public function testCallbackRejectsWhenStateMissingFromQuery(): void
    {
        $client = static::createClient();
        $client->request('GET', '/auth/google/callback?code=any-code');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Invalid or missing OAuth state.', $payload['error']);
    }

    public function testCallbackRejectsWhenStateNotInSession(): void
    {
        $client = static::createClient();
        $client->request('GET', '/auth/google/callback?code=any-code&state=any-state');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Invalid or missing OAuth state.', $payload['error']);
    }

    public function testCallbackRejectsEmptyStringState(): void
    {
        $client = static::createClient();
        $client->request('GET', '/auth/google/callback?code=any-code&state=');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Invalid or missing OAuth state.', $payload['error']);
    }

    public function testCallbackRejectsMismatchedState(): void
    {
        $client = static::createClient();
        $client->request('GET', '/auth/google');
        $sessionState = $client->getRequest()->getSession()->get(self::SESSION_STATE_KEY);
        self::assertIsString($sessionState);

        $client->request('GET', '/auth/google/callback?code=any-code&state=tampered');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Invalid or missing OAuth state.', $payload['error']);
    }

    public function testCallbackConsumesStateOnRejection(): void
    {
        $client = static::createClient();
        $client->request('GET', '/auth/google');
        $sessionState = $client->getRequest()->getSession()->get(self::SESSION_STATE_KEY);
        self::assertIsString($sessionState);

        $client->request('GET', '/auth/google/callback?code=any-code&state=tampered');

        self::assertNull($client->getRequest()->getSession()->get(self::SESSION_STATE_KEY));
    }
}
