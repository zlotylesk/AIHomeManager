<?php

declare(strict_types=1);

namespace App\Module\Music\Infrastructure\External;

use App\Module\Music\Application\DTO\AlbumDTO;
use App\Module\Music\Domain\Port\MusicListeningHistoryInterface;
use JsonException;
use Redis;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class LastFmApiClient implements MusicListeningHistoryInterface
{
    private const string API_URL = 'https://ws.audioscrobbler.com/2.0/';
    private const int CACHE_TTL = 3600;
    private const array PREFERRED_IMAGE_SIZES = ['extralarge', 'large', 'medium', 'small'];

    public function __construct(
        private HttpClientInterface $httpClient,
        private Redis $redis,
        private string $apiKey,
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

            $data = $response->toArray();
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException('Last.fm API unavailable.', 0, $e);
        }

        $albums = $this->parseAlbums($data);

        $this->redis->setex($cacheKey, self::CACHE_TTL, $this->encodeAlbumsForCache($albums));

        return $albums;
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
