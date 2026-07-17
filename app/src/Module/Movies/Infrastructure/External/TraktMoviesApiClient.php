<?php

declare(strict_types=1);

namespace App\Module\Movies\Infrastructure\External;

use App\Module\Movies\Domain\Port\MovieRatingsProviderInterface;
use App\Module\Movies\Domain\Port\WatchedMoviesProviderInterface;
use App\Shared\Security\TraktTokenProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the user's watched movies + movie ratings from Trakt.tv (one-directional
 * Trakt → AIHM), the Movies counterpart of the Series TraktApiClient (HMAI-290).
 *
 * A movie is flat (no season/episode tree), so /sync/watched/movies carries the
 * film (title, year, trakt id) + last_watched_at, and /sync/ratings/movies the
 * 1–10 rating. The shared Trakt OAuth token is read through the Shared-kernel
 * {@see TraktTokenProviderInterface} port, so this adapter never reaches into the
 * Series Infrastructure (deptrac stays clean).
 *
 * @phpstan-import-type WatchedMovie from WatchedMoviesProviderInterface
 * @phpstan-import-type MovieRating from MovieRatingsProviderInterface
 */
final readonly class TraktMoviesApiClient implements WatchedMoviesProviderInterface, MovieRatingsProviderInterface
{
    private const string BASE_URL = 'https://api.trakt.tv';
    private const string PROVIDER = 'trakt';
    private const string API_VERSION = '2';

    public function __construct(
        private HttpClientInterface $httpClient,
        private TraktTokenProviderInterface $tokenProvider,
        private string $clientId,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Fetches every movie the user has marked watched on Trakt. Not cached — the
     * import polls this for the current truth.
     *
     * @return list<WatchedMovie>
     */
    public function fetchWatchedMovies(): array
    {
        return $this->parseWatchedMovies($this->get('/sync/watched/movies', ['extended' => 'full']));
    }

    /**
     * Fetches the user's Trakt movie ratings (1–10). Not cached — the import reads
     * current truth.
     *
     * @return list<MovieRating>
     */
    public function fetchMovieRatings(): array
    {
        return $this->parseMovieRatings($this->get('/sync/ratings/movies'));
    }

    /**
     * Authenticated GET against the Trakt sync API, decoded to an array. Guards the
     * client id and stored token, records the call, and maps transport errors onto
     * a readable RuntimeException.
     *
     * @param array<string, string> $query
     *
     * @return array<array-key, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        if ('' === trim($this->clientId)) {
            throw new RuntimeException('Trakt client ID not configured.');
        }

        $token = $this->tokenProvider->get();
        $accessToken = is_array($token) ? trim((string) ($token['access_token'] ?? '')) : '';
        if ('' === $accessToken) {
            throw new RuntimeException('Trakt account not connected.');
        }

        $options = [
            'headers' => [
                'Content-Type' => 'application/json',
                'trakt-api-version' => self::API_VERSION,
                'trakt-api-key' => $this->clientId,
                'Authorization' => 'Bearer '.$accessToken,
            ],
        ];
        if ([] !== $query) {
            $options['query'] = $query;
        }

        $start = microtime(true);

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.$path, $options);
            $status = $response->getStatusCode();
            $data = $response->toArray();
        } catch (TransportExceptionInterface $e) {
            $this->recordCall($path, $start, null, 'transport_error');

            throw new RuntimeException('Trakt API unavailable.', 0, $e);
        }

        $this->recordCall($path, $start, $status);

        return $data;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<WatchedMovie>
     */
    private function parseWatchedMovies(array $data): array
    {
        $movies = [];

        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            $movie = $item['movie'] ?? null;
            if (!is_array($movie)) {
                continue;
            }

            $traktId = $this->movieTraktId($movie);
            if (null === $traktId) {
                continue;
            }

            $lastWatchedAt = $item['last_watched_at'] ?? null;
            $movies[] = [
                'traktId' => $traktId,
                'title' => trim((string) ($movie['title'] ?? '')),
                'year' => isset($movie['year']) && is_numeric($movie['year']) ? (int) $movie['year'] : null,
                'lastWatchedAt' => is_string($lastWatchedAt) ? $lastWatchedAt : null,
            ];
        }

        return $movies;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<MovieRating>
     */
    private function parseMovieRatings(array $data): array
    {
        $result = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rating = $this->validRating($item['rating'] ?? null);
            $movie = is_array($item['movie'] ?? null) ? $item['movie'] : null;
            $traktId = null === $movie ? null : $this->movieTraktId($movie);
            if (null === $rating || null === $traktId) {
                continue;
            }
            $result[] = ['traktId' => $traktId, 'rating' => $rating];
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $movie
     */
    private function movieTraktId(array $movie): ?int
    {
        $ids = $movie['ids'] ?? null;
        $traktId = is_array($ids) ? ($ids['trakt'] ?? null) : null;

        return is_int($traktId) ? $traktId : null;
    }

    private function validRating(mixed $rating): ?int
    {
        return is_int($rating) && $rating >= 1 && $rating <= 10 ? $rating : null;
    }

    private function recordCall(string $endpoint, float $startMicrotime, ?int $statusCode, ?string $error = null): void
    {
        $context = [
            'provider' => self::PROVIDER,
            'endpoint' => $endpoint,
            'duration_ms' => (int) round((microtime(true) - $startMicrotime) * 1000),
        ];

        if (null !== $statusCode) {
            $context['status'] = $statusCode;
        }

        if (null !== $error) {
            $context['error'] = $error;
        }

        $this->logger->info('External API call', $context);
    }
}
