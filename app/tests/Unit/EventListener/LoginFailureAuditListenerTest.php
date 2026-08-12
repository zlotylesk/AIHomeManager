<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\LoginFailureAuditListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final class LoginFailureAuditListenerTest extends TestCase
{
    public function testFailedMainFirewallLoginIsLoggedAsAuditWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('Panel login failed', ['ip' => '203.0.113.7', 'reason' => 'Invalid credentials.']);

        $listener = new LoginFailureAuditListener($logger);
        $listener(new LoginFailureEvent(
            new CustomUserMessageAuthenticationException('Invalid credentials.'),
            $this->createStub(AuthenticatorInterface::class),
            Request::create('/series', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']),
            null,
            'main',
        ));
    }

    /**
     * `LoginFailureEvent` fires for every AuthenticatorManager-based firewall, `api`
     * included — a wrong `X-API-Key` must not land in the operator-account audit
     * channel.
     */
    public function testFailedApiFirewallLoginIsNotAudited(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $listener = new LoginFailureAuditListener($logger);
        $listener(new LoginFailureEvent(
            new AuthenticationException('Invalid API key.'),
            $this->createStub(AuthenticatorInterface::class),
            Request::create('/api/series'),
            null,
            'api',
        ));
    }
}
