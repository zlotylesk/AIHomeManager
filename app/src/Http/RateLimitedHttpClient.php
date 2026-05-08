<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Decorator that proactively throttles HTTP requests to honor an external API's rate limit.
 *
 * Why: prevents 429s and IP bans from third-party APIs (Discogs 60/min, Last.fm 5/s).
 * Reserves a token via Symfony RateLimiter; if none available, blocks the calling thread
 * until one is — the calling code does not need to know about throttling.
 */
final readonly class RateLimitedHttpClient implements HttpClientInterface
{
    public function __construct(
        private HttpClientInterface $inner,
        private RateLimiterFactory $limiterFactory,
        private string $limiterName,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $reservation = $this->limiterFactory->create()->reserve();
        $waitSeconds = $reservation->getWaitDuration();

        if ($waitSeconds > 0.0) {
            $this->logger->warning('External API throttled — waiting for token', [
                'rate_limit_triggered' => true,
                'limiter' => $this->limiterName,
                'wait_seconds' => $waitSeconds,
                'url' => $url,
            ]);
        }

        $reservation->wait();

        return $this->inner->request($method, $url, $options);
    }

    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options), $this->limiterFactory, $this->limiterName, $this->logger);
    }
}
