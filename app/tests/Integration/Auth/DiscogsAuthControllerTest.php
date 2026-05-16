<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Module\Music\Infrastructure\Persistence\DiscogsTokenRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DiscogsAuthControllerTest extends WebTestCase
{
    private const string SESSION_STATE_KEY = 'discogs_oauth_state';
    private const string DISCOGS_AUTHORIZE_URL = 'https://www.discogs.com/oauth/authorize';

    private KernelBrowser $client;
    private DiscogsTokenRepositoryInterface&MockObject $tokenRepository;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Service overrides must survive between sub-requests; otherwise the
        // mock is discarded when the kernel reboots before the next request.
        $this->client->disableReboot();

        $this->tokenRepository = $this->createMock(DiscogsTokenRepositoryInterface::class);
        self::getContainer()->set(DiscogsTokenRepositoryInterface::class, $this->tokenRepository);
    }

    public function testAuthorizeRedirectsToDiscogsWithRequestToken(): void
    {
        $this->installHttpClient([
            $this->requestTokenResponse('request-tok-1', 'request-secret-1'),
        ]);

        $this->client->request('GET', '/auth/discogs');

        $response = $this->client->getResponse();
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            self::DISCOGS_AUTHORIZE_URL.'?oauth_token=request-tok-1',
            $response->headers->get('Location')
        );
    }

    public function testAuthorizeStoresStateInSession(): void
    {
        $this->installHttpClient([
            $this->requestTokenResponse('request-tok-1', 'request-secret-1'),
        ]);

        $this->client->request('GET', '/auth/discogs');

        $state = $this->client->getRequest()->getSession()->get(self::SESSION_STATE_KEY);
        self::assertIsString($state);
        self::assertNotSame('', $state);
    }

    public function testAuthorizeReturnsBadGatewayWhenRequestTokenFails(): void
    {
        $this->installHttpClient([
            new MockResponse('Invalid consumer credentials.', ['http_code' => 401]),
        ]);

        $this->client->request('GET', '/auth/discogs');

        $response = $this->client->getResponse();
        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString(
            'Failed to obtain Discogs request token',
            (string) $response->getContent()
        );
    }

    public function testCallbackReturnsErrorWhenOauthTokenMissing(): void
    {
        $state = $this->primeOAuthState();

        $this->client->request('GET', '/auth/discogs/callback?state='.$state.'&oauth_verifier=v');

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'Missing OAuth parameters',
            (string) $this->client->getResponse()->getContent()
        );
    }

    public function testCallbackReturnsErrorWhenOauthVerifierMissing(): void
    {
        $state = $this->primeOAuthState();

        $this->client->request('GET', '/auth/discogs/callback?state='.$state.'&oauth_token=t');

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'Missing OAuth parameters',
            (string) $this->client->getResponse()->getContent()
        );
    }

    public function testCallbackReturnsBadGatewayWhenAccessTokenFails(): void
    {
        $state = $this->primeOAuthState([
            new MockResponse('Invalid OAuth verifier.', ['http_code' => 401]),
        ]);
        $this->tokenRepository->expects(self::never())->method('save');

        $this->client->request('GET', '/auth/discogs/callback?state='.$state.'&oauth_token=t&oauth_verifier=v');

        self::assertSame(502, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'Failed to obtain Discogs access token',
            (string) $this->client->getResponse()->getContent()
        );
    }

    public function testCallbackReturnsBadGatewayWhenResponseBodyMissingTokens(): void
    {
        $state = $this->primeOAuthState([
            new MockResponse('', ['http_code' => 200]),
        ]);
        $this->tokenRepository->expects(self::never())->method('save');

        $this->client->request('GET', '/auth/discogs/callback?state='.$state.'&oauth_token=t&oauth_verifier=v');

        self::assertSame(502, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'Failed to obtain Discogs access token',
            (string) $this->client->getResponse()->getContent()
        );
    }

    public function testCallbackPersistsTokenAndReturnsSuccess(): void
    {
        $accessTokenBody = http_build_query([
            'oauth_token' => 'final-access-token',
            'oauth_token_secret' => 'final-access-secret',
        ]);
        $state = $this->primeOAuthState([
            new MockResponse($accessTokenBody, ['http_code' => 200]),
        ]);

        $this->tokenRepository->expects(self::once())
            ->method('save')
            ->with('final-access-token', 'final-access-secret');

        $this->client->request('GET', '/auth/discogs/callback?state='.$state.'&oauth_token=req-tok&oauth_verifier=verifier-1');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Discogs connected successfully', (string) $response->getContent());
    }

    /**
     * Replace the autowired HTTP client with a queue of canned responses.
     * MockHttpClient consumes the array in order, one per request.
     *
     * @param list<MockResponse> $responses
     */
    private function installHttpClient(array $responses): void
    {
        self::getContainer()->set('http_client', new MockHttpClient($responses));
    }

    /**
     * Drive the real authorize endpoint to plant a valid OAuth state into the
     * session, then queue any extra responses for the test's own request(s).
     *
     * @param list<MockResponse> $followupResponses
     */
    private function primeOAuthState(array $followupResponses = []): string
    {
        $this->installHttpClient(array_merge(
            [$this->requestTokenResponse('req-tok', 'req-secret')],
            $followupResponses,
        ));
        $this->client->request('GET', '/auth/discogs');

        $state = $this->client->getRequest()->getSession()->get(self::SESSION_STATE_KEY);
        self::assertIsString($state);

        return $state;
    }

    private function requestTokenResponse(string $token, string $secret): MockResponse
    {
        return new MockResponse(
            http_build_query(['oauth_token' => $token, 'oauth_token_secret' => $secret]),
            ['http_code' => 200],
        );
    }
}
