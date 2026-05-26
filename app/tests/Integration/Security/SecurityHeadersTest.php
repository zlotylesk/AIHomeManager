<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

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
