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
    // RFC 7231 HTTP-date format (literal GMT). Inlined because the
    // DateTimeInterface::RFC7231 constant is deprecated since PHP 8.5.
    private const string HTTP_DATE_FORMAT = 'D, d M Y H:i:s \G\M\T';

    private function inspect(?string $dateHeader, ?LoggerInterface $logger = null, ?int $thresholdSeconds = null): void
    {
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
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $serverTime = new DateTimeImmutable('@'.(time() - 30))->format(self::HTTP_DATE_FORMAT);
        $this->inspect($serverTime, $logger);
    }

    public function testLogsWarningWhenDriftExceedsThreshold(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('Discogs clock drift detected'), self::callback(
                static fn (array $ctx): bool => $ctx['drift_seconds'] > 300
                    && 300 === $ctx['threshold_seconds']
                    && is_string($ctx['server_date_header'])
                    && '' !== $ctx['server_date_header'],
            ));

        $serverTime = new DateTimeImmutable('@'.(time() - 600))->format(self::HTTP_DATE_FORMAT);
        $this->inspect($serverTime, $logger);
    }

    public function testLogsWarningWhenLocalClockIsAheadOfServer(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $serverTime = new DateTimeImmutable('@'.(time() + 600))->format(self::HTTP_DATE_FORMAT);
        $this->inspect($serverTime, $logger);
    }

    public function testStaysSilentWhenDateHeaderMissing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $this->inspect(null, $logger);
    }

    public function testStaysSilentWhenDateHeaderUnparseable(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $this->inspect('not-a-date', $logger);
    }

    public function testCustomThresholdIsRespected(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::anything(), self::callback(
                static fn (array $ctx): bool => 60 === $ctx['threshold_seconds'] && $ctx['drift_seconds'] > 60,
            ));

        $serverTime = new DateTimeImmutable('@'.(time() - 120))->format(self::HTTP_DATE_FORMAT);
        $this->inspect($serverTime, $logger, 60);
    }
}
