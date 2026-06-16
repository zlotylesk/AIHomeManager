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
