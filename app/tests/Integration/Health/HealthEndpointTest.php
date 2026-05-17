<?php

declare(strict_types=1);

namespace App\Tests\Integration\Health;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthEndpointTest extends WebTestCase
{
    public function testHealthEndpointWorksWithoutApiKey(): void
    {
        // Regression guard for HMAI-37: /api/health must respond even without
        // the X-API-Key header. Orchestrators (docker healthcheck, k8s probes)
        // never carry the project's API key.
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
        self::assertContains($body['status'], ['healthy', 'unhealthy']);
        self::assertArrayHasKey('mysql', $body['components']);
        self::assertArrayHasKey('redis', $body['components']);
        self::assertArrayHasKey('rabbitmq', $body['components']);
    }
}
