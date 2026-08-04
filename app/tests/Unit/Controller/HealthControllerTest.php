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

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $body['timestamp']);
    }

    public function testReturns503AndUnhealthyWhenAnyComponentDown(): void
    {
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

    public function testDeadWorkersReturn200WithDegradedStatus(): void
    {
        // HMAI-418. The half of "a dead worker must not take the instance out of
        // rotation" that belongs to the controller: `degraded` maps to 200, so
        // the reading is informational. The other half — that the worker probe
        // reports `degraded` rather than `down` — is pinned against real Redis in
        // WorkerHealthTest. Split this way because the endpoint's aggregate
        // status depends on every other component, and an integration test
        // asserting 200 would be asserting the environment it happens to run in.
        $checker = $this->createStub(HealthChecker::class);
        $checker->method('check')->willReturn([
            'mysql' => 'up',
            'redis' => 'up',
            'rabbitmq' => 'up',
            'worker' => 'degraded',
            'disk' => 'up',
        ]);

        $controller = new HealthController($checker);
        $response = $controller();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('degraded', $body['status']);
        self::assertSame('degraded', $body['components']['worker']);
    }

    public function testDiskDownReturns503(): void
    {
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
