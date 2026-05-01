<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Security\ApiKeyAuthenticator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiKeyAuthTest extends WebTestCase
{
    public function testApiRequestWithoutKeyReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/series');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testApiRequestWithInvalidKeyReturns401(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_X_API_KEY', 'wrong-key');
        $client->request('GET', '/api/series');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testApiRequestWithValidKeyIsAuthorized(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_X_API_KEY', 'test-api-key');
        $client->request('GET', '/api/series');

        self::assertNotSame(401, $client->getResponse()->getStatusCode());
    }

    public function testFrontendRouteIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertNotSame(401, $client->getResponse()->getStatusCode());
    }

    public function testGoogleAuthRouteIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/auth/google');

        self::assertNotSame(401, $client->getResponse()->getStatusCode());
    }

    public function testAuthenticatorHeaderConstantMatchesConvention(): void
    {
        self::assertSame('X-API-Key', ApiKeyAuthenticator::HEADER);
    }
}
