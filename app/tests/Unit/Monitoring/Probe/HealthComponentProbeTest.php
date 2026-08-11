<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitoring\Probe;

use App\Health\HealthChecker;
use App\Monitoring\Alert;
use App\Monitoring\AlertSeverity;
use App\Monitoring\Probe\HealthComponentProbe;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class HealthComponentProbeTest extends TestCase
{
    public function testAHealthySystemProducesNoAlerts(): void
    {
        $alerts = $this->probeReporting([
            'mysql' => 'up',
            'redis' => 'up',
            'rabbitmq' => 'up',
            'search' => 'up',
            'worker' => 'up',
            'disk' => 'up',
        ]);

        self::assertSame([], $alerts);
    }

    public function testDownIsCriticalAndDegradedIsAWarning(): void
    {
        $alerts = $this->probeReporting([
            'mysql' => 'down',
            'redis' => 'up',
            'worker' => 'degraded',
        ]);

        self::assertCount(2, $alerts);
        self::assertSame('mysql', $alerts[0]->key);
        self::assertSame(AlertSeverity::CRITICAL, $alerts[0]->severity);
        self::assertSame('worker', $alerts[1]->key);
        self::assertSame(AlertSeverity::WARNING, $alerts[1]->severity, 'A degraded component still serves requests; the health endpoint keeps that distinction and so must this.');
    }

    public function testTheAlertNamesTheComponentAndItsState(): void
    {
        $alerts = $this->probeReporting(['disk' => 'degraded']);

        self::assertSame('Health component "disk" is degraded', $alerts[0]->title);
    }

    /**
     * The disk component is the one that genuinely walks up the severity scale
     * on its own — 80 % degraded, 95 % down — which is what makes escalation a
     * real path rather than a theoretical one.
     */
    public function testTheSameComponentCanEscalateFromWarningToCritical(): void
    {
        self::assertSame(AlertSeverity::WARNING, $this->probeReporting(['disk' => 'degraded'])[0]->severity);
        self::assertSame(AlertSeverity::CRITICAL, $this->probeReporting(['disk' => 'down'])[0]->severity);
    }

    public function testEveryComponentTheHealthCheckReportsCarriesARunbookHint(): void
    {
        foreach (['mysql', 'redis', 'rabbitmq', 'search', 'worker', 'disk'] as $component) {
            $alerts = $this->probeReporting([$component => 'down']);

            self::assertNotEmpty($alerts[0]->detail, sprintf('The "%s" component has no runbook hint.', $component));
            self::assertStringNotContainsString('No runbook entry', $alerts[0]->detail, sprintf('The "%s" component has no runbook hint.', $component));
        }
    }

    public function testAnUnrecognisedStatusIsIgnoredRatherThanGuessedAt(): void
    {
        self::assertSame([], $this->probeReporting(['mysql' => 'probably fine']));
    }

    /**
     * @param array<string, string> $components
     *
     * @return list<Alert>
     */
    private function probeReporting(array $components): array
    {
        $health = $this->createStub(HealthChecker::class);
        $health->method('check')->willReturn($components);

        return new HealthComponentProbe($health)->probe(new DateTimeImmutable('2026-08-11 09:00:00'));
    }
}
