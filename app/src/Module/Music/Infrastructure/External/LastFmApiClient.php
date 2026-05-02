<?php

declare(strict_types=1);

namespace App\Module\Music\Infrastructure\External;

use App\Module\Music\Application\DTO\AlbumDTO;
use App\Module\Music\Domain\Port\MusicListeningHistoryInterface;
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
        if ('' === $this->apiKey) {
            throw new RuntimeException('Last.fm API key not configured');
        }

        $cacheKey = sprintf('lastfm:top:%s:%s:%d', $username, $period, $limit);

        $cached = $this->redis->get($cacheKey);
        if (false !== $cached) {
            return unserialize($cached);
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

        $this->redis->setex($cacheKey, self::CACHE_TTL, serialize($albums));

        return $albums;
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
