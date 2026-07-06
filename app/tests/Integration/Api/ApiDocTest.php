<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiDocTest extends WebTestCase
{
    public function testOpenApiSpecificationIsPublicAndValid(): void
    {
        $client = static::createClient();
        // No X-API-Key header: the documentation must be reachable without it.
        $client->request('GET', '/api/doc.json');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode(), 'The OpenAPI spec must be reachable without an API key.');
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));

        $content = $response->getContent();
        self::assertIsString($content);
        $doc = json_decode($content, true);
        self::assertIsArray($doc);

        self::assertSame('3.1.0', $doc['openapi'] ?? null, 'The generated contract must be OpenAPI 3.1.');
        self::assertArrayHasKey('info', $doc);
        self::assertIsArray($doc['info']);
        self::assertSame('AI Home Manager API', $doc['info']['title'] ?? null);
        self::assertArrayHasKey('version', $doc['info']);
        self::assertArrayHasKey('servers', $doc);
        self::assertNotEmpty($doc['servers']);
    }

    public function testSwaggerUiIsPublicAndRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
    }

    public function testRedocIsPublicAndRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc/redoc');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
    }
}
