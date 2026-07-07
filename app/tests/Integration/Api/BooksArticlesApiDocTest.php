<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pins the OpenAPI contract for the Books and Articles modules (HMAI-341):
 * every endpoint is documented under the versioned `/api/v1/{books,articles}*`
 * surface with the right method, parameters, request bodies, response codes and
 * `$ref`s to the shared components (HMAI-337). Schemas mirror the real DTO shapes
 * emitted by the Book/Article normalizers.
 */
final class BooksArticlesApiDocTest extends WebTestCase
{
    public function testEveryBooksAndArticlesEndpointIsDocumentedWithItsMethodAndTag(): void
    {
        $paths = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths');

        $expected = [
            'Books' => [
                '/api/v1/books' => ['get', 'post'],
                '/api/v1/books/export' => ['get'],
                '/api/v1/books/{id}' => ['get', 'put', 'delete'],
                '/api/v1/books/{id}/reading-sessions' => ['post'],
            ],
            'Articles' => [
                '/api/v1/articles' => ['get', 'post'],
                '/api/v1/articles/export' => ['get'],
                '/api/v1/articles/import' => ['post'],
                '/api/v1/articles/today' => ['get'],
                '/api/v1/articles/{id}' => ['get', 'put', 'delete'],
                '/api/v1/articles/{id}/read' => ['post'],
            ],
        ];

        foreach ($expected as $tag => $routes) {
            foreach ($routes as $path => $methods) {
                self::assertArrayHasKey($path, $paths, sprintf('Missing documented path "%s".', $path));
                foreach ($methods as $method) {
                    self::assertArrayHasKey($method, $paths[$path], sprintf('Path "%s" must document the "%s" operation.', $path, strtoupper($method)));
                    self::assertContains($tag, $paths[$path][$method]['tags'] ?? [], sprintf('%s %s must be tagged "%s".', strtoupper($method), $path, $tag));
                }
            }
        }
    }

    public function testBooksListDocumentsStatusEnumAndBookArray(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/books', 'get');

        $status = null;
        foreach ($get['parameters'] ?? [] as $param) {
            if ('status' === ($param['name'] ?? null)) {
                $status = $param;
            }
        }
        self::assertNotNull($status, 'The books list must document the "status" query parameter.');
        self::assertSame(['to_read', 'reading', 'completed'], $status['schema']['enum'] ?? null);

        $items = $this->nestedArray($get, 'responses', '200', 'content', 'application/json', 'schema');
        self::assertSame('array', $items['type'] ?? null);
        self::assertSame('#/components/schemas/BookDTO', $items['items']['$ref'] ?? null);
    }

    public function testBookDetailComposesBookDtoWithReadingSessions(): void
    {
        $doc = $this->fetchSpec(static::createClient());
        $schema = $this->nestedArray($doc, 'paths', '/api/v1/books/{id}', 'get', 'responses', '200', 'content', 'application/json', 'schema');

        // The detail body flattens the BookDTO fields and appends the sessions list.
        self::assertArrayHasKey('allOf', $schema, 'The book detail body must compose BookDTO + sessions.');
        $refs = array_column($schema['allOf'], '$ref');
        self::assertContains('#/components/schemas/BookDTO', $refs);

        $sessions = null;
        foreach ($schema['allOf'] as $part) {
            if (isset($part['properties']['sessions'])) {
                $sessions = $part['properties']['sessions'];
            }
        }
        self::assertNotNull($sessions, 'The book detail must document the "sessions" array.');
        self::assertSame('#/components/schemas/ReadingSessionDTO', $sessions['items']['$ref'] ?? null);

        $notFound = $this->nestedArray($doc, 'paths', '/api/v1/books/{id}', 'get', 'responses', '404');
        self::assertSame('#/components/responses/NotFoundError', $notFound['$ref'] ?? null);
    }

    public function testAddBookDocumentsIsbnRequiredAndMetadataUnavailable(): void
    {
        $post = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/books', 'post');

        $body = $this->nestedArray($post, 'requestBody', 'content', 'application/json', 'schema');
        self::assertSame(['isbn'], $body['required'] ?? null);
        foreach (['isbn', 'cover_url', 'total_pages'] as $field) {
            self::assertArrayHasKey($field, $body['properties'] ?? [], sprintf('Add-book body must document "%s".', $field));
        }

        // The provider-unavailable 503 is part of the contract.
        self::assertArrayHasKey('503', $post['responses'] ?? [], 'Add-book must document the 503 provider-unavailable response.');
    }

    public function testBooksExportOffersBothBinaryFormats(): void
    {
        $content = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/books/export', 'get', 'responses', '200', 'content');
        self::assertArrayHasKey('text/csv', $content);
        self::assertArrayHasKey('application/pdf', $content);
        self::assertSame('binary', $content['application/pdf']['schema']['format'] ?? null);
    }

    public function testLogReadingSessionDocumentsPagesReadRequired(): void
    {
        $body = $this->nestedArray(
            $this->fetchSpec(static::createClient()),
            'paths',
            '/api/v1/books/{id}/reading-sessions',
            'post',
            'requestBody',
            'content',
            'application/json',
            'schema',
        );
        self::assertSame(['pages_read'], $body['required'] ?? null);
        self::assertSame('integer', $body['properties']['pages_read']['type'] ?? null);
    }

    public function testArticlesListAndTodayDocumentTheArticleSchema(): void
    {
        $doc = $this->fetchSpec(static::createClient());

        $items = $this->nestedArray($doc, 'paths', '/api/v1/articles', 'get', 'responses', '200', 'content', 'application/json', 'schema');
        self::assertSame('#/components/schemas/ArticleDTO', $items['items']['$ref'] ?? null);

        // The article-of-the-day returns the article or an empty 204.
        $today = $this->nestedArray($doc, 'paths', '/api/v1/articles/today', 'get', 'responses');
        self::assertArrayHasKey('200', $today);
        self::assertArrayHasKey('204', $today);
        self::assertArrayNotHasKey('content', $today['204']);
    }

    public function testArticleImportDocumentsAMultipartFileUpload(): void
    {
        $schema = $this->nestedArray(
            $this->fetchSpec(static::createClient()),
            'paths',
            '/api/v1/articles/import',
            'post',
            'requestBody',
            'content',
            'multipart/form-data',
            'schema',
        );
        self::assertSame(['file'], $schema['required'] ?? null);
        self::assertSame('binary', $schema['properties']['file']['format'] ?? null);
        self::assertArrayHasKey('dry_run', $schema['properties'] ?? []);
    }

    public function testCreateArticleDocumentsTitleAndUrlRequired(): void
    {
        $doc = $this->fetchSpec(static::createClient());

        $body = $this->nestedArray($doc, 'paths', '/api/v1/articles', 'post', 'requestBody', 'content', 'application/json', 'schema');
        self::assertSame(['title', 'url'], $body['required'] ?? null);

        // Validation failure and not-found resolve to the shared components.
        $unprocessable = $this->nestedArray($doc, 'paths', '/api/v1/articles', 'post', 'responses', '422');
        self::assertSame('#/components/responses/UnprocessableEntityError', $unprocessable['$ref'] ?? null);
        $notFound = $this->nestedArray($doc, 'paths', '/api/v1/articles/{id}/read', 'post', 'responses', '404');
        self::assertSame('#/components/responses/NotFoundError', $notFound['$ref'] ?? null);
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
