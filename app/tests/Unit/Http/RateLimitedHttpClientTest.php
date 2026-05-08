<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\RateLimitedHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class RateLimitedHttpClientTest extends TestCase
{
    public function testFirstRequestsConsumeBucketWithoutWaiting(): void
    {
        $factory = new RateLimiterFactory(
            ['id' => 'test', 'policy' => 'token_bucket', 'limit' => 3, 'rate' => ['interval' => '1 second', 'amount' => 1]],
            new InMemoryStorage(),
        );

        $callCount = 0;
        $inner = new MockHttpClient(static function () use (&$callCount): MockResponse {
            ++$callCount;

            return new MockResponse('ok');
        });

        $client = new RateLimitedHttpClient($inner, $factory, 'test', new NullLogger());

        $start = microtime(true);
        for ($i = 0; $i < 3; ++$i) {
            $response = $client->request('GET', 'https://example.com/');
            self::assertSame(200, $response->getStatusCode());
        }

        self::assertSame(3, $callCount);
        // No real wall-clock waits should have happened — we only consumed initial bucket.
        self::assertLessThan(0.5, microtime(true) - $start, 'Initial bucket should not block the caller');
    }

    public function testFourthRequestWaitsForToken(): void
    {
        // Bucket of 3 with refill 1/second → 4th request must wait roughly 1s.
        $factory = new RateLimiterFactory(
            ['id' => 'test', 'policy' => 'token_bucket', 'limit' => 3, 'rate' => ['interval' => '1 second', 'amount' => 1]],
            new InMemoryStorage(),
        );

        $inner = new MockHttpClient(static fn (): MockResponse => new MockResponse('ok'));
        $client = new RateLimitedHttpClient($inner, $factory, 'test', new NullLogger());

        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', 'https://example.com/');
        }

        // Reservation for the 4th request reports a wait > 0 — the bucket is empty.
        $reservation = $factory->create()->reserve();
        $waitDuration = $reservation->getWaitDuration();

        self::assertGreaterThan(0.0, $waitDuration, 'Expected non-zero wait once bucket is empty');
        self::assertLessThanOrEqual(1.0, $waitDuration, 'Wait should be at most one refill interval');
    }
}
