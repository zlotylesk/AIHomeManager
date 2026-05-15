<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Music\Infrastructure;

use App\Module\Music\Infrastructure\External\DiscogsClockDriftDetector;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DiscogsClockDriftDetectorTest extends TestCase
{
    private function inspect(?string $dateHeader, ?LoggerInterface $logger = null, ?int $thresholdSeconds = null): void
    {
        // The detector inspects a real ResponseInterface, so build a MockResponse
        // routed through MockHttpClient — keeps test setup parallel to how the
        // production HTTP clients actually surface responses.
        $headers = null === $dateHeader ? [] : ['Date: '.$dateHeader];
        $client = new MockHttpClient(new MockResponse('', ['response_headers' => $headers]));
        $response = $client->request('GET', 'https://example.test/');

        $detector = null === $thresholdSeconds
            ? new DiscogsClockDriftDetector($logger ?? $this->createStub(LoggerInterface::class))
            : new DiscogsClockDriftDetector($logger ?? $this->createStub(LoggerInterface::class), $thresholdSeconds);
        $detector->inspect($response);
    }

    public function testDoesNotLogWhenDriftWithinThreshold(): void
    {
        // 30s drift — well below the 300s threshold. The detector should stay
        // silent: routine NTP jitter and request latency must not produce noise.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $serverTime = new DateTimeImmutable('@'.(time() - 30))->format(DateTimeImmutable::RFC7231);
        $this->inspect($serverTime, $logger);
    }

    public function testLogsWarningWhenDriftExceedsThreshold(): void
    {
        // 10-minute drift past Discogs' clock — the trip-wire the ticket cares
        // about. Asserting on both the message and the structured context so a
        // refactor that drops drift_seconds or threshold_seconds is caught.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('Discogs clock drift detected'), self::callback(
                static fn (array $ctx): bool => $ctx['drift_seconds'] > 300
                    && 300 === $ctx['threshold_seconds']
                    && is_string($ctx['server_date_header'])
                    && '' !== $ctx['server_date_header'],
            ));

        $serverTime = new DateTimeImmutable('@'.(time() - 600))->format(DateTimeImmutable::RFC7231);
        $this->inspect($serverTime, $logger);
    }

    public function testLogsWarningWhenLocalClockIsAheadOfServer(): void
    {
        // Drift is absolute — a host running 10 minutes ahead of Discogs is just
        // as broken as one running 10 minutes behind. Both must trip the warning.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $serverTime = new DateTimeImmutable('@'.(time() + 600))->format(DateTimeImmutable::RFC7231);
        $this->inspect($serverTime, $logger);
    }

    public function testStaysSilentWhenDateHeaderMissing(): void
    {
        // Discogs always sends Date, but proxies and dev fixtures may strip it.
        // Better to skip the check than fabricate a warning from no signal.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $this->inspect(null, $logger);
    }

    public function testStaysSilentWhenDateHeaderUnparseable(): void
    {
        // If we cannot parse the header, we have no comparison to make. The
        // detector swallows the parse error rather than escalating it — a
        // garbled Date is not the operator's problem to fix.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $this->inspect('not-a-date', $logger);
    }

    public function testCustomThresholdIsRespected(): void
    {
        // The threshold is constructor-injected (and bound from services.yaml
        // in prod) so it can be tuned without code edits. A 60s window with a
        // 120s drift must fire, even though 120s is well under the 300s default.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::anything(), self::callback(
                static fn (array $ctx): bool => 60 === $ctx['threshold_seconds'] && $ctx['drift_seconds'] > 60,
            ));

        $serverTime = new DateTimeImmutable('@'.(time() - 120))->format(DateTimeImmutable::RFC7231);
        $this->inspect($serverTime, $logger, 60);
    }
}
