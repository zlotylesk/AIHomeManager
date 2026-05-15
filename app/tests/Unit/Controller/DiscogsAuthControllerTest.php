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
        // DiscogsOAuth1Signer is final and stateless — instantiate directly
        // rather than fight PHPUnit's no-doubling-final restriction. The
        // returned Authorization header is opaque to the controller under test.
        // Detector and controller share the same logger so tests that pin
        // expects(self::once()) on the controller's HMAI-105 warning also
        // catch an unintended drift warning leaking from MockResponse changes.
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
        // Regression guard: happy path still produces a 302 to discogs.com with
        // the request_token in the query string. Without this test, a refactor
        // could accidentally drop the redirect and the 200 status path would
        // still pass the failure-mode tests below.
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
        // Regression for HMAI-105: a 401 from Discogs (bad consumer key/secret)
        // previously bubbled through getContent() as a HttpExceptionInterface,
        // producing a generic 500. The status guard must convert it into a
        // 502 with a recognisable error body so the user (and ops) know it's
        // an upstream failure, not a bug in our code.
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
        // Same protection for the second leg of OAuth1. Discogs occasionally 500s
        // mid-flow during a deploy; the user must see "upstream failed" rather
        // than a stack trace.
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
        // Belt for the access_token happy path. Together with the failure-mode
        // tests, this nails down that the 200-vs-other branch is dispatched on
        // the actual status code, not on body content.
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
}
