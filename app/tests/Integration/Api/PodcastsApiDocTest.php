<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pins the OpenAPI contract for the Podcasts module, reaching parity with the
 * other modules' *ApiDocTest: every `/api/v1/podcasts*` operation is documented
 * and `Podcasts`-tagged, the read schemas expose every field the frontend
 * consumes, and the sync trigger's 202/409 split is part of the contract.
 */
final class PodcastsApiDocTest extends WebTestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function podcastOperations(): array
    {
        return [
            'list' => ['/api/v1/podcasts', 'get'],
            'detail' => ['/api/v1/podcasts/{id}', 'get'],
            'sync' => ['/api/v1/podcasts/sync', 'post'],
        ];
    }

    public function testEveryPodcastOperationIsDocumentedAndTagged(): void
    {
        $spec = $this->fetchSpec(static::createClient());

        foreach (self::podcastOperations() as $label => [$path, $method]) {
            $operation = $this->nestedArray($spec, 'paths', $path, $method);
            self::assertContains(
                'Podcasts',
                $operation['tags'] ?? [],
                sprintf('%s %s (%s) must be tagged "Podcasts".', strtoupper($method), $path, $label),
            );
        }
    }

    public function testListReturnsAnArrayOfPodcastModels(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/podcasts', 'get');

        $schema = $this->nestedArray($get, 'responses', '200', 'content', 'application/json', 'schema');
        self::assertSame('array', $schema['type'] ?? null);
        self::assertStringContainsString('PodcastDTO', $schema['items']['$ref'] ?? '');
    }

    /**
     * The detail is documented as allOf[PodcastDTO, {episodes, sessions}] rather
     * than a $ref to PodcastDetailDTO, because the normalizer flattens the show
     * to the top level — the DTO's own `podcast` key never reaches the wire
     * (the BookDetailDTO precedent).
     */
    public function testDetailFlattensTheShowAndDocumentsTheMiss(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/podcasts/{id}', 'get');

        $schema = $this->nestedArray($get, 'responses', '200', 'content', 'application/json', 'schema');
        self::assertArrayHasKey('allOf', $schema, 'The detail must be documented as a flattened composition.');

        $refs = array_column($schema['allOf'], '$ref');
        self::assertNotEmpty(
            array_filter($refs, static fn (string $ref): bool => str_contains($ref, 'PodcastDTO')),
            'The flattened half must reference PodcastDTO.',
        );

        $appended = array_values(array_filter(
            $schema['allOf'],
            static fn (array $part): bool => isset($part['properties']),
        ));
        self::assertArrayHasKey('episodes', $appended[0]['properties']);
        self::assertArrayHasKey('sessions', $appended[0]['properties']);

        $notFound = $this->nestedArray($get, 'responses', '404');
        self::assertStringContainsString('NotFoundError', $notFound['$ref'] ?? '');
    }

    public function testSyncDocumentsStartedAndNotConnected(): void
    {
        $post = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/podcasts/sync', 'post');

        $started = $this->nestedArray($post, 'responses', '202', 'content', 'application/json', 'schema');
        self::assertContains('sync_started', $started['properties']['status']['enum'] ?? []);

        $conflict = $this->nestedArray($post, 'responses', '409', 'content', 'application/json', 'schema');
        self::assertArrayHasKey(
            'authUrl',
            $conflict['properties'] ?? [],
            'The 409 body must tell the client where to authorize.',
        );
    }

    public function testPodcastModelExposesEveryReadField(): void
    {
        $schema = $this->nestedArray($this->fetchSpec(static::createClient()), 'components', 'schemas', 'PodcastDTO');

        $properties = $schema['properties'] ?? [];
        foreach ([
            'id', 'title', 'publisher', 'coverUrl', 'description',
            'episodeCount', 'listenedEpisodeCount', 'lastListenedAt', 'createdAt',
        ] as $field) {
            self::assertArrayHasKey($field, $properties, sprintf('PodcastDTO must document the "%s" field.', $field));
        }
    }

    /**
     * The nested read models must expose the progress fields the UI renders —
     * an episode's furthest position and whether it was finished.
     */
    public function testNestedModelsExposeTheProgressFields(): void
    {
        $spec = $this->fetchSpec(static::createClient());

        $episode = $this->nestedArray($spec, 'components', 'schemas', 'PodcastEpisodeDTO');
        foreach (['id', 'title', 'publishedAt', 'durationMs', 'listened', 'resumePositionMs', 'fullyPlayed'] as $field) {
            self::assertArrayHasKey($field, $episode['properties'] ?? []);
        }

        $session = $this->nestedArray($spec, 'components', 'schemas', 'PodcastListeningSessionDTO');
        foreach (['id', 'episodeId', 'episodeTitle', 'listenedAt', 'resumePositionMs', 'fullyPlayed'] as $field) {
            self::assertArrayHasKey($field, $session['properties'] ?? []);
        }
    }

    public function testTheSpecDeclaresThePodcastsTag(): void
    {
        $spec = $this->fetchSpec(static::createClient());

        $names = array_column($spec['tags'] ?? [], 'name');
        self::assertContains('Podcasts', $names);
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
