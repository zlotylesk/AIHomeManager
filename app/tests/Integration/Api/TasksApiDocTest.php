<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pins the OpenAPI contract for the Tasks module (HMAI-340): every endpoint is
 * documented under the versioned `/api/v1/tasks*` surface with the right method,
 * parameters, request bodies, response codes and `$ref`s to the shared
 * components (HMAI-337). Schemas mirror the real DTO shapes.
 */
final class TasksApiDocTest extends WebTestCase
{
    public function testEveryTasksEndpointIsDocumentedWithItsMethod(): void
    {
        $paths = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths');

        foreach ([
            '/api/v1/tasks' => ['get', 'post'],
            '/api/v1/tasks/{id}' => ['get', 'patch', 'delete'],
            '/api/v1/tasks/{id}/complete' => ['post'],
            '/api/v1/tasks/{id}/cancel' => ['post'],
            '/api/v1/tasks/export' => ['get'],
            '/api/v1/tasks/time-report' => ['get'],
        ] as $path => $methods) {
            self::assertArrayHasKey($path, $paths, sprintf('Missing documented path "%s".', $path));
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $paths[$path], sprintf('Path "%s" must document the "%s" operation.', $path, strtoupper($method)));
                self::assertContains('Tasks', $paths[$path][$method]['tags'] ?? [], sprintf('%s %s must be tagged "Tasks".', strtoupper($method), $path));
            }
        }
    }

    public function testListDocumentsStatusEnumAndTaskArray(): void
    {
        $doc = $this->fetchSpec(static::createClient());
        $get = $this->nestedArray($doc, 'paths', '/api/v1/tasks', 'get');

        // The status filter is documented as an optional enum query parameter.
        $status = null;
        foreach ($get['parameters'] ?? [] as $param) {
            if ('status' === ($param['name'] ?? null)) {
                $status = $param;
            }
        }
        self::assertNotNull($status, 'The list endpoint must document the "status" query parameter.');
        self::assertSame('query', $status['in']);
        self::assertSame(['pending', 'completed', 'cancelled'], $status['schema']['enum'] ?? null);

        // The 200 body is an array of the TaskDTO schema.
        $items = $this->nestedArray($get, 'responses', '200', 'content', 'application/json', 'schema');
        self::assertSame('array', $items['type'] ?? null);
        self::assertSame('#/components/schemas/TaskDTO', $items['items']['$ref'] ?? null);
    }

    public function testCreateDocumentsRequiredRequestBodyAndCreatedResponse(): void
    {
        $post = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/tasks', 'post');

        $body = $this->nestedArray($post, 'requestBody', 'content', 'application/json', 'schema');
        self::assertSame(['title', 'start', 'end'], $body['required'] ?? null);
        foreach (['title', 'start', 'end'] as $field) {
            self::assertArrayHasKey($field, $body['properties'] ?? [], sprintf('Create body must document "%s".', $field));
        }

        // 201 returns the new id.
        $created = $this->nestedArray($post, 'responses', '201', 'content', 'application/json', 'schema');
        self::assertArrayHasKey('id', $created['properties'] ?? []);
    }

    public function testWriteEndpointsReferenceSharedErrorResponses(): void
    {
        $doc = $this->fetchSpec(static::createClient());

        // Not-found on a mutating action resolves to the shared component.
        $notFound = $this->nestedArray($doc, 'paths', '/api/v1/tasks/{id}', 'patch', 'responses', '404');
        self::assertSame('#/components/responses/NotFoundError', $notFound['$ref'] ?? null);

        // Validation failure resolves to the shared component.
        $unprocessable = $this->nestedArray($doc, 'paths', '/api/v1/tasks/{id}/complete', 'post', 'responses', '422');
        self::assertSame('#/components/responses/UnprocessableEntityError', $unprocessable['$ref'] ?? null);

        // 204 no-content on delete carries no body.
        $noContent = $this->nestedArray($doc, 'paths', '/api/v1/tasks/{id}', 'delete', 'responses', '204');
        self::assertArrayNotHasKey('content', $noContent);
    }

    public function testTimeReportDocumentsTheReportSchema(): void
    {
        $doc = $this->fetchSpec(static::createClient());
        $get = $this->nestedArray($doc, 'paths', '/api/v1/tasks/time-report', 'get');

        // from/to are documented as required date query parameters.
        $required = [];
        foreach ($get['parameters'] ?? [] as $param) {
            if (true === ($param['required'] ?? false)) {
                $required[] = $param['name'];
            }
        }
        self::assertEqualsCanonicalizing(['from', 'to'], $required);

        // The 200 body is the TimeReportDTO, whose breakdown is a list of TaskTimeDTO.
        $schema = $this->nestedArray($get, 'responses', '200', 'content', 'application/json', 'schema');
        self::assertSame('#/components/schemas/TimeReportDTO', $schema['$ref'] ?? null);

        $report = $this->nestedArray($doc, 'components', 'schemas', 'TimeReportDTO', 'properties');
        self::assertSame('#/components/schemas/TaskTimeDTO', $report['breakdown']['items']['$ref'] ?? null);

        $breakdown = $this->nestedArray($doc, 'components', 'schemas', 'TaskTimeDTO', 'properties');
        foreach (['taskId', 'title', 'minutes'] as $field) {
            self::assertArrayHasKey($field, $breakdown, sprintf('TaskTimeDTO must document "%s".', $field));
        }
    }

    public function testExportDocumentsBothBinaryFormats(): void
    {
        $doc = $this->fetchSpec(static::createClient());
        $get = $this->nestedArray($doc, 'paths', '/api/v1/tasks/export', 'get');

        // format is an enum (csv|pdf) with a csv default.
        $format = null;
        foreach ($get['parameters'] ?? [] as $param) {
            if ('format' === ($param['name'] ?? null)) {
                $format = $param;
            }
        }
        self::assertNotNull($format, 'The export endpoint must document the "format" query parameter.');
        self::assertSame(['csv', 'pdf'], $format['schema']['enum'] ?? null);

        // The 200 body offers both a CSV and a PDF media type as binary strings.
        $content = $this->nestedArray($get, 'responses', '200', 'content');
        self::assertArrayHasKey('text/csv', $content);
        self::assertArrayHasKey('application/pdf', $content);
        self::assertSame('binary', $content['text/csv']['schema']['format'] ?? null);
    }

    public function testTaskSchemaMirrorsTheNormalizerOutput(): void
    {
        $props = $this->nestedArray($this->fetchSpec(static::createClient()), 'components', 'schemas', 'TaskDTO', 'properties');

        self::assertSame(
            ['id', 'title', 'start', 'end', 'durationMinutes', 'status', 'googleEventId'],
            array_keys($props),
            'The TaskDTO schema must list exactly the fields TaskDTONormalizer emits, in order.',
        );
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
