<?php

declare(strict_types=1);

namespace App\Tests\Integration\SearchEngine;

use OpenSearch\Client;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * HMAI-360 (epic HMAI-359): proves the app-data search engine is provisioned and
 * reachable through the DI-wired client. Requires the OpenSearch `search` service
 * (docker-compose locally, the CI `tests` job service container) — like every
 * other integration test, it runs where the stack is up.
 */
final class SearchEngineConnectionTest extends KernelTestCase
{
    public function testDiWiredClientReachesTheEngine(): void
    {
        self::bootKernel();

        /** @var Client $client */
        $client = self::getContainer()->get('app.search_client');

        self::assertTrue(
            $client->ping(),
            'The app-data OpenSearch engine should be reachable via app.search_client.',
        );
    }

    public function testEngineReportsUsableClusterHealth(): void
    {
        self::bootKernel();

        /** @var Client $client */
        $client = self::getContainer()->get('app.search_client');

        $health = $client->cluster()->health();

        self::assertArrayHasKey('status', $health);
        // A single-node engine reports 'green'/'yellow' (unassigned replicas are
        // expected with one node); 'red' means the cluster is broken.
        self::assertContains($health['status'], ['green', 'yellow']);
    }
}
