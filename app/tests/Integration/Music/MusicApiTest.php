<?php

declare(strict_types=1);

namespace App\Tests\Integration\Music;

use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MusicApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);

        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->executeStatement('TRUNCATE TABLE discogs_oauth_tokens');
    }

    public function testTopAlbumsWithInvalidPeriodReturns422(): void
    {
        $this->client->request('GET', '/api/music/top-albums?period=invalid');

        self::assertResponseStatusCodeSame(422);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testTopAlbumsWithMissingApiKeyReturns503(): void
    {
        $this->client->request('GET', '/api/music/top-albums?period=1month');

        self::assertResponseStatusCodeSame(503);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('not configured', $data['error']);
    }

    public function testComparisonWithInvalidPeriodReturns422(): void
    {
        $this->client->request('GET', '/api/music/comparison?period=badvalue');

        self::assertResponseStatusCodeSame(422);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testCollectionWhenCacheEmptyReturns503AndSchedulesRefresh(): void
    {
        $this->client->request('GET', '/api/music/collection');

        self::assertResponseStatusCodeSame(503);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('being refreshed', strtolower((string) $data['error']));
    }

    public function testComparisonWhenDiscogsCacheEmptyReturns503(): void
    {
        $this->client->request('GET', '/api/music/comparison?period=1month&limit=5');

        self::assertResponseStatusCodeSame(503);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testTopAlbumsDefaultPeriodIsAccepted(): void
    {
        $this->client->request('GET', '/api/music/top-albums');

        // 503 because no API key configured — but not 422 (period is valid default)
        self::assertNotSame(422, $this->client->getResponse()->getStatusCode());
    }
}
