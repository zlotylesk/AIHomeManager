<?php

declare(strict_types=1);

namespace App\Tests\Integration\RateLimit;

use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class ApiRateLimitTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // KernelBrowser reboots the kernel between requests by default, which resets the
        // in-memory rate limiter store. We need shared state across requests for the test.
        $this->client->disableReboot();
        $this->authenticate($this->client);
        // /api/series tests below use an empty list, so wipe the table once per test.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $conn->executeStatement('TRUNCATE TABLE series_episodes');
        $conn->executeStatement('TRUNCATE TABLE series_seasons');
        $conn->executeStatement('TRUNCATE TABLE series');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testFirst60RequestsSucceed(): void
    {
        for ($i = 1; $i <= 60; ++$i) {
            $this->client->request('GET', '/api/series');
            self::assertResponseIsSuccessful(sprintf('Request #%d should succeed', $i));
        }
    }

    public function testRequest61Returns429WithRetryAfter(): void
    {
        for ($i = 1; $i <= 60; ++$i) {
            $this->client->request('GET', '/api/series');
        }

        $this->client->request('GET', '/api/series');

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $response = $this->client->getResponse();
        self::assertTrue($response->headers->has('Retry-After'), 'Retry-After header missing');
        self::assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        self::assertSame('0', $response->headers->get('X-RateLimit-Remaining'));

        $content = $response->getContent();
        self::assertIsString($content);
        $body = json_decode($content, true);
        self::assertSame('Too Many Requests', $body['error']);
        self::assertArrayHasKey('retry_after', $body);
    }

    public function testAuthRoutesNotRateLimited(): void
    {
        // /auth/* is outside the /api/ prefix — listener must not engage.
        for ($i = 1; $i <= 100; ++$i) {
            $this->client->request('GET', '/auth/discogs');
            self::assertNotSame(
                Response::HTTP_TOO_MANY_REQUESTS,
                $this->client->getResponse()->getStatusCode(),
                sprintf('Request #%d to /auth/discogs returned 429', $i),
            );
        }
    }

    public function testHealthEndpointBypassesRateLimit(): void
    {
        // /api/health is explicitly excluded from per-IP throttling.
        for ($i = 1; $i <= 80; ++$i) {
            $this->client->request('GET', '/api/health');
            self::assertNotSame(
                Response::HTTP_TOO_MANY_REQUESTS,
                $this->client->getResponse()->getStatusCode(),
                sprintf('Request #%d to /api/health returned 429', $i),
            );
        }
    }
}
