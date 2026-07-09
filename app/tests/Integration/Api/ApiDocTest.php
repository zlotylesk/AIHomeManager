<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\EventListener\RequestIdListener;
use App\Security\ApiKeyAuthenticator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiDocTest extends WebTestCase
{
    public function testOpenApiSpecificationIsPublicAndValid(): void
    {
        $client = static::createClient();
        $doc = $this->fetchSpec($client);

        // No X-API-Key header: the documentation must be reachable without it.
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('Content-Type'));

        self::assertSame('3.1.0', $doc['openapi'] ?? null, 'The generated contract must be OpenAPI 3.1.');
        $info = $this->nestedArray($doc, 'info');
        self::assertSame('AI Home Manager API', $info['title'] ?? null);
        self::assertArrayHasKey('version', $info);
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

    public function testSecuritySchemeAndGlobalSecurityAreDefined(): void
    {
        $doc = $this->fetchSpec(static::createClient());

        // Every operation defaults to the API key unless it opts out with `security: []`.
        self::assertArrayHasKey('security', $doc);
        self::assertIsArray($doc['security']);
        self::assertContains(['ApiKeyAuth' => []], $doc['security'], 'The API key must be the global default security.');

        // The apiKey/header scheme must mirror ApiKeyAuthenticator::HEADER.
        $scheme = $this->nestedArray($doc, 'components', 'securitySchemes', 'ApiKeyAuth');
        self::assertSame('apiKey', $scheme['type'] ?? null);
        self::assertSame('header', $scheme['in'] ?? null);
        self::assertSame(ApiKeyAuthenticator::HEADER, $scheme['name'] ?? null);
    }

    public function testReusableErrorComponentsAreDefined(): void
    {
        $doc = $this->fetchSpec(static::createClient());

        // Error envelope { "error": string } — mirrors ApiExceptionListener.
        $error = $this->nestedArray($doc, 'components', 'schemas', 'Error');
        self::assertSame('object', $error['type'] ?? null);
        $props = $this->nestedArray($doc, 'components', 'schemas', 'Error', 'properties');
        self::assertArrayHasKey('error', $props);

        // Ready-to-$ref responses covering the whole 401/404/409/422/429/500 surface.
        $responses = $this->nestedArray($doc, 'components', 'responses');
        foreach ([
            'UnauthorizedError',
            'NotFoundError',
            'ConflictError',
            'UnprocessableEntityError',
            'TooManyRequestsError',
            'InternalServerError',
        ] as $name) {
            self::assertArrayHasKey($name, $responses, sprintf('Missing reusable "%s" response.', $name));
        }
    }

    public function testRateLimitResponseCarriesRetryContract(): void
    {
        $doc = $this->fetchSpec(static::createClient());

        // The 429 response must expose the rate-limit headers ApiRateLimitListener emits.
        $tooMany = $this->nestedArray($doc, 'components', 'responses', 'TooManyRequestsError');
        $headers = $this->nestedArray($tooMany, 'headers');
        foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $header) {
            self::assertArrayHasKey($header, $headers, sprintf('The 429 response must document the "%s" header.', $header));
        }

        // RateLimitError = Error + retry_after (the { error, retry_after } 429 body).
        $rateLimitError = $this->nestedArray($doc, 'components', 'schemas', 'RateLimitError');
        self::assertArrayHasKey('allOf', $rateLimitError, 'RateLimitError must compose the shared Error schema.');
    }

    public function testRateLimitAndCorrelationHeadersAreDefined(): void
    {
        $doc = $this->fetchSpec(static::createClient());

        $headers = $this->nestedArray($doc, 'components', 'headers');
        foreach ([
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
            'Retry-After',
            RequestIdListener::HEADER_NAME,
        ] as $header) {
            self::assertArrayHasKey($header, $headers, sprintf('Missing reusable "%s" header component.', $header));
        }
    }

    public function testPaginationSchemaIsDefined(): void
    {
        $doc = $this->fetchSpec(static::createClient());

        $pagination = $this->nestedArray($doc, 'components', 'schemas', 'Pagination');
        self::assertSame('object', $pagination['type'] ?? null);
        self::assertArrayHasKey('properties', $pagination);
    }

    public function testHealthEndpointIsMarkedPublic(): void
    {
        $doc = $this->fetchSpec(static::createClient());

        // The public readiness probe opts out of the global security, mirroring
        // ApiKeyAuthenticator::supports() which bypasses /api/health.
        $health = $this->nestedArray($doc, 'paths', '/api/health', 'get');
        self::assertArrayHasKey('security', $health, 'The public health probe must override the global security.');
        self::assertSame([], $health['security'], 'The health probe must be documented as public (security: []).');
    }

    /**
     * Fetch the generated OpenAPI document without an API key (it must be public).
     *
     * @return array<mixed>
     */
    private function fetchSpec(KernelBrowser $client): array
    {
        $client->request('GET', '/api/doc.json');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode(), 'The OpenAPI spec must be reachable without an API key.');

        $content = $response->getContent();
        self::assertIsString($content);
        $doc = json_decode($content, true);
        self::assertIsArray($doc);

        return $doc;
    }

    /**
     * Navigate a decoded-JSON tree, asserting each level exists and is an array.
     *
     * @param array<mixed> $tree
     *
     * @return array<mixed>
     */
    private function nestedArray(array $tree, string ...$keys): array
    {
        $node = $tree;
        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $node, sprintf('Missing "%s" in the OpenAPI document.', $key));
            self::assertIsArray($node[$key], sprintf('"%s" must be an object in the OpenAPI document.', $key));
            $node = $node[$key];
        }

        return $node;
    }
}
