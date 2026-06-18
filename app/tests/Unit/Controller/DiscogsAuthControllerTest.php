<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\DiscogsAuthController;
use App\Module\Music\Infrastructure\External\DiscogsClockDriftDetector;
use App\Module\Music\Infrastructure\External\DiscogsCredentials;
use App\Module\Music\Infrastructure\External\DiscogsOAuth1Signer;
use App\Module\Music\Infrastructure\Persistence\DiscogsTokenRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class DiscogsAuthControllerTest extends TestCase
{
    private const string SESSION_STATE_KEY = 'discogs_oauth_state';

    private function makeController(MockHttpClient $httpClient, ?LoggerInterface $logger = null): DiscogsAuthController
    {
        $sharedLogger = $logger ?? new NullLogger();

        return new DiscogsAuthController(
            httpClient: $httpClient,
            tokenRepository: $this->createStub(DiscogsTokenRepositoryInterface::class),
            signer: new DiscogsOAuth1Signer(),
            logger: $sharedLogger,
            credentials: new DiscogsCredentials('test-key', 'test-secret'),
            driftDetector: new DiscogsClockDriftDetector($sharedLogger),
            callbackUrl: 'http://localhost:8080/auth/discogs/callback',
        );
    }

    private function makeRequest(): Request
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    public function testAuthorizeRedirectsOnSuccessfulRequestToken(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(
            'oauth_token=req-token&oauth_token_secret=req-secret&oauth_callback_confirmed=true',
            ['http_code' => 200],
        ));

        $response = $this->makeController($httpClient)->authorize($this->makeRequest());

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertStringContainsString('discogs.com/oauth/authorize?oauth_token=req-token', (string) $response->headers->get('Location'));
    }

    public function testAuthorizeReturnsBadGatewayWhenRequestTokenFailsWith401(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('Invalid consumer credentials', ['http_code' => 401]));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('request_token returned non-200'), self::callback(
                static fn (array $ctx): bool => 401 === $ctx['status'] && str_contains((string) $ctx['body_sample'], 'Invalid consumer'),
            ));

        $response = $this->makeController($httpClient, $logger)->authorize($this->makeRequest());

        self::assertSame(Response::HTTP_BAD_GATEWAY, $response->getStatusCode());
        self::assertStringContainsString('HTTP 401', (string) $response->getContent());
    }

    public function testCallbackReturnsBadGatewayWhenAccessTokenFailsWith500(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('Internal Server Error', ['http_code' => 500]));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('access_token returned non-200'));

        $request = $this->makeRequest();
        $request->getSession()->set(self::SESSION_STATE_KEY, 'matching-state');
        $request->query->set('state', 'matching-state');
        $request->query->set('oauth_token', 'req-token');
        $request->query->set('oauth_verifier', 'verifier');

        $response = $this->makeController($httpClient, $logger)->callback($request);

        self::assertSame(Response::HTTP_BAD_GATEWAY, $response->getStatusCode());
        self::assertStringContainsString('HTTP 500', (string) $response->getContent());
    }

    public function testCallbackSucceedsOnValidAccessTokenResponse(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(
            'oauth_token=access&oauth_token_secret=secret',
            ['http_code' => 200],
        ));

        $request = $this->makeRequest();
        $request->getSession()->set(self::SESSION_STATE_KEY, 'matching-state');
        $request->query->set('state', 'matching-state');
        $request->query->set('oauth_token', 'req-token');
        $request->query->set('oauth_verifier', 'verifier');

        $response = $this->makeController($httpClient)->callback($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('connected successfully', (string) $response->getContent());
    }

    public function testAuthorizeEmitsAuditInfoOnHappyPath(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(
            'oauth_token=req-token&oauth_token_secret=req-secret&oauth_callback_confirmed=true',
            ['http_code' => 200],
        ));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('OAuth authorize initiated', ['provider' => 'discogs']);

        $this->makeController($httpClient, $logger)->authorize($this->makeRequest());
    }

    public function testCallbackLogsInvalidStateAsAuditWarning(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['http_code' => 200]));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('OAuth callback failed', ['provider' => 'discogs', 'reason' => 'invalid_state']);

        $response = $this->makeController($httpClient, $logger)->callback($this->makeRequest());

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCallbackLogsMissingParamsAsAuditWarning(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['http_code' => 200]));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('OAuth callback failed', ['provider' => 'discogs', 'reason' => 'missing_params']);

        $request = $this->makeRequest();
        $request->getSession()->set(self::SESSION_STATE_KEY, 'matching-state');
        $request->query->set('state', 'matching-state');

        $response = $this->makeController($httpClient, $logger)->callback($request);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCallbackLogsEmptyTokenAsAuditWarning(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(
            'oauth_token=&oauth_token_secret=',
            ['http_code' => 200],
        ));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('OAuth callback failed', ['provider' => 'discogs', 'reason' => 'empty_token']);

        $request = $this->makeRequest();
        $request->getSession()->set(self::SESSION_STATE_KEY, 'matching-state');
        $request->query->set('state', 'matching-state');
        $request->query->set('oauth_token', 'req-token');
        $request->query->set('oauth_verifier', 'verifier');

        $response = $this->makeController($httpClient, $logger)->callback($request);

        self::assertSame(Response::HTTP_BAD_GATEWAY, $response->getStatusCode());
    }

    public function testCallbackLogsSuccessAsAuditInfo(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(
            'oauth_token=access&oauth_token_secret=secret',
            ['http_code' => 200],
        ));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('OAuth callback success', ['provider' => 'discogs']);

        $request = $this->makeRequest();
        $request->getSession()->set(self::SESSION_STATE_KEY, 'matching-state');
        $request->query->set('state', 'matching-state');
        $request->query->set('oauth_token', 'req-token');
        $request->query->set('oauth_verifier', 'verifier');

        $response = $this->makeController($httpClient, $logger)->callback($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }
}
