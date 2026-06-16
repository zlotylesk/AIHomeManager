<?php

declare(strict_types=1);

namespace App\Module\Series\Infrastructure\External;

use App\Module\Series\Domain\Port\RatingsProviderInterface;
use App\Module\Series\Domain\Port\WatchedShowsProviderInterface;
use App\Module\Series\Infrastructure\Persistence\TraktTokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the user's watched shows from Trakt.tv (one-directional Trakt → AIHM).
 *
 * The /sync/watched/shows payload carries the show (title, year, trakt id) and a
 * nested seasons[].episodes[] tree of *watched* episodes — episode numbers and
 * play timestamps, but no episode titles (Trakt omits those here). The import
 * layer (HMAI-183) consumes the structured shape defined on the port.
 *
 * @phpstan-import-type WatchedShow from WatchedShowsProviderInterface
 * @phpstan-import-type WatchedSeason from WatchedShowsProviderInterface
 * @phpstan-import-type WatchedEpisode from WatchedShowsProviderInterface
 * @phpstan-import-type TraktRatings from RatingsProviderInterface
 * @phpstan-import-type ShowRating from RatingsProviderInterface
 * @phpstan-import-type SeasonRating from RatingsProviderInterface
 * @phpstan-import-type EpisodeRating from RatingsProviderInterface
 */
final readonly class TraktApiClient implements WatchedShowsProviderInterface, RatingsProviderInterface
{
    private const string BASE_URL = 'https://api.trakt.tv';
    private const string PROVIDER = 'trakt';
    private const string API_VERSION = '2';

    public function __construct(
        private HttpClientInterface $httpClient,
        private TraktTokenRepositoryInterface $tokenRepository,
        private string $clientId,
        #[Autowire(service: 'monolog.logger.series')]
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Fetches every show the user has marked watched on Trakt, with its watched
     * seasons/episodes. Not cached — the import polls this for the current truth.
     *
     * @return list<WatchedShow>
     */
    public function fetchWatchedShows(): array
    {
        return $this->parseWatchedShows($this->get('/sync/watched/shows', ['extended' => 'full']));
    }

    /**
     * Fetches the user's Trakt ratings (1–10) for shows, seasons and episodes
     * across the three sync endpoints. Not cached — the import reads current truth.
     *
     * @return TraktRatings
     */
    public function fetchRatings(): array
    {
        return [
            'shows' => $this->parseShowRatings($this->get('/sync/ratings/shows')),
            'seasons' => $this->parseSeasonRatings($this->get('/sync/ratings/seasons')),
            'episodes' => $this->parseEpisodeRatings($this->get('/sync/ratings/episodes')),
        ];
    }

    /**
     * Authenticated GET against the Trakt sync API, decoded to an array. Shared by
     * the watched + ratings imports: guards the client id and stored token, records
     * the call, and maps transport errors onto a readable RuntimeException.
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

        $token = $this->tokenRepository->get();
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
     * @return list<WatchedShow>
     */
    private function parseWatchedShows(array $data): array
    {
        $shows = [];

        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            $show = $item['show'] ?? null;
            if (!is_array($show)) {
                continue;
            }

            $ids = $show['ids'] ?? null;
            $traktId = is_array($ids) ? ($ids['trakt'] ?? null) : null;
            if (!is_int($traktId)) {
                continue;
            }

            $shows[] = [
                'traktId' => $traktId,
                'title' => trim((string) ($show['title'] ?? '')),
                'year' => isset($show['year']) && is_numeric($show['year']) ? (int) $show['year'] : null,
                'seasons' => $this->parseSeasons($item['seasons'] ?? null),
            ];
        }

        return $shows;
    }

    /**
     * @return list<WatchedSeason>
     */
    private function parseSeasons(mixed $seasons): array
    {
        if (!is_array($seasons)) {
            return [];
        }

        $result = [];
        foreach ($seasons as $season) {
            if (!is_array($season)) {
                continue;
            }

            $number = $season['number'] ?? null;
            if (!is_int($number)) {
                continue;
            }

            $result[] = [
                'number' => $number,
                'episodes' => $this->parseEpisodes($season['episodes'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * @return list<WatchedEpisode>
     */
    private function parseEpisodes(mixed $episodes): array
    {
        if (!is_array($episodes)) {
            return [];
        }

        $result = [];
        foreach ($episodes as $episode) {
            if (!is_array($episode)) {
                continue;
            }

            $number = $episode['number'] ?? null;
            if (!is_int($number)) {
                continue;
            }

            $lastWatchedAt = $episode['last_watched_at'] ?? null;
            $result[] = [
                'number' => $number,
                'lastWatchedAt' => is_string($lastWatchedAt) ? $lastWatchedAt : null,
            ];
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<ShowRating>
     */
    private function parseShowRatings(array $data): array
    {
        $result = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rating = $this->validRating($item['rating'] ?? null);
            $traktId = $this->showTraktId($item);
            if (null === $rating || null === $traktId) {
                continue;
            }
            $result[] = ['traktId' => $traktId, 'rating' => $rating];
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<SeasonRating>
     */
    private function parseSeasonRatings(array $data): array
    {
        $result = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rating = $this->validRating($item['rating'] ?? null);
            $traktId = $this->showTraktId($item);
            $season = is_array($item['season'] ?? null) ? $item['season'] : null;
            $seasonNumber = is_array($season) && is_int($season['number'] ?? null) ? $season['number'] : null;
            if (null === $rating || null === $traktId || null === $seasonNumber) {
                continue;
            }
            $result[] = ['traktId' => $traktId, 'seasonNumber' => $seasonNumber, 'rating' => $rating];
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<EpisodeRating>
     */
    private function parseEpisodeRatings(array $data): array
    {
        $result = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rating = $this->validRating($item['rating'] ?? null);
            $traktId = $this->showTraktId($item);
            $episode = is_array($item['episode'] ?? null) ? $item['episode'] : null;
            $seasonNumber = is_array($episode) && is_int($episode['season'] ?? null) ? $episode['season'] : null;
            $episodeNumber = is_array($episode) && is_int($episode['number'] ?? null) ? $episode['number'] : null;
            if (null === $rating || null === $traktId || null === $seasonNumber || null === $episodeNumber) {
                continue;
            }
            $result[] = ['traktId' => $traktId, 'seasonNumber' => $seasonNumber, 'episodeNumber' => $episodeNumber, 'rating' => $rating];
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $item
     */
    private function showTraktId(array $item): ?int
    {
        $show = $item['show'] ?? null;
        if (!is_array($show)) {
            return null;
        }
        $ids = $show['ids'] ?? null;
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
