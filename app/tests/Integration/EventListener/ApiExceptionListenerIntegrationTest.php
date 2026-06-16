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
        $this->client->request('GET', '/api/this-route-does-not-exist');

        self::assertResponseStatusCodeSame(404);
        self::assertStringStartsWith('application/json', (string) $this->client->getResponse()->headers->get('content-type'));

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('error', $data);
    }

    public function testUnknownFrontendRouteIsNotIntercepted(): void
    {
        $this->client->request('GET', '/this-page-does-not-exist');

        self::assertResponseStatusCodeSame(404);

        $contentType = (string) $this->client->getResponse()->headers->get('content-type');
        self::assertStringNotContainsString('application/json', $contentType);
    }
}
