<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * HMAI-434: failed panel logins land in the same `auth` audit channel as OAuth
 * authorize/callback events, instead of nowhere.
 *
 * `LoginFailureEvent` fires for every firewall built on the AuthenticatorManager,
 * `api` included — filtering to `main` keeps a wrong `X-API-Key` out of an audit
 * channel meant for the operator account, and out of a log line that would
 * otherwise carry no request identity worth auditing.
 */
#[AsEventListener(event: LoginFailureEvent::class)]
final readonly class LoginFailureAuditListener
{
    private const string AUDITED_FIREWALL = 'main';

    public function __construct(
        #[Autowire(service: 'monolog.logger.auth')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(LoginFailureEvent $event): void
    {
        if (self::AUDITED_FIREWALL !== $event->getFirewallName()) {
            return;
        }

        $this->logger->warning('Panel login failed', [
            'ip' => $event->getRequest()->getClientIp() ?? 'unknown',
            'reason' => $event->getException()->getMessageKey(),
        ]);
    }
}
