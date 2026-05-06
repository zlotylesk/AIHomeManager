<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DiscogsOAuthStateTest extends WebTestCase
{
    private const string SESSION_STATE_KEY = 'discogs_oauth_state';

    public function testCallbackRejectsWhenStateMissingFromQuery(): void
    {
        $client = static::createClient();
        $client->request('GET', '/auth/discogs/callback?oauth_token=t&oauth_verifier=v');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertSame('Invalid or missing OAuth state.', $client->getResponse()->getContent());
    }

    public function testCallbackRejectsWhenStateNotInSession(): void
    {
        $client = static::createClient();
        $client->request('GET', '/auth/discogs/callback?oauth_token=t&oauth_verifier=v&state=any-state');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertSame('Invalid or missing OAuth state.', $client->getResponse()->getContent());
    }

    public function testCallbackRejectsEmptyStringState(): void
    {
        $client = static::createClient();
        $client->request('GET', '/auth/discogs/callback?oauth_token=t&oauth_verifier=v&state=');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertSame('Invalid or missing OAuth state.', $client->getResponse()->getContent());
    }

    public function testCallbackRejectsMismatchedState(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        $session = $client->getRequest()->getSession();
        $session->set(self::SESSION_STATE_KEY, 'expected-state');
        $session->save();

        $client->request('GET', '/auth/discogs/callback?oauth_token=t&oauth_verifier=v&state=tampered');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertSame('Invalid or missing OAuth state.', $client->getResponse()->getContent());
    }

    public function testCallbackConsumesStateOnRejection(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        $session = $client->getRequest()->getSession();
        $session->set(self::SESSION_STATE_KEY, 'expected-state');
        $session->save();

        $client->request('GET', '/auth/discogs/callback?oauth_token=t&oauth_verifier=v&state=tampered');

        self::assertNull($client->getRequest()->getSession()->get(self::SESSION_STATE_KEY));
    }
}
