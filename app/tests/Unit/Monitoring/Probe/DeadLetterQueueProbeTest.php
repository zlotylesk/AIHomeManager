<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitoring\Probe;

use App\Monitoring\Alert;
use App\Monitoring\AlertSeverity;
use App\Monitoring\Probe\DeadLetterQueueProbe;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class DeadLetterQueueProbeTest extends TestCase
{
    public function testAnEmptyQueueIsNothingToSay(): void
    {
        self::assertSame([], $this->probeAt(depth: 0, threshold: 1));
    }

    public function testTheDefaultThresholdMeansASingleStuckMessageIsReported(): void
    {
        $alerts = $this->probeAt(depth: 1, threshold: 1);

        self::assertCount(1, $alerts);
        self::assertSame('failed', $alerts[0]->key);
        self::assertStringContainsString('1 message(s)', $alerts[0]->title);
    }

    public function testADepthBelowTheThresholdIsToleratedInSilence(): void
    {
        self::assertSame([], $this->probeAt(depth: 9, threshold: 10));
    }

    public function testTheThresholdItselfIsEnoughToReport(): void
    {
        self::assertCount(1, $this->probeAt(depth: 10, threshold: 10));
    }

    public function testItIsAWarningBecauseTheInstanceKeepsServing(): void
    {
        self::assertSame(AlertSeverity::WARNING, $this->probeAt(depth: 40, threshold: 1)[0]->severity);
    }

    public function testTheAlertPointsAtTheCommandThatActuallyWorksHere(): void
    {
        $detail = $this->probeAt(depth: 3, threshold: 1)[0]->detail;

        self::assertStringContainsString('messenger:failed:retry', $detail);
        self::assertStringContainsString('messenger:failed:show', $detail, 'The one that does not work against AMQP is worth naming, or it is the first thing someone reaches for.');
    }

    public function testATransportThatCannotBeCountedIsAConfigurationShapeNotAFailure(): void
    {
        $transport = $this->createStub(TransportInterface::class);

        $alerts = new DeadLetterQueueProbe($transport, 1)->probe(new DateTimeImmutable());

        self::assertSame([], $alerts, 'The in-memory transport the suite binds cannot be counted; alerting on that would make the suite alert on itself.');
    }

    /** @return list<Alert> */
    private function probeAt(int $depth, int $threshold): array
    {
        /** @var TransportInterface&MessageCountAwareInterface&Stub $transport */
        $transport = $this->createStubForIntersectionOfInterfaces([TransportInterface::class, MessageCountAwareInterface::class]);
        $transport->method('getMessageCount')->willReturn($depth);

        return new DeadLetterQueueProbe($transport, $threshold)->probe(new DateTimeImmutable('2026-08-11 09:00:00'));
    }
}
