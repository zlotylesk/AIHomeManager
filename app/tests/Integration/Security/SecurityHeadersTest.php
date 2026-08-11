<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\EventListener\SecurityHeadersListener;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityHeadersTest extends WebTestCase
{
    public function testFrontendPageHasFrameDeny(): void
    {
        $client = static::createClient();
        $client->request('GET', '/series');

        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
    }

    public function testApiHealthHasNoSniff(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
    }

    public function testApiEndpointHasReferrerPolicy(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseHeaderSame('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function testStrictTransportSecurityIsSentOverHttps(): void
    {
        $client = static::createClient();
        $client->request('GET', 'https://localhost/api/health');

        self::assertResponseHeaderSame(
            'Strict-Transport-Security',
            SecurityHeadersListener::STRICT_TRANSPORT_SECURITY,
        );
    }

    /**
     * The half of the rule that is easy to lose: a header set unconditionally
     * would still pass the test above.
     *
     * RFC 6797 has the browser ignore Strict-Transport-Security received over
     * plain HTTP, so an unconditional header would not protect the development
     * stack — it would only make the suite claim a protection that no client
     * applies, which is the failure this whole ticket exists to stop repeating.
     */
    public function testStrictTransportSecurityIsAbsentOverPlainHttp(): void
    {
        $client = static::createClient();
        $client->request('GET', 'http://localhost/api/health');

        self::assertFalse(
            $client->getResponse()->headers->has('Strict-Transport-Security'),
            'HSTS was sent over a plain-HTTP connection, where a browser must ignore it.',
        );
    }

    public function testErrorResponseHasAllSecurityHeaders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/nonexistent-page-for-header-test');

        $headers = $client->getResponse()->headers;

        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
        self::assertSame('geolocation=(), microphone=(), camera=(), payment=()', $headers->get('Permissions-Policy'));
    }
}
