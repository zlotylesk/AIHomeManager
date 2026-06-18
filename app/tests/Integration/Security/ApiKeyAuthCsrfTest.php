<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Regression cover for HMAI-57 — the explicit "stateless+API key, no CSRF
 * tokens" decision documented in docs/HMAI-57.md.
 *
 * The threat model the original code-review reviewer worried about: a victim
 * with an active session visits an attacker's page, which submits a form to
 * /api/series. Our defence is that the firewall is stateless and authorises
 * via a custom header (X-API-Key) — a header the browser will not auto-send
 * cross-origin. These tests pin both halves: no header → 401 even with
 * cookies present, and the firewall stays stateless so no session is created.
 */
final class ApiKeyAuthCsrfTest extends WebTestCase
{
    public function testPostMutationWithoutApiKeyIsRejectedEvenWithSessionCookie(): void
    {
        $client = static::createClient();
        $client->getCookieJar()->set(new Cookie('PHPSESSID', 'fake-session-cookie'));

        $client->request('POST', '/api/series', content: (string) json_encode(['title' => 'CSRF Probe']));

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testPutMutationWithoutApiKeyIsRejectedEvenWithSessionCookie(): void
    {
        $client = static::createClient();
        $client->getCookieJar()->set(new Cookie('PHPSESSID', 'fake-session-cookie'));

        $client->request(
            'PUT',
            '/api/books/00000000-0000-0000-0000-000000000000',
            content: (string) json_encode(['title' => 'X', 'author' => 'Y', 'publisher' => 'Z', 'year' => 2020])
        );

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testDeleteMutationWithoutApiKeyIsRejectedEvenWithSessionCookie(): void
    {
        $client = static::createClient();
        $client->getCookieJar()->set(new Cookie('PHPSESSID', 'fake-session-cookie'));

        $client->request('DELETE', '/api/articles/00000000-0000-0000-0000-000000000000');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testApiResponseDoesNotIssueSessionCookie(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_X_API_KEY', 'test-api-key');
        $client->request('GET', '/api/series');

        $cookies = $client->getResponse()->headers->getCookies();
        self::assertSame([], $cookies, 'API responses must not set cookies (stateless firewall).');
    }
}
