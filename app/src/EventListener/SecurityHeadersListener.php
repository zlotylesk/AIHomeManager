<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[AsEventListener(event: 'kernel.response', priority: -128)]
final readonly class SecurityHeadersListener
{
    /**
     * Kept identical to the value in docker/nginx/snippets/hsts-map.conf, which
     * ProductionRuntimeConfigTest enforces: the two layers announce one policy
     * or the build fails. A max-age that disagreed between them would be
     * decided by whichever response the browser happened to see last.
     */
    public const string STRICT_TRANSPORT_SECURITY = 'max-age=31536000; includeSubDomains';

    public function __invoke(ResponseEvent $event): void
    {
        $headers = $event->getResponse()->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=()');

        // Only over TLS. RFC 6797 requires a user agent to ignore the header on
        // a plain-HTTP connection, so sending it there is not a weaker
        // protection — it is none, dressed as one, on exactly the stacks where
        // it would matter that it is missing.
        //
        // isSecure() is accurate behind nginx because framework.yaml trusts
        // x-forwarded-proto and nginx passes the scheme it terminated. In
        // development this is plainly false and the header stays off, which is
        // correct: localhost is a secure context to a browser, but pinning HSTS
        // on it would outlive the container in every developer's browser.
        if ($event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', self::STRICT_TRANSPORT_SECURITY);
        }
    }
}
