<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Module\Series\Infrastructure\Persistence\TraktTokenRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TraktAuthControllerTest extends WebTestCase
{
    private const string SESSION_STATE_KEY = 'trakt_oauth_state';
    private const string TRAKT_AUTHORIZE_URL = 'https://trakt.tv/oauth/authorize';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Service overrides (MockHttpClient) must survive between the
        // authorize → callback sub-requests; without this the kernel reboots
        // and discards the mock before the callback runs.
        $this->client->disableReboot();

        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->connection->executeStatement('TRUNCATE TABLE trakt_oauth_tokens');
    }

    public function testAuthorizeRedirectsToTraktWithStateAndClientId(): void
    {
        $this->client->request('GET', '/auth/trakt');

        $response = $this->client->getResponse();
        self::assertSame(302, $response->getStatusCode());

        $location = (string) $response->headers->get('Location');
        self::assertStringStartsWith(self::TRAKT_AUTHORIZE_URL, $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('code', $query['response_type']);
        self::assertSame('test-trakt-client-id', $query['client_id']);
        self::assertArrayHasKey('state', $query);
        self::assertNotSame('', $query['state']);
    }

    public function testAuthorizeStoresStateInSession(): void
    {
        $this->client->request('GET', '/auth/trakt');

        $state = $this->client->getRequest()->getSession()->get(self::SESSION_STATE_KEY);
        self::assertIsString($state);
        self::assertNotSame('', $state);
    }

    public function testCallbackRejectsInvalidState(): void
    {
        $this->primeOAuthState();

        $this->client->request('GET', '/auth/trakt/callback?state=not-the-real-state&code=abc');

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Invalid or missing OAuth state', (string) $this->client->getResponse()->getContent());
    }

    public function testCallbackRejectsMissingCode(): void
    {
        $state = $this->primeOAuthState();

        $this->client->request('GET', '/auth/trakt/callback?state='.$state);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Authorization code missing', (string) $this->client->getResponse()->getContent());
    }

    public function testCallbackReturnsBadGatewayWhenTokenExchangeFails(): void
    {
        $state = $this->primeOAuthState([new MockResponse('{"error":"invalid_grant"}', ['http_code' => 401])]);

        $this->client->request('GET', '/auth/trakt/callback?state='.$state.'&code=bad-code');

        self::assertSame(502, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Failed to obtain Trakt access token', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->repository()->get());
    }

    public function testCallbackPersistsEncryptedTokenAndRedirects(): void
    {
        $token = [
            'access_token' => 'trakt-access-xyz',
            'token_type' => 'bearer',
            'expires_in' => 7776000,
            'refresh_token' => 'trakt-refresh-xyz',
            'scope' => 'public',
            'created_at' => 1700000000,
        ];

        $state = $this->primeOAuthState([new MockResponse(json_encode($token, JSON_THROW_ON_ERROR), ['http_code' => 200])]);

        $this->client->request('GET', '/auth/trakt/callback?state='.$state.'&code=good-code');

        $response = $this->client->getResponse();
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/series', $response->headers->get('Location'));

        // Round-trip: the repository decrypts back to exactly what Trakt returned.
        self::assertSame($token, $this->repository()->get());

        // At rest the token must be ciphertext — the raw column never leaks the
        // plaintext access token.
        $stored = (string) $this->connection->fetchOne('SELECT token_json FROM trakt_oauth_tokens');
        self::assertStringNotContainsString('trakt-access-xyz', $stored);
    }

    /**
     * Drive the real authorize endpoint to plant a valid OAuth state into the
     * session, returning that state for the test's callback request. The mock
     * HTTP client is installed first (authorize itself makes no HTTP call) so
     * the queued response is ready for the callback's token exchange — the
     * service cannot be replaced once a request has initialized it.
     *
     * @param list<MockResponse> $responses
     */
    private function primeOAuthState(array $responses = []): string
    {
        $this->installHttpClient($responses);
        $this->client->request('GET', '/auth/trakt');
        $state = $this->client->getRequest()->getSession()->get(self::SESSION_STATE_KEY);
        self::assertIsString($state);

        return $state;
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function installHttpClient(array $responses): void
    {
        static::getContainer()->set('http_client', new MockHttpClient($responses));
    }

    private function repository(): TraktTokenRepositoryInterface
    {
        return static::getContainer()->get(TraktTokenRepositoryInterface::class);
    }
}
