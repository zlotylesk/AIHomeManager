<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitoring;

use App\Health\HealthChecker;
use App\Monitoring\Probe\HealthComponentProbe;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every component the health check reports carries a first step in its alert.
 *
 * The component list is taken from the REAL checker out of the container rather
 * than written down here, which is the whole point of the test: a hand-kept
 * list is exactly what lets a probe added later ship with no runbook entry and
 * pass a green suite — the alert then arrives saying only that something named
 * `disk_backups` is `down`, at the moment the reader is least able to work out
 * what that means. The `?? 'No runbook entry'` fallback in the probe keeps that
 * alert deliverable; this keeps it from being what anyone receives.
 *
 * Integration rather than unit for the same reason: a stubbed checker can only
 * report what a test told it to.
 */
final class HealthRunbookCoverageTest extends KernelTestCase
{
    public function testEveryComponentTheHealthCheckReportsHasARunbookHint(): void
    {
        self::bootKernel();

        /** @var HealthChecker $checker */
        $checker = static::getContainer()->get(HealthChecker::class);

        $components = array_keys($checker->check());
        self::assertNotEmpty($components, 'The health check reports nothing at all — this test would pass vacuously.');

        foreach ($components as $component) {
            $alerts = $this->probeReporting([$component => 'down'])->probe(new DateTimeImmutable('2026-08-12 09:00:00'));

            self::assertCount(1, $alerts, sprintf('The "%s" component produced no alert when down.', $component));
            self::assertStringNotContainsString(
                'No runbook entry',
                $alerts[0]->detail,
                sprintf('The "%s" component has no runbook hint; its alert would name a component and nothing else.', $component),
            );
        }
    }

    /** @param array<string, string> $components */
    private function probeReporting(array $components): HealthComponentProbe
    {
        /** @var HealthChecker&Stub $health */
        $health = $this->createStub(HealthChecker::class);
        $health->method('check')->willReturn($components);

        return new HealthComponentProbe($health);
    }
}
