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
        $client = $this->createStub(Client::class);
        $client->method('setState')->willThrowException(new RuntimeException('bad state value'));

        $response = $this->makeController($client)->authorize($this->makeRequest());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/tasks?error=oauth_unavailable', $response->getTargetUrl());
    }

    public function testAuthorizeEmitsAuditInfoOnHappyPath(): void
    {
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
