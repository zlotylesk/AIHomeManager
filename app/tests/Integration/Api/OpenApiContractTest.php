<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\ORM\EntityManagerInterface;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HMAI-343 — OpenAPI response↔schema conformance.
 *
 * Closes the contract epic (HMAI-311) as a CI quality gate: representative
 * endpoint responses are validated against the schema the generated OpenAPI 3.1
 * contract declares for the status code each one actually returns. Any drift
 * between a normalizer's real JSON and its documented schema fails here — so the
 * contract stops being documentation-only and becomes an enforced boundary.
 *
 * The contract is OpenAPI 3.1 (its schemas are JSON Schema 2020-12), so
 * validation uses opis/json-schema (native 2020-12) rather than a
 * cebe/php-openapi-based validator, which only understands 3.0.
 */
final class OpenApiContractTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    /** Base URI the whole spec is registered under, so intra-document $refs resolve. */
    private const string SPEC_URI = 'https://homemanager.local/openapi.json';

    private KernelBrowser $client;

    /** @var array<mixed> The decoded OpenAPI document (array form, for navigation). */
    private array $spec;

    private Validator $validator;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        $this->truncate('series_episodes', 'series_seasons', 'series', 'tasks', 'articles');

        $content = $this->fetchSpecContent();

        $specArray = json_decode($content, true);
        self::assertIsArray($specArray);
        $this->spec = $specArray;

        // opis needs the object form (stdClass maps) so #/components/* $refs resolve.
        $specObject = json_decode($content);
        self::assertIsObject($specObject);

        $this->validator = new Validator();
        $resolver = $this->validator->resolver();
        self::assertNotNull($resolver);
        $resolver->registerRaw($specObject, self::SPEC_URI);
    }

    public function testHealthResponseConformsToContract(): void
    {
        // Public probe: validated against whichever documented status the test
        // environment reports (200 healthy/degraded, or 503 unhealthy).
        $this->assertResponseConformsToContract('GET', '/api/health', '/api/health');
    }

    public function testSeriesListResponseConformsToContract(): void
    {
        $this->seedSeries();
        $this->assertResponseConformsToContract('GET', '/api/v1/series', '/api/v1/series');
    }

    public function testSeriesDetailResponseConformsToContract(): void
    {
        // The richest DTO in the contract: nested seasons/episodes plus the disjoint
        // own/season/episode ratings, the computed averageRating and the watched flag.
        $id = $this->seedSeries();
        $this->assertResponseConformsToContract('GET', '/api/v1/series/'.$id, '/api/v1/series/{id}');
    }

    public function testTasksListResponseConformsToContract(): void
    {
        $this->seedTask();
        $this->assertResponseConformsToContract('GET', '/api/v1/tasks', '/api/v1/tasks');
    }

    public function testTasksDetailResponseConformsToContract(): void
    {
        $id = $this->seedTask();
        $this->assertResponseConformsToContract('GET', '/api/v1/tasks/'.$id, '/api/v1/tasks/{id}');
    }

    public function testArticlesListResponseConformsToContract(): void
    {
        $this->seedArticle();
        $this->assertResponseConformsToContract('GET', '/api/v1/articles', '/api/v1/articles');
    }

    public function testContractValidationRejectsDriftingResponse(): void
    {
        // Negative control: a payload that breaks EpisodeDTO.number (integer) must be
        // rejected — proof the checks above would catch a real contract↔response drift.
        $schema = $this->responseSchema('/api/v1/series/{id}', 'get', 200);
        self::assertNotNull($schema, 'The series-detail 200 schema must be documented.');

        $drifted = json_decode('{"id":"x","title":"x","createdAt":"x","seasons":'
            .'[{"id":"s","number":1,"episodes":[{"id":"e","title":"E","number":"not-an-integer"}]}]}');

        self::assertFalse(
            $this->validator->validate($drifted, $schema)->isValid(),
            'The contract validator must reject a response that drifts from the schema.',
        );
    }

    /**
     * Request the endpoint and validate its body against the schema documented for
     * the status code it actually returned — the drift-catching core of this suite.
     */
    private function assertResponseConformsToContract(string $method, string $requestUri, string $specPath): void
    {
        $this->client->request($method, $requestUri);
        $status = $this->client->getResponse()->getStatusCode();
        $verb = strtolower($method);

        $responses = $this->dig($this->spec, 'paths', $specPath, $verb, 'responses');
        self::assertIsArray($responses, sprintf('Operation %s %s is not documented in the contract.', $method, $specPath));
        self::assertArrayHasKey($status, $responses, sprintf('%s %s returned undocumented status %d.', $method, $specPath, $status));

        $schema = $this->responseSchema($specPath, $verb, $status);
        if (null === $schema) {
            // Documented status with no JSON body (e.g. 204) — nothing to validate.
            return;
        }

        $body = json_decode((string) $this->client->getResponse()->getContent());
        $result = $this->validator->validate($body, $schema);

        self::assertTrue(
            $result->isValid(),
            sprintf(
                "%s %s (HTTP %d) response does not conform to its OpenAPI schema:\n%s",
                $method,
                $requestUri,
                $status,
                $this->formatErrors($result),
            ),
        );
    }

    /**
     * The application/json response schema documented for a status, as an opis-ready
     * object with intra-document $refs made absolute against the registered spec, or
     * null when the status carries no JSON body.
     */
    private function responseSchema(string $path, string $method, int $status): ?object
    {
        $node = $this->dig($this->spec, 'paths', $path, $method, 'responses', $status, 'content', 'application/json', 'schema');
        if (!is_array($node)) {
            return null;
        }

        $decoded = json_decode((string) json_encode($this->absolutizeRefs($node), \JSON_UNESCAPED_SLASHES));
        self::assertIsObject($decoded);

        return $decoded;
    }

    /**
     * Rewrite intra-document "#/..." $refs to absolute "<spec-uri>#/..." so the
     * extracted schema fragment can resolve components against the registered spec.
     * Local "#/components/*" pointers carry no path templates, so this also sidesteps
     * opis reading a "{id}" path key as an RFC-6570 URI template.
     */
    private function absolutizeRefs(mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }

        $out = [];
        foreach ($node as $key => $value) {
            if ('$ref' === $key && is_string($value) && str_starts_with($value, '#/')) {
                $out[$key] = self::SPEC_URI.$value;
            } else {
                $out[$key] = $this->absolutizeRefs($value);
            }
        }

        return $out;
    }

    /** Walk a decoded-JSON tree by keys, returning null the moment a level is absent. */
    private function dig(mixed $node, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }

        return $node;
    }

    private function formatErrors(ValidationResult $result): string
    {
        $error = $result->error();
        if (null === $error) {
            return '(no error details)';
        }

        return (string) json_encode(new ErrorFormatter()->format($error), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    private function fetchSpecContent(): string
    {
        $this->client->request('GET', '/api/doc.json');
        self::assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);

        return $content;
    }

    private function seedSeries(): string
    {
        $seriesId = $this->postForId('/api/v1/series', [
            'title' => 'Breaking Bad',
            'year' => 2008,
            'status' => 'ended',
            'coverUrl' => 'https://example.com/bb.jpg',
            'description' => 'A high-school chemistry teacher turned manufacturer.',
        ]);
        $seasonId = $this->postForId('/api/v1/series/'.$seriesId.'/seasons', ['number' => 1]);
        $episodeId = $this->postForId(
            '/api/v1/series/'.$seriesId.'/seasons/'.$seasonId.'/episodes',
            ['title' => 'Pilot', 'number' => 1, 'rating' => 8],
        );

        // Exercise the disjoint own ratings + the watched flag on the read model.
        $this->patch('/api/v1/series/'.$seriesId.'/rating', ['rating' => 9]);
        $this->patch('/api/v1/series/'.$seriesId.'/seasons/'.$seasonId.'/rating', ['rating' => 7]);
        $this->patch('/api/v1/series/'.$seriesId.'/seasons/'.$seasonId.'/episodes/'.$episodeId.'/watched', ['watched' => true]);

        return $seriesId;
    }

    private function seedTask(): string
    {
        return $this->postForId('/api/v1/tasks', [
            'title' => 'Contract conformance task',
            'start' => '2026-06-01T09:00:00+02:00',
            'end' => '2026-06-01T10:30:00+02:00',
        ]);
    }

    private function seedArticle(): string
    {
        return $this->postForId('/api/v1/articles', [
            'title' => 'A representative article',
            'url' => 'https://example.com/article',
        ]);
    }

    /**
     * POST a JSON body, assert a 201 create, and return the new resource id.
     *
     * @param array<string, mixed> $payload
     */
    private function postForId(string $uri, array $payload): string
    {
        $this->client->request('POST', $uri, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($payload));
        self::assertResponseStatusCodeSame(201);

        $id = $this->jsonResponse($this->client)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function patch(string $uri, array $payload): void
    {
        $this->client->request('PATCH', $uri, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($payload));
        self::assertResponseStatusCodeSame(204);
    }

    private function truncate(string ...$tables): void
    {
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }
}
