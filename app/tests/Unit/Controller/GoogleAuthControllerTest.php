<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\GoogleAuthController;
use App\Module\Tasks\Infrastructure\Persistence\GoogleTokenRepositoryInterface;
use Google\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class GoogleAuthControllerTest extends TestCase
{
    private function makeController(Client $client, ?LoggerInterface $logger = null): GoogleAuthController
    {
        return new GoogleAuthController(
            client: $client,
            tokenRepository: $this->createStub(GoogleTokenRepositoryInterface::class),
            logger: $logger ?? new NullLogger(),
        );
    }

    private function makeRequest(): Request
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    public function testAuthorizeRedirectsToGoogleOnHappyPath(): void
    {
        // Belt against accidental regression of the happy path when adding the
        // try/catch — we still need a 302 to the URL produced by the SDK.
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('setState');
        $client->expects(self::once())
            ->method('createAuthUrl')
            ->willReturn('https://accounts.google.com/o/oauth2/v2/auth?client_id=test');

        $response = $this->makeController($client)->authorize($this->makeRequest());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://accounts.google.com/o/oauth2/v2/auth?client_id=test', $response->getTargetUrl());
    }

    public function testAuthorizeRedirectsToTasksWithErrorWhenCreateAuthUrlThrows(): void
    {
        // Regression for HMAI-106: a misconfigured Google SDK previously
        // bubbled an uncaught exception out of authorize(), giving the user a
        // generic 500. The controller must trap the throwable, log it, and
        // send the user back to /tasks with a flag the UI can render as a
        // friendly notice.
        $client = $this->createStub(Client::class);
        $client->method('createAuthUrl')->willThrowException(new RuntimeException('clientId is empty'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('Google OAuth init failed'), self::callback(
                static fn (array $ctx): bool => 'clientId is empty' === $ctx['exception']
                    && RuntimeException::class === $ctx['class'],
            ));

        $response = $this->makeController($client, $logger)->authorize($this->makeRequest());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/tasks?error=oauth_unavailable', $response->getTargetUrl());
    }

    public function testAuthorizeTrapsSetStateFailureToo(): void
    {
        // setState() lives inside the same try block on purpose — the SDK
        // could plausibly throw from there if the consumer feeds it a value
        // the underlying transport rejects. Verifies the guard covers the
        // full SDK call sequence, not just createAuthUrl().
        $client = $this->createStub(Client::class);
        $client->method('setState')->willThrowException(new RuntimeException('bad state value'));

        $response = $this->makeController($client)->authorize($this->makeRequest());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/tasks?error=oauth_unavailable', $response->getTargetUrl());
    }

    public function testAuthorizeEmitsAuditInfoOnHappyPath(): void
    {
        // HMAI-107: every authorize attempt must leave a trail on the `auth`
        // channel so we can correlate a downstream callback to its initiator
        // even when the token-exchange path is clean. The payload carries the
        // provider so Graylog filters can split Google vs Discogs.
        $client = $this->createStub(Client::class);
        $client->method('createAuthUrl')->willReturn('https://accounts.google.com/o/oauth2/v2/auth');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('OAuth authorize initiated', ['provider' => 'google']);

        $this->makeController($client, $logger)->authorize($this->makeRequest());
    }

    public function testCallbackLogsInvalidStateAsAuditWarning(): void
    {
        // HMAI-107: a forged or replayed state must produce a structured
        // `reason=invalid_state` warning, not just an HTTP 400. The warning
        // is the only signal we have that someone is poking at the callback.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('OAuth callback failed', ['provider' => 'google', 'reason' => 'invalid_state']);

        $controller = $this->makeController($this->createStub(Client::class), $logger);

        $response = $controller->callback($this->makeRequest());

        self::assertSame(400, $response->getStatusCode());
    }

    public function testCallbackLogsMissingCodeAsAuditWarning(): void
    {
        // The state passes but Google sent no `code` — typically a botched
        // consent screen. Distinguished from invalid_state by reason key.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('OAuth callback failed', ['provider' => 'google', 'reason' => 'missing_code']);

        $request = $this->makeRequest();
        $request->getSession()->set('google_oauth_state', 'fixed-state');
        $request->query->set('state', 'fixed-state');

        $controller = $this->makeController($this->createStub(Client::class), $logger);

        $response = $controller->callback($request);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testCallbackLogsTokenExchangeFailureAsAuditWarning(): void
    {
        // Google returns 200 + {error: ...} when the auth code is bogus
        // (replayed, expired, wrong client). Surface the upstream error string
        // in the audit log so we can spot patterns.
        $client = $this->createStub(Client::class);
        $client->method('fetchAccessTokenWithAuthCode')->willReturn(['error' => 'invalid_grant']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('OAuth callback failed', [
                'provider' => 'google',
                'reason' => 'token_exchange',
                'error' => 'invalid_grant',
            ]);

        $request = $this->makeRequest();
        $request->getSession()->set('google_oauth_state', 'fixed-state');
        $request->query->set('state', 'fixed-state');
        $request->query->set('code', 'bad-code');

        $controller = $this->makeController($client, $logger);

        $response = $controller->callback($request);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testCallbackLogsSuccessAsAuditInfo(): void
    {
        // The success path is the most important audit event: a token landed
        // in the repository. Verifies it fires AFTER the repository save so a
        // mid-flight exception wouldn't claim a token was issued.
        $client = $this->createStub(Client::class);
        $client->method('fetchAccessTokenWithAuthCode')->willReturn([
            'access_token' => 'tok',
            'refresh_token' => 'ref',
            'expires_in' => 3600,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('OAuth callback success', ['provider' => 'google']);

        $request = $this->makeRequest();
        $request->getSession()->set('google_oauth_state', 'fixed-state');
        $request->query->set('state', 'fixed-state');
        $request->query->set('code', 'good-code');

        $controller = $this->makeController($client, $logger);

        $response = $controller->callback($request);

        self::assertSame(200, $response->getStatusCode());
    }
}
