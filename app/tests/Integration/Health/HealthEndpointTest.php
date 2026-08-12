<?php

declare(strict_types=1);

namespace App\Tests\Integration\Health;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthEndpointTest extends WebTestCase
{
    public function testHealthEndpointWorksWithoutApiKey(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        $response = $client->getResponse();
        self::assertContains(
            $response->getStatusCode(),
            [200, 503],
            'Health endpoint must respond with 200 or 503, not 401'
        );

        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);

        self::assertContains($body['status'], ['healthy', 'degraded', 'unhealthy']);
        self::assertArrayHasKey('mysql', $body['components']);
        self::assertArrayHasKey('redis', $body['components']);
        self::assertArrayHasKey('rabbitmq', $body['components']);
        self::assertArrayHasKey('search', $body['components']);
        // Two disk components, not one: the database's data directory and the
        // backup directory are separate filesystems the moment either is given
        // a volume of its own, and knowing which filled up is what decides
        // whether pruning dumps would help.
        self::assertArrayHasKey('disk_database', $body['components']);
        self::assertArrayHasKey('disk_backups', $body['components']);
        self::assertContains($body['components']['disk_database'], ['up', 'degraded', 'down']);
        self::assertContains($body['components']['disk_backups'], ['up', 'degraded', 'down']);
        // Search is optional infra (graceful degrade to FULLTEXT) — reachable or
        // 'degraded', but never 'down'.
        self::assertContains($body['components']['search'], ['up', 'degraded']);
    }
}
