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
}
