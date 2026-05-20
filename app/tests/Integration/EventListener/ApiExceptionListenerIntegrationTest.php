<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventListener;

use App\Tests\Support\AuthenticatedApiTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ApiExceptionListenerIntegrationTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
    }

    public function testUnknownApiRouteReturnsJsonNotHtml(): void
    {
        // HMAI-79: without ApiExceptionListener the RouterListener's
        // NotFoundHttpException would be rendered by Symfony's default error
        // template (HTML), confusing JS clients that await response.json().
        // The listener guarantees `^/api/*` always returns JSON.
        $this->client->request('GET', '/api/this-route-does-not-exist');

        self::assertResponseStatusCodeSame(404);
        self::assertStringStartsWith('application/json', (string) $this->client->getResponse()->headers->get('content-type'));

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('error', $data);
    }

    public function testUnknownFrontendRouteIsNotIntercepted(): void
    {
        // The listener must scope itself to `/api/*` so the Twig frontend
        // keeps its rendered HTML 404 page for unknown pages like /typo.
        $this->client->request('GET', '/this-page-does-not-exist');

        self::assertResponseStatusCodeSame(404);
        // Whatever the frontend renderer returns, it must NOT be the listener's
        // JSON shape. The presence of HTML doctype or just the absence of
        // "application/json" in content-type is enough.
        $contentType = (string) $this->client->getResponse()->headers->get('content-type');
        self::assertStringNotContainsString('application/json', $contentType);
    }
}
