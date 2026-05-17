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
        ]);

        $controller = new HealthController($checker);
        $response = $controller();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('healthy', $body['status']);
        self::assertSame(['mysql' => 'up', 'redis' => 'up', 'rabbitmq' => 'up'], $body['components']);
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
        ]);

        $controller = new HealthController($checker);
        $response = $controller();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('unhealthy', $body['status']);
        self::assertSame('down', $body['components']['redis']);
    }
}
