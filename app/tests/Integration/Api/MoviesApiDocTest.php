<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pins the OpenAPI contract for the Movies module (HMAI-291 documented the
 * operations; this closes the epic HMAI-285 test pyramid by asserting them): every
 * `/api/v1/movies*` operation is documented, `Movies`-tagged, references the
 * `MovieDTO` schema on its read path, carries the shared error responses, and
 * documents the watched/rating request bodies + the Trakt import 202/409 contract.
 */
final class MoviesApiDocTest extends WebTestCase
{
    /**
     * @return array<string, array{0: string, 1: string}> path → [method, opId label]
     */
    public static function movieOperations(): array
    {
        return [
            'list' => ['/api/v1/movies', 'get'],
            'create' => ['/api/v1/movies', 'post'],
            'detail' => ['/api/v1/movies/{id}', 'get'],
            'update' => ['/api/v1/movies/{id}', 'patch'],
            'delete' => ['/api/v1/movies/{id}', 'delete'],
            'watched' => ['/api/v1/movies/{id}/watched', 'patch'],
            'rating' => ['/api/v1/movies/{id}/rating', 'patch'],
            'import' => ['/api/v1/movies/import/trakt', 'post'],
        ];
    }

    public function testEveryMovieOperationIsDocumentedAndTagged(): void
    {
        $spec = $this->fetchSpec(static::createClient());

        foreach (self::movieOperations() as $label => [$path, $method]) {
            $operation = $this->nestedArray($spec, 'paths', $path, $method);
            self::assertContains(
                'Movies',
                $operation['tags'] ?? [],
                sprintf('%s %s (%s) must be tagged "Movies".', strtoupper($method), $path, $label),
            );
        }
    }

    public function testListReturnsAnArrayOfMovieModels(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/movies', 'get');

        $schema = $this->nestedArray($get, 'responses', '200', 'content', 'application/json', 'schema');
        self::assertSame('array', $schema['type'] ?? null);
        self::assertSame('#/components/schemas/MovieDTO', $schema['items']['$ref'] ?? null);

        // The optional watched filter is a documented query parameter.
        $names = array_column($get['parameters'] ?? [], 'name');
        self::assertContains('watched', $names, 'GET /movies must document the "watched" query filter.');
    }

    public function testDetailReferencesMovieModelWithNotFound(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/movies/{id}', 'get');

        $schema = $this->nestedArray($get, 'responses', '200', 'content', 'application/json', 'schema');
        self::assertSame('#/components/schemas/MovieDTO', $schema['$ref'] ?? null);
        self::assertArrayHasKey('404', $this->nestedArray($get, 'responses'));
    }

    public function testCreateRequiresTitleAndReturnsId(): void
    {
        $post = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/movies', 'post');

        $body = $this->nestedArray($post, 'requestBody', 'content', 'application/json', 'schema');
        self::assertContains('title', $body['required'] ?? [], 'POST /movies must require "title".');
        self::assertArrayHasKey('status', $body['properties'] ?? [], 'POST /movies must document the "status" metadata field.');

        $created = $this->nestedArray($post, 'responses', '201', 'content', 'application/json', 'schema');
        self::assertArrayHasKey('id', $created['properties'] ?? []);
        self::assertArrayHasKey('422', $this->nestedArray($post, 'responses'));
    }

    public function testUpdateIsPartialSafeAndCarriesSharedErrors(): void
    {
        $patch = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/movies/{id}', 'patch');

        $properties = $this->nestedArray($patch, 'requestBody', 'content', 'application/json', 'schema')['properties'] ?? [];
        foreach (['title', 'coverUrl', 'year', 'status', 'description'] as $field) {
            self::assertArrayHasKey($field, $properties, sprintf('PATCH /movies/{id} must document the "%s" field.', $field));
        }

        $responses = $this->nestedArray($patch, 'responses');
        self::assertArrayHasKey('204', $responses);
        self::assertArrayHasKey('404', $responses);
        self::assertArrayHasKey('422', $responses);
    }

    public function testWatchedAndRatingBodiesAreDocumented(): void
    {
        $spec = $this->fetchSpec(static::createClient());

        $watched = $this->nestedArray($spec, 'paths', '/api/v1/movies/{id}/watched', 'patch');
        $watchedBody = $this->nestedArray($watched, 'requestBody', 'content', 'application/json', 'schema');
        self::assertContains('watched', $watchedBody['required'] ?? []);
        self::assertSame('boolean', $watchedBody['properties']['watched']['type'] ?? null);

        $rating = $this->nestedArray($spec, 'paths', '/api/v1/movies/{id}/rating', 'patch');
        $ratingProp = $this->nestedArray($rating, 'requestBody', 'content', 'application/json', 'schema')['properties']['rating'] ?? [];
        self::assertSame(1, $ratingProp['minimum'] ?? null);
        self::assertSame(10, $ratingProp['maximum'] ?? null);
    }

    public function testTraktImportDocumentsStartedAndConflict(): void
    {
        $post = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/movies/import/trakt', 'post');

        $started = $this->nestedArray($post, 'responses', '202', 'content', 'application/json', 'schema');
        self::assertContains('import_started', $started['properties']['status']['enum'] ?? []);

        $conflict = $this->nestedArray($post, 'responses', '409', 'content', 'application/json', 'schema');
        self::assertArrayHasKey('authUrl', $conflict['properties'] ?? [], 'The 409 body must expose "authUrl".');
    }

    public function testMovieModelExposesEveryReadField(): void
    {
        $schema = $this->nestedArray($this->fetchSpec(static::createClient()), 'components', 'schemas', 'MovieDTO');

        $properties = $schema['properties'] ?? [];
        foreach (['id', 'title', 'watched', 'watchedAt', 'rating', 'coverUrl', 'year', 'status', 'description', 'createdAt'] as $field) {
            self::assertArrayHasKey($field, $properties, sprintf('MovieDTO must document the "%s" field.', $field));
        }
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
