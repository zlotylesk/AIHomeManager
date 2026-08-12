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
            'disk_database' => 'up',
            'disk_backups' => 'up',
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
        $alerts = $this->probeReporting(['disk_backups' => 'degraded']);

        self::assertSame('Health component "disk_backups" is degraded', $alerts[0]->title);
    }

    /**
     * The disk components are the ones that genuinely walk up the severity
     * scale on their own — 80 % degraded, 95 % down — which is what makes
     * escalation a real path rather than a theoretical one.
     */
    public function testTheSameComponentCanEscalateFromWarningToCritical(): void
    {
        self::assertSame(AlertSeverity::WARNING, $this->probeReporting(['disk_database' => 'degraded'])[0]->severity);
        self::assertSame(AlertSeverity::CRITICAL, $this->probeReporting(['disk_database' => 'down'])[0]->severity);
    }

    /**
     * A component with no entry still produces a deliverable alert — it says so
     * instead of pretending to advise. That every component actually HAS an
     * entry is asserted against the real checker in
     * {@see \App\Tests\Integration\Monitoring\HealthRunbookCoverageTest}, and
     * deliberately not against a list written down here: a hand-kept list is
     * what lets a probe added later ship with no hint and a green suite.
     */
    public function testAComponentWithNoRunbookEntrySaysSoRatherThanFallingSilent(): void
    {
        $alerts = $this->probeReporting(['something_new' => 'down']);

        self::assertStringContainsString('No runbook entry', $alerts[0]->detail);
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
