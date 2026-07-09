<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pins the OpenAPI contract for the Series module and the public `/api/health`
 * probe (HMAI-339): every endpoint is documented under the versioned
 * `/api/v1/series*` surface with the right method, parameters, request bodies,
 * response codes and `$ref`s to the shared components (HMAI-337). Schemas mirror
 * the real DTO shapes emitted by SeriesDetailDTONormalizer.
 */
final class SeriesApiDocTest extends WebTestCase
{
    public function testEverySeriesEndpointIsDocumentedWithItsMethod(): void
    {
        $paths = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths');

        foreach ([
            '/api/v1/series' => ['get', 'post'],
            '/api/v1/series/{id}' => ['get', 'patch', 'delete'],
            '/api/v1/series/import/trakt' => ['post'],
            '/api/v1/series/{seriesId}/seasons' => ['post'],
            '/api/v1/series/{seriesId}/seasons/{seasonId}' => ['patch', 'delete'],
            '/api/v1/series/{seriesId}/seasons/{seasonId}/rating' => ['patch'],
            '/api/v1/series/{seriesId}/rating' => ['patch'],
            '/api/v1/series/{seriesId}/seasons/{seasonId}/episodes' => ['post'],
            '/api/v1/series/{seriesId}/seasons/{seasonId}/episodes/{episodeId}' => ['patch', 'delete'],
            '/api/v1/series/{seriesId}/seasons/{seasonId}/episodes/{episodeId}/rating' => ['patch'],
            '/api/v1/series/{seriesId}/seasons/{seasonId}/episodes/{episodeId}/watched' => ['patch'],
        ] as $path => $methods) {
            self::assertArrayHasKey($path, $paths, sprintf('Missing documented path "%s".', $path));
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $paths[$path], sprintf('Path "%s" must document the "%s" operation.', $path, strtoupper($method)));
                self::assertContains('Series', $paths[$path][$method]['tags'] ?? [], sprintf('%s %s must be tagged "Series".', strtoupper($method), $path));
            }
        }
    }

    public function testListReturnsAnArrayOfSeriesDetailAndDetailRefsTheSameSchema(): void
    {
        $doc = $this->fetchSpec(static::createClient());

        $list = $this->nestedArray($doc, 'paths', '/api/v1/series', 'get', 'responses', '200', 'content', 'application/json', 'schema');
        self::assertSame('array', $list['type'] ?? null);
        self::assertSame('#/components/schemas/SeriesDetailDTO', $list['items']['$ref'] ?? null);

        $detail = $this->nestedArray($doc, 'paths', '/api/v1/series/{id}', 'get', 'responses', '200', 'content', 'application/json', 'schema');
        self::assertSame('#/components/schemas/SeriesDetailDTO', $detail['$ref'] ?? null);

        $notFound = $this->nestedArray($doc, 'paths', '/api/v1/series/{id}', 'get', 'responses', '404');
        self::assertSame('#/components/responses/NotFoundError', $notFound['$ref'] ?? null);
    }

    public function testCreateDocumentsTitleRequiredWithMetadataAndCreatedId(): void
    {
        $post = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/series', 'post');

        $body = $this->nestedArray($post, 'requestBody', 'content', 'application/json', 'schema');
        self::assertSame(['title'], $body['required'] ?? null);
        foreach (['title', 'coverUrl', 'year', 'status', 'description'] as $field) {
            self::assertArrayHasKey($field, $body['properties'] ?? [], sprintf('Create body must document "%s".', $field));
        }
        self::assertSame(['ongoing', 'ended'], $body['properties']['status']['enum'] ?? null);

        $created = $this->nestedArray($post, 'responses', '201', 'content', 'application/json', 'schema');
        self::assertArrayHasKey('id', $created['properties'] ?? []);
    }

    public function testRatingEndpointsDocumentTheNullableRatingBodyAndErrors(): void
    {
        $doc = $this->fetchSpec(static::createClient());

        // Series own rating: a bounded, nullable integer (null clears it).
        $rating = $this->nestedArray($doc, 'paths', '/api/v1/series/{seriesId}/rating', 'patch', 'requestBody', 'content', 'application/json', 'schema', 'properties', 'rating');
        self::assertSame(1, $rating['minimum'] ?? null);
        self::assertSame(10, $rating['maximum'] ?? null);

        // 204 carries no body; validation failure resolves to the shared component.
        $noContent = $this->nestedArray($doc, 'paths', '/api/v1/series/{seriesId}/rating', 'patch', 'responses', '204');
        self::assertArrayNotHasKey('content', $noContent);
        $unprocessable = $this->nestedArray($doc, 'paths', '/api/v1/series/{seriesId}/rating', 'patch', 'responses', '422');
        self::assertSame('#/components/responses/UnprocessableEntityError', $unprocessable['$ref'] ?? null);
    }

    public function testWatchedEndpointDocumentsABooleanFlag(): void
    {
        $watched = $this->nestedArray(
            $this->fetchSpec(static::createClient()),
            'paths',
            '/api/v1/series/{seriesId}/seasons/{seasonId}/episodes/{episodeId}/watched',
            'patch',
            'requestBody',
            'content',
            'application/json',
            'schema',
            'properties',
            'watched',
        );
        self::assertSame('boolean', $watched['type'] ?? null);
    }

    public function testTraktImportDocumentsAcceptedAndConflict(): void
    {
        $post = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/series/import/trakt', 'post');

        // 202 returns the async "import_started" status.
        $accepted = $this->nestedArray($post, 'responses', '202', 'content', 'application/json', 'schema', 'properties', 'status');
        self::assertSame(['import_started'], $accepted['enum'] ?? null);

        // 409 carries both the error and the authUrl hint (not just the shared Error).
        $conflict = $this->nestedArray($post, 'responses', '409', 'content', 'application/json', 'schema', 'properties');
        self::assertArrayHasKey('error', $conflict);
        self::assertArrayHasKey('authUrl', $conflict);
    }

    public function testRenumberSeasonDocumentsTheConflictResponse(): void
    {
        $conflict = $this->nestedArray(
            $this->fetchSpec(static::createClient()),
            'paths',
            '/api/v1/series/{seriesId}/seasons/{seasonId}',
            'patch',
            'responses',
            '409',
        );
        self::assertSame('#/components/responses/ConflictError', $conflict['$ref'] ?? null);
    }

    public function testSeriesDetailSchemaMirrorsTheNormalizerShape(): void
    {
        $doc = $this->fetchSpec(static::createClient());

        // Show-level: the disjoint own rating + computed average + watched counters.
        $series = $this->nestedArray($doc, 'components', 'schemas', 'SeriesDetailDTO', 'properties');
        foreach (['id', 'title', 'createdAt', 'coverUrl', 'year', 'status', 'description', 'rating', 'averageRating', 'watchedCount', 'episodeCount', 'seasons'] as $field) {
            self::assertArrayHasKey($field, $series, sprintf('SeriesDetailDTO must document "%s".', $field));
        }
        self::assertSame('#/components/schemas/SeasonDTO', $series['seasons']['items']['$ref'] ?? null);

        // Season-level nests episodes and carries its own rating + counters.
        $season = $this->nestedArray($doc, 'components', 'schemas', 'SeasonDTO', 'properties');
        foreach (['id', 'number', 'rating', 'averageRating', 'watchedCount', 'episodeCount', 'episodes'] as $field) {
            self::assertArrayHasKey($field, $season, sprintf('SeasonDTO must document "%s".', $field));
        }
        self::assertSame('#/components/schemas/EpisodeDTO', $season['episodes']['items']['$ref'] ?? null);

        // Episode-level: number, rating and the watched flag/date.
        $episode = $this->nestedArray($doc, 'components', 'schemas', 'EpisodeDTO', 'properties');
        foreach (['id', 'title', 'number', 'rating', 'watched', 'watchedAt'] as $field) {
            self::assertArrayHasKey($field, $episode, sprintf('EpisodeDTO must document "%s".', $field));
        }
    }

    public function testHealthProbeIsPublicAndDocumentsItsStatusStates(): void
    {
        $health = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/health', 'get');

        self::assertContains('Health', $health['tags'] ?? [], 'The health probe must be tagged "Health".');
        self::assertSame([], $health['security'] ?? null, 'The health probe must be documented as public (security: []).');

        $ok = $this->nestedArray($health, 'responses', '200', 'content', 'application/json', 'schema', 'properties', 'status');
        self::assertSame(['healthy', 'degraded'], $ok['enum'] ?? null);

        $down = $this->nestedArray($health, 'responses', '503', 'content', 'application/json', 'schema', 'properties', 'status');
        self::assertSame(['unhealthy'], $down['enum'] ?? null);
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
