<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HMAI-338 — API versioning. The `/api/v1/*` prefix and the backward-compatible
 * `/api/*` alias are backed by the same controllers, so both must behave
 * identically (auth, payloads, writes) and the OpenAPI contract must advertise
 * the versioned base.
 */
final class ApiVersioningTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()->executeStatement('TRUNCATE TABLE tasks');
    }

    public function testVersionedAndLegacyListReturnIdenticalPayload(): void
    {
        $this->authenticate($this->client);
        $this->createTaskVia('/api/v1/tasks', 'Versioned task');

        $this->client->request('GET', '/api/tasks');
        self::assertResponseIsSuccessful();
        $legacy = $this->jsonResponse($this->client);

        $this->client->request('GET', '/api/v1/tasks');
        self::assertResponseIsSuccessful();
        $versioned = $this->jsonResponse($this->client);

        self::assertCount(1, $versioned);
        self::assertSame($legacy, $versioned, 'The /api and /api/v1 prefixes must return byte-identical payloads.');
    }

    public function testVersionedWriteIsVisibleThroughLegacyAlias(): void
    {
        // A task created through /api/v1 must be readable through the legacy alias:
        // both prefixes are wired to the same handlers and datastore.
        $this->authenticate($this->client);
        $id = $this->createTaskVia('/api/v1/tasks', 'Cross-prefix task');

        $this->client->request('GET', '/api/tasks/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSame('Cross-prefix task', $this->jsonResponse($this->client)['title'] ?? null);
    }

    public function testVersionedRouteRequiresApiKey(): void
    {
        // No X-API-Key: the versioned prefix sits behind the same `^/api` firewall.
        $this->client->request('GET', '/api/v1/tasks');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testOpenApiServersAdvertiseVersionedBase(): void
    {
        // The spec is public (no key) and must point clients at the versioned base.
        $this->client->request('GET', '/api/doc.json');
        self::assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        $doc = json_decode($content, true);
        self::assertIsArray($doc);
        self::assertIsArray($doc['servers'] ?? null);
        self::assertSame('/api/v1', $doc['servers'][0]['url'] ?? null, 'The contract must advertise the /api/v1 base.');
    }

    private function createTaskVia(string $endpoint, string $title): string
    {
        $this->client->request('POST', $endpoint, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'title' => $title,
            'start' => '2025-06-01T09:00:00+02:00',
            'end' => '2025-06-01T10:00:00+02:00',
        ]));

        self::assertResponseStatusCodeSame(201);

        $id = $this->jsonResponse($this->client)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }
}
