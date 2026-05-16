<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Module\Tasks\Infrastructure\Persistence\GoogleTokenRepositoryInterface;
use Google\Client;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GoogleAuthControllerTest extends WebTestCase
{
    private const string SESSION_STATE_KEY = 'google_oauth_state';
    private const string DUMMY_AUTH_URL = 'https://accounts.google.com/o/oauth2/auth?client_id=test';

    private KernelBrowser $client;
    private Client&MockObject $googleClient;
    private GoogleTokenRepositoryInterface&MockObject $tokenRepository;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Service overrides must survive between sub-requests; otherwise the
        // mock is discarded when the kernel reboots before the next request.
        $this->client->disableReboot();

        $this->googleClient = $this->createMock(Client::class);
        $this->tokenRepository = $this->createMock(GoogleTokenRepositoryInterface::class);

        self::getContainer()->set('google.client', $this->googleClient);
        self::getContainer()->set(GoogleTokenRepositoryInterface::class, $this->tokenRepository);
    }

    public function testAuthorizeRedirectsToGoogleAuthUrlAndStoresStateInSession(): void
    {
        $this->googleClient->expects(self::once())->method('setState');
        $this->googleClient->method('createAuthUrl')->willReturn(self::DUMMY_AUTH_URL);

        $this->client->request('GET', '/auth/google');

        $response = $this->client->getResponse();
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(self::DUMMY_AUTH_URL, $response->headers->get('Location'));

        $state = $this->client->getRequest()->getSession()->get(self::SESSION_STATE_KEY);
        self::assertIsString($state);
        self::assertNotSame('', $state);
    }

    public function testAuthorizeRedirectsToTasksErrorWhenCreateAuthUrlThrows(): void
    {
        $this->googleClient->method('createAuthUrl')
            ->willThrowException(new RuntimeException('OAuth init failed'));

        $this->client->request('GET', '/auth/google');

        $response = $this->client->getResponse();
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/tasks?error=oauth_unavailable', $response->headers->get('Location'));
    }

    public function testCallbackRejectsWhenStateMissingFromQuery(): void
    {
        $this->client->request('GET', '/auth/google/callback?code=auth-code');

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'Invalid or missing OAuth state',
            (string) $this->client->getResponse()->getContent()
        );
    }

    public function testCallbackRejectsMismatchedState(): void
    {
        // Prime session by running the real authorize flow so the callback
        // sees a non-empty expected state — without this the test would pass
        // for the wrong reason (state simply absent from session).
        $this->googleClient->method('createAuthUrl')->willReturn(self::DUMMY_AUTH_URL);
        $this->client->request('GET', '/auth/google');

        $this->client->request('GET', '/auth/google/callback?code=auth-code&state=tampered');

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'Invalid or missing OAuth state',
            (string) $this->client->getResponse()->getContent()
        );
    }

    public function testCallbackConsumesStateAfterValidation(): void
    {
        $this->googleClient->method('createAuthUrl')->willReturn(self::DUMMY_AUTH_URL);
        $this->googleClient->method('fetchAccessTokenWithAuthCode')
            ->willReturn(['access_token' => 't', 'refresh_token' => 'r']);

        $state = $this->primeOAuthState();
        $this->client->request('GET', '/auth/google/callback?code=auth-code&state='.$state);

        self::assertNull(
            $this->client->getRequest()->getSession()->get(self::SESSION_STATE_KEY),
            'State must be cleared after callback to prevent replay'
        );
    }

    public function testCallbackReturnsErrorWhenAuthCodeMissing(): void
    {
        $this->googleClient->method('createAuthUrl')->willReturn(self::DUMMY_AUTH_URL);
        $state = $this->primeOAuthState();

        $this->client->request('GET', '/auth/google/callback?state='.$state);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'Authorization code missing',
            (string) $this->client->getResponse()->getContent()
        );
    }

    public function testCallbackReturnsErrorOnTokenExchangeFailure(): void
    {
        $this->googleClient->method('createAuthUrl')->willReturn(self::DUMMY_AUTH_URL);
        $this->googleClient->method('fetchAccessTokenWithAuthCode')
            ->willReturn(['error' => 'invalid_grant']);
        $this->tokenRepository->expects(self::never())->method('save');

        $state = $this->primeOAuthState();
        $this->client->request('GET', '/auth/google/callback?code=bad-code&state='.$state);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'invalid_grant',
            (string) $this->client->getResponse()->getContent()
        );
    }

    public function testCallbackPersistsTokenAndReturnsAuthenticated(): void
    {
        $token = ['access_token' => 'access-xyz', 'refresh_token' => 'refresh-abc', 'expires_in' => 3600];
        $this->googleClient->method('createAuthUrl')->willReturn(self::DUMMY_AUTH_URL);
        $this->googleClient->expects(self::once())
            ->method('fetchAccessTokenWithAuthCode')
            ->with('auth-code')
            ->willReturn($token);
        $this->tokenRepository->expects(self::once())->method('save')->with($token);

        $state = $this->primeOAuthState();
        $this->client->request('GET', '/auth/google/callback?code=auth-code&state='.$state);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"status":"authenticated"}',
            (string) $response->getContent()
        );
    }

    /**
     * Run the real authorize endpoint to plant the OAuth state into the
     * session and return the generated state token, ready to be echoed back
     * by the callback request. createAuthUrl must already be stubbed.
     */
    private function primeOAuthState(): string
    {
        $this->client->request('GET', '/auth/google');
        $state = $this->client->getRequest()->getSession()->get(self::SESSION_STATE_KEY);
        self::assertIsString($state);

        return $state;
    }
}
