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

    /**
     * HMAI-434: during a rotation window the previous key must keep working
     * alongside the current one, or a client swapping over on its own schedule
     * gets locked out the moment the server-side value changes. `app/.env.test`
     * sets `API_KEY_PREVIOUS='test-api-key-previous'` for exactly this case.
     */
    public function testApiRequestWithPreviousKeyDuringRotationIsAuthorized(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_X_API_KEY', 'test-api-key-previous');
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

    public function testOpenApiSpecRouteIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc.json');

        self::assertNotSame(401, $client->getResponse()->getStatusCode());
    }

    public function testSwaggerUiRouteIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc');

        self::assertNotSame(401, $client->getResponse()->getStatusCode());
    }

    public function testAuthenticatorHeaderConstantMatchesConvention(): void
    {
        self::assertSame('X-API-Key', ApiKeyAuthenticator::HEADER);
    }
}
