<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Module\Podcasts\Infrastructure\Persistence\SpotifyTokenRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SpotifyAuthControllerTest extends WebTestCase
{
    private const string SESSION_STATE_KEY = 'spotify_oauth_state';
    private const string SPOTIFY_AUTHORIZE_URL = 'https://accounts.spotify.com/authorize';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $this->client->disableReboot();

        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->connection->executeStatement('TRUNCATE TABLE spotify_oauth_tokens');
    }

    public function testAuthorizeRedirectsToSpotifyWithStateAndClientId(): void
    {
        $this->client->request('GET', '/auth/spotify');

        $response = $this->client->getResponse();
        self::assertSame(302, $response->getStatusCode());

        $location = (string) $response->headers->get('Location');
        self::assertStringStartsWith(self::SPOTIFY_AUTHORIZE_URL, $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('code', $query['response_type']);
        self::assertIsString($query['client_id']);
        self::assertNotSame('', $query['client_id']);
        self::assertArrayHasKey('state', $query);
        self::assertNotSame('', $query['state']);
    }

    /**
     * Without user-read-playback-position Spotify silently omits `resume_point`
     * from every episode — and resume points ARE the listening history here, so
     * the integration would connect successfully and then report nothing.
     */
    public function testAuthorizeRequestsTheScopesTheListeningHistoryDependsOn(): void
    {
        $this->client->request('GET', '/auth/spotify');

        $location = (string) $this->client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        self::assertIsString($query['scope']);
        self::assertStringContainsString('user-library-read', $query['scope']);
        self::assertStringContainsString('user-read-playback-position', $query['scope']);
        self::assertStringContainsString('user-read-currently-playing', $query['scope']);
    }

    public function testAuthorizeStoresStateInSession(): void
    {
        $this->client->request('GET', '/auth/spotify');

        $state = $this->client->getRequest()->getSession()->get(self::SESSION_STATE_KEY);
        self::assertIsString($state);
        self::assertNotSame('', $state);
    }

    public function testCallbackRejectsInvalidState(): void
    {
        $this->primeOAuthState();

        $this->client->request('GET', '/auth/spotify/callback?state=not-the-real-state&code=abc');

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Invalid or missing OAuth state', (string) $this->client->getResponse()->getContent());
    }

    public function testCallbackRejectsMissingCode(): void
    {
        $state = $this->primeOAuthState();

        $this->client->request('GET', '/auth/spotify/callback?state='.$state);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Authorization code missing', (string) $this->client->getResponse()->getContent());
    }

    public function testCallbackReturnsBadGatewayWhenTokenExchangeFails(): void
    {
        $state = $this->primeOAuthState([new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400])]);

        $this->client->request('GET', '/auth/spotify/callback?state='.$state.'&code=bad-code');

        self::assertSame(502, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Failed to obtain Spotify access token', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->repository()->get());
    }

    public function testCallbackPersistsEncryptedTokenAndRedirects(): void
    {
        $token = [
            'access_token' => 'spotify-access-xyz',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'spotify-refresh-xyz',
            'scope' => 'user-library-read user-read-playback-position',
        ];

        $state = $this->primeOAuthState([new MockResponse(json_encode($token, JSON_THROW_ON_ERROR), ['http_code' => 200])]);

        $this->client->request('GET', '/auth/spotify/callback?state='.$state.'&code=good-code');

        $response = $this->client->getResponse();
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->headers->get('Location'));

        $stored = $this->repository()->get();
        self::assertIsArray($stored);
        self::assertSame('spotify-access-xyz', $stored['access_token']);
        self::assertSame('spotify-refresh-xyz', $stored['refresh_token']);
    }

    /**
     * Spotify does not say when it issued the token, so the repository stamps an
     * issue time on write. Without it there is nothing to add `expires_in` to and
     * the provider would treat every stored token as already expired.
     */
    public function testCallbackStampsAnIssueTimeSpotifyDoesNotProvide(): void
    {
        $state = $this->primeOAuthState([new MockResponse(
            (string) json_encode(['access_token' => 'a', 'expires_in' => 3600, 'refresh_token' => 'r']),
            ['http_code' => 200]
        )]);

        $before = time();
        $this->client->request('GET', '/auth/spotify/callback?state='.$state.'&code=good-code');

        $stored = $this->repository()->get();
        self::assertIsArray($stored);
        self::assertIsInt($stored['created_at']);
        self::assertGreaterThanOrEqual($before, $stored['created_at']);
    }

    public function testStoredTokenIsEncryptedAtRest(): void
    {
        $state = $this->primeOAuthState([new MockResponse(
            (string) json_encode(['access_token' => 'spotify-access-xyz', 'expires_in' => 3600]),
            ['http_code' => 200]
        )]);

        $this->client->request('GET', '/auth/spotify/callback?state='.$state.'&code=good-code');

        $stored = (string) $this->connection->fetchOne('SELECT token_json FROM spotify_oauth_tokens');
        self::assertNotSame('', $stored);
        self::assertStringNotContainsString('spotify-access-xyz', $stored);
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
        static::getContainer()->set('http_client', new MockHttpClient($responses));
        $this->client->request('GET', '/auth/spotify');
        $state = $this->client->getRequest()->getSession()->get(self::SESSION_STATE_KEY);
        self::assertIsString($state);

        return $state;
    }

    private function repository(): SpotifyTokenRepositoryInterface
    {
        return static::getContainer()->get(SpotifyTokenRepositoryInterface::class);
    }
}
