<?php

declare(strict_types=1);

namespace App\Module\Music\Infrastructure\External;

use App\Module\Music\Application\DTO\AlbumDTO;
use App\Module\Music\Application\DTO\RecentTrackDTO;
use App\Module\Music\Domain\Port\MusicListeningHistoryInterface;
use DateTimeImmutable;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Redis;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class LastFmApiClient implements MusicListeningHistoryInterface
{
    private const string API_URL = 'https://ws.audioscrobbler.com/2.0/';
    private const string PROVIDER = 'lastfm';
    private const int CACHE_TTL = 3600;
    private const array PREFERRED_IMAGE_SIZES = ['extralarge', 'large', 'medium', 'small'];

    public function __construct(
        private HttpClientInterface $httpClient,
        private Redis $redis,
        private string $apiKey,
        #[Autowire(service: 'monolog.logger.music')]
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** @return AlbumDTO[] */
    public function getTopAlbums(string $username, string $period, int $limit): array
    {
        // trim() also catches whitespace-only keys (e.g. LASTFM_API_KEY=" " from a
        // copy-paste mishap) — typed string already rules out null. HMAI-84.
        if ('' === trim($this->apiKey)) {
            throw new RuntimeException('Last.fm API key not configured');
        }

        $cacheKey = sprintf('lastfm:top:%s:%s:%d', $username, $period, $limit);

        $cached = $this->redis->get($cacheKey);
        if (false !== $cached) {
            try {
                return $this->decodeAlbumsFromCache($cached);
            } catch (JsonException) {
                // Stale or corrupted cache entry — fall through to refetch.
            }
        }

        $start = microtime(true);
        $status = null;

        try {
            $response = $this->httpClient->request('GET', self::API_URL, [
                'query' => [
                    'method' => 'user.gettopalbums',
                    'user' => $username,
                    'period' => $period,
                    'limit' => $limit,
                    'api_key' => $this->apiKey,
                    'format' => 'json',
                ],
            ]);

            $status = $response->getStatusCode();
            $data = $response->toArray();
        } catch (TransportExceptionInterface $e) {
            $this->recordCall('user.gettopalbums', $start, null, 'transport_error');

            throw new RuntimeException('Last.fm API unavailable.', 0, $e);
        }

        $this->recordCall('user.gettopalbums', $start, $status);

        $albums = $this->parseAlbums($data);

        $this->redis->setex($cacheKey, self::CACHE_TTL, $this->encodeAlbumsForCache($albums));

        return $albums;
    }

    /**
     * Fetches the most recent plays straight from Last.fm — NOT cached, since the
     * scheduler polls this to capture new scrobbles the local history hasn't seen
     * yet (HMAI-144). A cache would hide exactly the deltas we are polling for.
     *
     * @return RecentTrackDTO[]
     */
    public function getRecentTracks(string $username, int $limit): array
    {
        if ('' === trim($this->apiKey)) {
            throw new RuntimeException('Last.fm API key not configured');
        }

        $start = microtime(true);
        $status = null;

        try {
            $response = $this->httpClient->request('GET', self::API_URL, [
                'query' => [
                    'method' => 'user.getrecenttracks',
                    'user' => $username,
                    'limit' => $limit,
                    'api_key' => $this->apiKey,
                    'format' => 'json',
                ],
            ]);

            $status = $response->getStatusCode();
            $data = $response->toArray();
        } catch (TransportExceptionInterface $e) {
            $this->recordCall('user.getrecenttracks', $start, null, 'transport_error');

            throw new RuntimeException('Last.fm API unavailable.', 0, $e);
        }

        $this->recordCall('user.getrecenttracks', $start, $status);

        return $this->parseRecentTracks($data);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return RecentTrackDTO[]
     */
    private function parseRecentTracks(array $data): array
    {
        $tracks = [];

        foreach ($data['recenttracks']['track'] ?? [] as $item) {
            // The currently-playing track carries no play timestamp — skip it,
            // it isn't a completed listening session yet.
            if ('true' === ($item['@attr']['nowplaying'] ?? null) || !isset($item['date']['uts'])) {
                continue;
            }

            $artist = trim((string) ($item['artist']['#text'] ?? $item['artist']['name'] ?? ''));
            $album = trim((string) ($item['album']['#text'] ?? ''));
            if ('' === $artist || '' === $album) {
                continue;
            }

            // Last.fm returns play time as a UNIX timestamp in UTC seconds.
            $playedAt = DateTimeImmutable::createFromFormat('U', (string) $item['date']['uts']);
            if (false === $playedAt) {
                continue;
            }

            $mbid = trim((string) ($item['album']['mbid'] ?? $item['mbid'] ?? ''));

            $tracks[] = new RecentTrackDTO(
                artist: $artist,
                album: $album,
                playedAt: $playedAt,
                mbid: '' !== $mbid ? $mbid : null,
            );
        }

        return $tracks;
    }

    /** @param AlbumDTO[] $albums */
    private function encodeAlbumsForCache(array $albums): string
    {
        return json_encode(
            array_map(
                static fn (AlbumDTO $album) => [
                    'artist' => $album->artist,
                    'title' => $album->title,
                    'playCount' => $album->playCount,
                    'imageUrl' => $album->imageUrl,
                ],
                $albums,
            ),
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return AlbumDTO[] */
    private function decodeAlbumsFromCache(string $json): array
    {
        $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return array_map(
            static fn (array $row) => new AlbumDTO(
                artist: (string) ($row['artist'] ?? ''),
                title: (string) ($row['title'] ?? ''),
                playCount: (int) ($row['playCount'] ?? 0),
                imageUrl: isset($row['imageUrl']) ? (string) $row['imageUrl'] : null,
            ),
            $rows,
        );
    }

    /** @return AlbumDTO[] */
    private function parseAlbums(array $data): array
    {
        $albums = [];

        foreach ($data['topalbums']['album'] ?? [] as $item) {
            $albums[] = new AlbumDTO(
                artist: $item['artist']['name'] ?? '',
                title: $item['name'] ?? '',
                playCount: (int) ($item['playcount'] ?? 0),
                imageUrl: $this->extractImageUrl($item['image'] ?? []),
            );
        }

        return $albums;
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

    private function extractImageUrl(array $images): ?string
    {
        $bySize = [];
        foreach ($images as $image) {
            $bySize[$image['size'] ?? ''] = $image['#text'] ?? '';
        }

        foreach (self::PREFERRED_IMAGE_SIZES as $size) {
            if (!empty($bySize[$size])) {
                return $bySize[$size];
            }
        }

        return null;
    }
}
