<?php

declare(strict_types=1);

namespace App\Tests\Integration\RateLimit;

use App\Tests\Support\AuthenticatedApiTrait;
use App\Tests\Support\SpyLogger;
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

        $this->client->disableReboot();
        $this->authenticate($this->client);

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
        for ($i = 1; $i <= 80; ++$i) {
            $this->client->request('GET', '/api/health');
            self::assertNotSame(
                Response::HTTP_TOO_MANY_REQUESTS,
                $this->client->getResponse()->getStatusCode(),
                sprintf('Request #%d to /api/health returned 429', $i),
            );
        }
    }

    public function testDifferentIpsHaveSeparateBuckets(): void
    {
        for ($i = 1; $i <= 60; ++$i) {
            $this->client->request('GET', '/api/series', [], [], ['HTTP_X_FORWARDED_FOR' => '10.0.0.1']);
        }

        $this->client->request('GET', '/api/series', [], [], ['HTTP_X_FORWARDED_FOR' => '10.0.0.1']);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $this->client->request('GET', '/api/series', [], [], ['HTTP_X_FORWARDED_FOR' => '10.0.0.2']);
        self::assertResponseIsSuccessful('Different IP must have its own bucket');
    }

    public function testRateLimitTriggersWarningLogWithExpectedContext(): void
    {
        /** @phpstan-ignore symfonyContainer.serviceNotFound */
        $logger = static::getContainer()->get(SpyLogger::class);
        self::assertInstanceOf(SpyLogger::class, $logger);
        $logger->reset();

        for ($i = 1; $i <= 61; ++$i) {
            $this->client->request('GET', '/api/series');
        }

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $record = $logger->findByMessage('API rate limit triggered');
        self::assertNotNull($record, 'Expected rate-limit warning was not logged');
        self::assertSame('warning', $record['level']);
        self::assertTrue($record['context']['rate_limit_triggered'] ?? null, 'Graylog AC: rate_limit_triggered=true must be present');
        self::assertSame('api_per_ip', $record['context']['limiter'] ?? null);
        self::assertGreaterThan(0, $record['context']['retry_after'] ?? 0);
    }
}
