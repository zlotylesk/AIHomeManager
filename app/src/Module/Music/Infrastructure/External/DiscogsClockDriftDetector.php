<?php

declare(strict_types=1);

namespace App\Module\Music\Infrastructure\External;

use DateTimeImmutable;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Post-response check for OAuth1 timestamp drift against Discogs' server clock.
 *
 * Discogs' OAuth1 replay protection rejects requests whose `oauth_timestamp`
 * deviates too far from the server's wall clock. If our host's clock drifts
 * unnoticed, every signed request silently starts failing. The detector reads
 * the `Date` response header (RFC 7231) after each call, compares it to
 * `time()`, and logs a warning when the gap exceeds the threshold so the
 * operator sees the issue before it cascades into auth failures.
 */
final readonly class DiscogsClockDriftDetector
{
    public const int DEFAULT_THRESHOLD_SECONDS = 300;

    public function __construct(
        private LoggerInterface $logger,
        private int $thresholdSeconds = self::DEFAULT_THRESHOLD_SECONDS,
    ) {
    }

    public function inspect(ResponseInterface $response): void
    {
        try {
            $headers = $response->getHeaders(false);
        } catch (TransportExceptionInterface) {
            // No usable response — skip the check rather than mask a real
            // transport failure with a noisy drift warning.
            return;
        }

        $dateHeader = $headers['date'][0] ?? null;
        if (!is_string($dateHeader) || '' === $dateHeader) {
            return;
        }

        try {
            $serverTime = new DateTimeImmutable($dateHeader);
        } catch (Exception) {
            return;
        }

        $driftSeconds = abs(time() - $serverTime->getTimestamp());
        if ($driftSeconds <= $this->thresholdSeconds) {
            return;
        }

        $this->logger->warning('Discogs clock drift detected', [
            'drift_seconds' => $driftSeconds,
            'threshold_seconds' => $this->thresholdSeconds,
            'server_date_header' => $dateHeader,
        ]);
    }
}
