<?php

declare(strict_types=1);

namespace App\Module\Series\Infrastructure\External;

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
 * layer (HMAI-183) consumes the structured shape below.
 *
 * @phpstan-type WatchedEpisode array{number: int, lastWatchedAt: string|null}
 * @phpstan-type WatchedSeason array{number: int, episodes: list<WatchedEpisode>}
 * @phpstan-type WatchedShow array{traktId: int, title: string, year: int|null, seasons: list<WatchedSeason>}
 */
final readonly class TraktApiClient
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
        // trim() catches a whitespace-only client id (copy-paste misconfig) the
        // same way the other clients guard their keys.
        if ('' === trim($this->clientId)) {
            throw new RuntimeException('Trakt client ID not configured.');
        }

        $token = $this->tokenRepository->get();
        $accessToken = is_array($token) ? trim((string) ($token['access_token'] ?? '')) : '';
        if ('' === $accessToken) {
            throw new RuntimeException('Trakt account not connected.');
        }

        $start = microtime(true);
        $status = null;

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.'/sync/watched/shows', [
                'query' => ['extended' => 'full'],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'trakt-api-version' => self::API_VERSION,
                    'trakt-api-key' => $this->clientId,
                    'Authorization' => 'Bearer '.$accessToken,
                ],
            ]);

            $status = $response->getStatusCode();
            $data = $response->toArray();
        } catch (TransportExceptionInterface $e) {
            $this->recordCall('/sync/watched/shows', $start, null, 'transport_error');

            throw new RuntimeException('Trakt API unavailable.', 0, $e);
        }

        $this->recordCall('/sync/watched/shows', $start, $status);

        return $this->parseWatchedShows($data);
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
                // A show without a stable trakt id can't be deduplicated on import — skip it.
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
