<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pins the OpenAPI contract for the Search module (HMAI-269): the
 * `/api/v1/search` operation is documented, `Search`-tagged, exposes its query
 * parameters (incl. the type enum) and returns an array of the `SearchResult`
 * schema plus the shared 401/422 error responses.
 */
final class SearchApiDocTest extends WebTestCase
{
    public function testSearchEndpointIsDocumentedAndTagged(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/search', 'get');

        self::assertContains('Search', $get['tags'] ?? [], 'GET /search must be tagged "Search".');
    }

    public function testSearchDocumentsQueryParametersAndTypeEnum(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/search', 'get');

        $params = [];
        foreach ($get['parameters'] ?? [] as $param) {
            $params[$param['name'] ?? ''] = $param;
        }

        self::assertArrayHasKey('q', $params);
        self::assertTrue($params['q']['required'] ?? false, 'The "q" phrase must be required.');
        self::assertArrayHasKey('type', $params);
        self::assertSame(['article', 'book', 'series', 'music', 'task'], $params['type']['schema']['enum'] ?? null);
        self::assertArrayHasKey('page', $params);
        self::assertArrayHasKey('perPage', $params);
    }

    public function testSearchResponseIsAnArrayOfSearchResultWithErrorResponses(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/search', 'get');

        $schema = $this->nestedArray($get, 'responses', '200', 'content', 'application/json', 'schema');
        self::assertSame('array', $schema['type'] ?? null);
        self::assertSame('#/components/schemas/SearchResult', $schema['items']['$ref'] ?? null);

        $responses = $this->nestedArray($get, 'responses');
        self::assertArrayHasKey('401', $responses);
        self::assertArrayHasKey('422', $responses);
    }

    /**
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
