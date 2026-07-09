<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pins the OpenAPI contract for the Music and YouTubeProgress modules
 * (HMAI-342): every endpoint is documented under the versioned
 * `/api/v1/{music,youtube-progress}*` surface with the right method, parameters,
 * request bodies, response codes (including the 202/400/429/503 external-integration
 * states) and `$ref`s to the shared components. Schemas mirror the real read models.
 */
final class MusicYouTubeApiDocTest extends WebTestCase
{
    public function testEveryMusicAndYouTubeEndpointIsDocumentedWithItsMethodAndTag(): void
    {
        $paths = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths');

        $expected = [
            'Music' => [
                '/api/v1/music/top-albums' => ['get'],
                '/api/v1/music/comparison' => ['get'],
                '/api/v1/music/collection' => ['get'],
                '/api/v1/music/history' => ['get'],
                '/api/v1/music/sessions' => ['post'],
            ],
            'YouTubeProgress' => [
                '/api/v1/youtube-progress/watchlist' => ['get'],
                '/api/v1/youtube-progress/sessions' => ['get'],
                '/api/v1/youtube-progress/sync' => ['post'],
                '/api/v1/youtube-progress/videos/{id}/start' => ['post'],
                '/api/v1/youtube-progress/videos/{id}/watched' => ['post'],
                '/api/v1/youtube-progress/sessions/{id}/push-to-youtube' => ['post'],
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

    public function testTopAlbumsDocumentsPeriodEnumAndAlbumArray(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/music/top-albums', 'get');

        $period = null;
        foreach ($get['parameters'] ?? [] as $param) {
            if ('period' === ($param['name'] ?? null)) {
                $period = $param;
            }
        }
        self::assertNotNull($period, 'top-albums must document the "period" query parameter.');
        self::assertSame(['7day', '1month', '3month', '6month', '12month', 'overall'], $period['schema']['enum'] ?? null);

        $items = $this->nestedArray($get, 'responses', '200', 'content', 'application/json', 'schema');
        self::assertSame('array', $items['type'] ?? null);
        self::assertSame('#/components/schemas/Album', $items['items']['$ref'] ?? null);

        // The provider-unavailable 503 is documented.
        self::assertArrayHasKey('503', $get['responses'] ?? []);
    }

    public function testComparisonDocumentsScoreAndBucketsWithExternalErrorCodes(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/music/comparison', 'get');

        $props = $this->nestedArray($get, 'responses', '200', 'content', 'application/json', 'schema', 'properties');
        foreach (['matchScore', 'ownedAndListened', 'wantList', 'dustyShelf', 'recentlyPlayedNotOwned'] as $field) {
            self::assertArrayHasKey($field, $props, sprintf('comparison must document "%s".', $field));
        }
        self::assertSame('#/components/schemas/Album', $props['ownedAndListened']['items']['$ref'] ?? null);
        self::assertSame('#/components/schemas/VinylRecord', $props['dustyShelf']['items']['$ref'] ?? null);

        // The Discogs rate-limit 429 resolves to the shared component.
        $tooMany = $this->nestedArray($get, 'responses', '429');
        self::assertSame('#/components/responses/TooManyRequestsError', $tooMany['$ref'] ?? null);
    }

    public function testCollectionReturnsVinylRecordArray(): void
    {
        $items = $this->nestedArray(
            $this->fetchSpec(static::createClient()),
            'paths',
            '/api/v1/music/collection',
            'get',
            'responses',
            '200',
            'content',
            'application/json',
            'schema',
        );
        self::assertSame('#/components/schemas/VinylRecord', $items['items']['$ref'] ?? null);
    }

    public function testHistoryDocumentsSourceEnumAndDateRange(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/music/history', 'get');

        $names = [];
        $source = null;
        foreach ($get['parameters'] ?? [] as $param) {
            $names[] = $param['name'] ?? null;
            if ('source' === ($param['name'] ?? null)) {
                $source = $param;
            }
        }
        foreach (['limit', 'source', 'from', 'to'] as $expected) {
            self::assertContains($expected, $names, sprintf('history must document the "%s" query parameter.', $expected));
        }
        self::assertSame(['lastfm_scrobble', 'lastfm_top_delta', 'manual'], $source['schema']['enum'] ?? null);

        $items = $this->nestedArray($get, 'responses', '200', 'content', 'application/json', 'schema');
        self::assertSame('#/components/schemas/ListeningSessionDTO', $items['items']['$ref'] ?? null);
    }

    public function testLogSessionDocumentsRequiredBodyAndBadRequest(): void
    {
        $post = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/music/sessions', 'post');

        $body = $this->nestedArray($post, 'requestBody', 'content', 'application/json', 'schema');
        self::assertSame(['artist', 'title', 'playedAt'], $body['required'] ?? null);

        // A non-object body is a 400; created returns the echoed session.
        $responses = $this->nestedArray($post, 'responses');
        self::assertArrayHasKey('400', $responses);
        self::assertArrayHasKey('201', $responses);
    }

    public function testWatchlistAndSessionsWrapTheReadModels(): void
    {
        $doc = $this->fetchSpec(static::createClient());

        $videos = $this->nestedArray($doc, 'paths', '/api/v1/youtube-progress/watchlist', 'get', 'responses', '200', 'content', 'application/json', 'schema', 'properties', 'videos');
        self::assertSame('#/components/schemas/VideoDTO', $videos['items']['$ref'] ?? null);

        $sessions = $this->nestedArray($doc, 'paths', '/api/v1/youtube-progress/sessions', 'get', 'responses', '200', 'content', 'application/json', 'schema', 'properties', 'sessions');
        self::assertSame('#/components/schemas/WatchSessionDTO', $sessions['items']['$ref'] ?? null);

        // The session read model nests its videos.
        $sessionVideos = $this->nestedArray($doc, 'components', 'schemas', 'WatchSessionDTO', 'properties', 'videos');
        self::assertSame('#/components/schemas/VideoDTO', $sessionVideos['items']['$ref'] ?? null);
    }

    public function testSyncDocumentsCountsAndUnconfiguredBadRequest(): void
    {
        $post = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/youtube-progress/sync', 'post');

        $props = $this->nestedArray($post, 'responses', '200', 'content', 'application/json', 'schema', 'properties');
        self::assertArrayHasKey('sessions_count', $props);
        self::assertArrayHasKey('videos_count', $props);

        // 400 when the playlist id is not configured.
        self::assertArrayHasKey('400', $post['responses'] ?? []);
    }

    public function testVideoAndSessionActionsDocument204AndNotFound(): void
    {
        $doc = $this->fetchSpec(static::createClient());

        foreach ([
            '/api/v1/youtube-progress/videos/{id}/start',
            '/api/v1/youtube-progress/videos/{id}/watched',
            '/api/v1/youtube-progress/sessions/{id}/push-to-youtube',
        ] as $path) {
            $responses = $this->nestedArray($doc, 'paths', $path, 'post', 'responses');
            self::assertArrayHasKey('204', $responses, sprintf('%s must document a 204.', $path));
            self::assertArrayNotHasKey('content', $responses['204']);
            self::assertSame('#/components/responses/NotFoundError', $responses['404']['$ref'] ?? null, sprintf('%s must $ref the shared 404.', $path));
        }
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
