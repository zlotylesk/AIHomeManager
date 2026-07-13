<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pins the OpenAPI contract for the Dashboard module (HMAI-260): the
 * `/api/v1/dashboard` operation is documented, `Dashboard`-tagged, returns the
 * `DashboardDTO` schema (with its widget sections) plus the shared 401 error.
 */
final class DashboardApiDocTest extends WebTestCase
{
    public function testDashboardEndpointIsDocumentedAndTagged(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/dashboard', 'get');

        self::assertContains('Dashboard', $get['tags'] ?? [], 'GET /dashboard must be tagged "Dashboard".');
    }

    public function testDashboardResponseReferencesDashboardModelWithUnauthorized(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/dashboard', 'get');

        $schema = $this->nestedArray($get, 'responses', '200', 'content', 'application/json', 'schema');
        self::assertSame('#/components/schemas/DashboardDTO', $schema['$ref'] ?? null);

        $responses = $this->nestedArray($get, 'responses');
        self::assertArrayHasKey('401', $responses);
    }

    public function testDashboardModelExposesEveryWidgetSection(): void
    {
        $schema = $this->nestedArray(
            $this->fetchSpec(static::createClient()),
            'components',
            'schemas',
            'DashboardDTO',
        );

        $properties = $schema['properties'] ?? [];
        foreach (['date', 'tasks', 'article', 'goals', 'recommendations', 'recentTracks'] as $section) {
            self::assertArrayHasKey($section, $properties, sprintf('DashboardDTO must document the "%s" section.', $section));
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
