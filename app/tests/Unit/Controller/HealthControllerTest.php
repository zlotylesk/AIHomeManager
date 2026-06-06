<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\HealthController;
use App\Health\HealthChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class HealthControllerTest extends TestCase
{
    public function testReturns200AndHealthyWhenAllComponentsUp(): void
    {
        $checker = $this->createStub(HealthChecker::class);
        $checker->method('check')->willReturn([
            'mysql' => 'up',
            'redis' => 'up',
            'rabbitmq' => 'up',
            'disk' => 'up',
        ]);

        $controller = new HealthController($checker);
        $response = $controller();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('healthy', $body['status']);
        self::assertSame(['mysql' => 'up', 'redis' => 'up', 'rabbitmq' => 'up', 'disk' => 'up'], $body['components']);
        // Timestamp is best-effort — assert shape only so the test stays
        // stable across timezones and replays.
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $body['timestamp']);
    }

    public function testReturns503AndUnhealthyWhenAnyComponentDown(): void
    {
        // Single component down must flip the entire response to 503 — that's
        // what docker healthcheck and orchestrator probes key on. If we returned
        // 200 with a `degraded` body the orchestrator would keep routing traffic
        // to a broken instance.
        $checker = $this->createStub(HealthChecker::class);
        $checker->method('check')->willReturn([
            'mysql' => 'up',
            'redis' => 'down',
            'rabbitmq' => 'up',
            'disk' => 'up',
        ]);

        $controller = new HealthController($checker);
        $response = $controller();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('unhealthy', $body['status']);
        self::assertSame('down', $body['components']['redis']);
    }

    public function testDiskDegradedReturns200WithDegradedStatus(): void
    {
        // HMAI-155: disk at 80-95% used → degraded. HTTP 200 keeps traffic
        // flowing (writes still work) but the body signal lets monitoring page
        // before the threshold escalates.
        $checker = $this->createStub(HealthChecker::class);
        $checker->method('check')->willReturn([
            'mysql' => 'up',
            'redis' => 'up',
            'rabbitmq' => 'up',
            'disk' => 'degraded',
        ]);

        $controller = new HealthController($checker);
        $response = $controller();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('degraded', $body['status']);
        self::assertSame('degraded', $body['components']['disk']);
    }

    public function testDiskDownReturns503(): void
    {
        // HMAI-155: disk above 95% used → down. MySQL/backup/cache start failing
        // at that point, so flip orchestrator routing the same way a redis-down
        // would.
        $checker = $this->createStub(HealthChecker::class);
        $checker->method('check')->willReturn([
            'mysql' => 'up',
            'redis' => 'up',
            'rabbitmq' => 'up',
            'disk' => 'down',
        ]);

        $controller = new HealthController($checker);
        $response = $controller();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('unhealthy', $body['status']);
        self::assertSame('down', $body['components']['disk']);
    }
}
